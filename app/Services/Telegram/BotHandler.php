<?php

namespace App\Services\Telegram;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\TelegramChat;
use App\Services\CertificateService;
use App\Services\PaperService;
use App\Support\Locale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Handles one Telegram update. Users can submit a paper step by step, track their
 * submissions, find published papers (with link + certificate) and switch language.
 * Chats listed in TELEGRAM_ADMIN_CHAT_IDS can also review, accept and reject papers.
 */
class BotHandler
{
    /** Submission steps in order; "optional" steps show a Skip button. */
    private const STEPS = [
        'title' => ['prompt' => 'Send the title of your paper.', 'max' => 255, 'min' => 5],
        'authors' => ['prompt' => 'List all authors separated by commas (e.g. Aliyev Vali, Karimova Nodira).', 'max' => 255, 'min' => 3],
        'affiliation' => ['prompt' => 'Your organisation or university.', 'max' => 255, 'optional' => true],
        'email' => ['prompt' => 'Your e-mail address, so we can also send the certificate by e-mail.', 'max' => 255, 'optional' => true],
        'abstract' => ['prompt' => 'Send the abstract of the paper.', 'max' => 5000, 'optional' => true],
        'keywords' => ['prompt' => 'Keywords, separated by commas.', 'max' => 255, 'optional' => true],
        'file' => ['prompt' => 'Finally, send the manuscript as a PDF, DOC or DOCX file (up to :size MB). By sending it you confirm that you are an author of this paper; every submission is checked for authorship and plagiarism.'],
    ];

    private const MENU = [
        'submit' => '📝 Submit a paper',
        'mine' => '📂 My papers',
        'search' => '🔎 Find a paper',
        'about' => 'ℹ️ About the journal',
        'language' => '🌐 Language',
    ];

    public function __construct(
        private TelegramApi $tg,
        private PaperService $papers,
        private CertificateService $certificates,
    ) {
    }

    public function handle(array $update): void
    {
        try {
            if (isset($update['callback_query'])) {
                $this->onCallback($update['callback_query']);
            } elseif (isset($update['message']['migrate_to_chat_id'])) {
                // The editors' group became a supergroup and got a new id: follow it.
                $new = $update['message']['migrate_to_chat_id'];
                \App\Support\RuntimeSettings::replaceChat((string) $update['message']['chat']['id'], (string) $new);
                $this->chat(['id' => $new, 'type' => 'supergroup'] + $update['message']['chat'], []);
            } elseif (isset($update['message'])) {
                $this->onMessage($update['message']);
            } elseif ($chat = $update['channel_post']['chat'] ?? $update['my_chat_member']['chat'] ?? null) {
                // Bot added to a group/channel, or a post in a channel: remember the chat so
                // Panel → System can offer it as the storage channel or an editor chat.
                $this->chat($chat, []);
                $this->adoptStorageChannel($chat, $update['my_chat_member']['new_chat_member'] ?? null);
            }
        } catch (Throwable $e) {
            Log::error('Telegram update failed', ['error' => $e->getMessage(), 'update' => $update['update_id'] ?? null]);
        }
    }

    /**
     * A private channel where the bot may post becomes the file storage automatically,
     * as long as no channel is configured yet (empty, or a person's chat used as a stopgap).
     */
    private function adoptStorageChannel(array $chat, ?array $member): void
    {
        if (($chat['type'] ?? '') !== 'channel' || ! empty($chat['username'])) {
            return; // only private channels: a public one would show manuscripts to everyone
        }
        if ($member !== null && (($member['status'] ?? '') !== 'administrator' || ($member['can_post_messages'] ?? false) === false)) {
            return; // the bot was added without the right to post (or removed)
        }
        $current = (string) config('services.telegram.storage_chat_id');
        if ($current !== '' && str_starts_with($current, '-') && $current !== (string) $chat['id']) {
            return; // another channel or group is already the storage: never switch silently
        }
        if ($current === (string) $chat['id']) {
            return;
        }

        \App\Support\RuntimeSettings::saveTelegram(['storage_chat_id' => (string) $chat['id']]);
        foreach (config('services.telegram.admin_chat_ids', []) as $adminId) {
            try {
                $this->tg->sendMessage($adminId, '📦 '.e(__('Channel ":name" (:id) is now the file storage. New manuscripts are kept there.', [
                    'name' => $chat['title'] ?? '', 'id' => $chat['id'],
                ])));
            } catch (Throwable $e) {
                // an unreachable editor chat must not stop the others
            }
        }
    }

    // ------------------------------------------------------------------ messages

    private function onMessage(array $message): void
    {
        $isPrivate = ($message['chat']['type'] ?? '') === 'private';
        $chat = $this->chat($message['chat'], $message['from'] ?? []);
        $text = trim($message['text'] ?? '');

        if (! $isPrivate) {
            // Groups are only used by editors; ignore everything except their reject comments and /pending.
            if ($this->isAdmin($chat->chat_id, $message['from']['id'] ?? null)) {
                $this->onAdminMessage($chat, $text);
            }

            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->onStart($chat, trim(substr($text, 6)));

            return;
        }

        // An editor forwards a post from a channel: remember the channel so Panel → System offers it as storage.
        $origin = $message['forward_origin'] ?? null;
        $forwarded = $origin['chat'] ?? $origin['sender_chat'] ?? $message['forward_from_chat'] ?? null;
        if (($origin || $forwarded) && ! str_starts_with((string) $chat->state, 'submit.') && $this->isAdmin($chat->chat_id, $message['from']['id'] ?? null)) {
            if ($forwarded) {
                $this->chat($forwarded, []);
                try {
                    $botId = strtok((string) config('services.telegram.token'), ':');
                    $this->adoptStorageChannel($forwarded, $this->tg->call('getChatMember', ['chat_id' => $forwarded['id'], 'user_id' => $botId]));
                } catch (Throwable $e) {
                    // the bot is not in that channel: the reply below still names it
                }
                $this->tg->sendMessage($chat->chat_id, $this->t($chat, 'Channel ":name" (:id) is saved. Open Panel → System, reload the page and press "Storage channel" next to it. The bot must be an admin of the channel.', [
                    'name' => e($forwarded['title'] ?? ''), 'id' => $forwarded['id'],
                ]));
            } else {
                // Forwarded from a person: say so, otherwise it looks as if the bot ignored the channel.
                $who = $origin['sender_user']['first_name'] ?? $origin['sender_user_name'] ?? '?';
                $this->tg->sendMessage($chat->chat_id, $this->t($chat, 'This message was forwarded from a person (:name), not from a channel. Forward a post from the channel itself, or paste the channel post link (Copy Post Link) in Panel → System → Bot settings.', [
                    'name' => e($who),
                ]));
            }

            return;
        }

        if ($this->isAdmin($chat->chat_id, $message['from']['id'] ?? null) && $this->onAdminMessage($chat, $text)) {
            return;
        }

        if ($text === '/cancel' || $this->is($text, '❌ Cancel')) {
            $chat->reset();
            $this->menu($chat, $this->t($chat, 'Cancelled.'));

            return;
        }

        if ($text === '/lang' || $this->is($text, self::MENU['language'])) {
            $this->askLanguage($chat);

            return;
        }

        if ($text === '/help') {
            $this->menu($chat, $this->welcome($chat));

            return;
        }

        foreach (self::MENU as $action => $label) {
            if ($this->is($text, $label)) {
                $chat->reset();
                $this->{'action'.ucfirst($action)}($chat);

                return;
            }
        }

        if ($chat->state === 'search') {
            $this->runSearch($chat, $text);

            return;
        }

        if ($chat->state && str_starts_with($chat->state, 'submit.')) {
            $this->continueSubmission($chat, $message);

            return;
        }

        $this->menu($chat, $this->t($chat, 'Please choose an option from the menu below.'));
    }

    private function onStart(TelegramChat $chat, string $payload): void
    {
        $chat->reset();

        if (str_starts_with($payload, 'p_')) {
            $this->linkWebSubmission($chat, substr($payload, 2));

            return;
        }

        if (! $chat->locale) {
            $this->askLanguage($chat);

            return;
        }

        $this->menu($chat, $this->welcome($chat));
    }

    /** A web author opened t.me/bot?start=p_CODE: link the chat and deliver the certificate. */
    private function linkWebSubmission(TelegramChat $chat, string $code): void
    {
        $paper = Paper::where('code', $code)->first();

        if (! $paper || ($paper->telegram_chat_id && (string) $paper->telegram_chat_id !== (string) $chat->chat_id)) {
            $this->menu($chat, $this->t($chat, 'Submission not found.'));

            return;
        }

        $paper->update(['telegram_chat_id' => $chat->chat_id, 'telegram_username' => $chat->username]);
        if (! $chat->locale) {
            $chat->update(['locale' => $paper->locale]);
        }

        $this->menu($chat, '🔗 '.$this->t($chat, 'Your submission is now linked to this chat. You will receive status updates here.')
            ."\n\n".$this->paperLine($chat, $paper));
        $this->sendPaperCertificates($chat, $paper);
    }

    // ------------------------------------------------------------------ menu actions

    private function actionSubmit(TelegramChat $chat): void
    {
        $chat->state = 'submit.title';
        $chat->data = [];
        $chat->save();

        $this->tg->sendMessage($chat->chat_id, '📝 <b>'.$this->t($chat, 'New submission')."</b>\n"
            .$this->t($chat, 'Answer a few questions. You can cancel at any time.'));
        $this->prompt($chat, 'title');
    }

    private function actionMine(TelegramChat $chat): void
    {
        $papers = Paper::where('telegram_chat_id', $chat->chat_id)->latest()->limit(10)->get();

        if ($papers->isEmpty()) {
            $this->menu($chat, $this->t($chat, 'You have not submitted any papers yet.'));

            return;
        }

        $lines = $papers->map(fn (Paper $p) => $this->paperLine($chat, $p))->implode("\n\n");
        $buttons = $papers->map(fn (Paper $p) => [[
            'text' => '📜 '.$this->t($chat, 'Certificate').' · '.$p->code,
            'callback_data' => 'cert:'.$p->id,
        ]])->values()->all();

        $this->tg->sendMessage($chat->chat_id, '📂 <b>'.$this->t($chat, 'My papers')."</b>\n\n".$lines, ['inline_keyboard' => $buttons]);
    }

    private function actionSearch(TelegramChat $chat): void
    {
        $chat->update(['state' => 'search']);
        $this->tg->sendMessage(
            $chat->chat_id,
            '🔎 '.$this->t($chat, 'Send a title, author name or keyword. Published papers are returned with a permanent link and a certificate.'),
            $this->keyboard($chat, [['❌ Cancel']])
        );
    }

    private function actionAbout(TelegramChat $chat): void
    {
        $locale = $chat->locale ?? 'uz';
        $text = '📘 <b>'.e(config('journal.name'))."</b>\n\n"
            .$this->t($chat, 'An open-access scientific journal. Every submission gets a certificate with a QR code that anyone can verify.')."\n\n"
            .'🌐 '.\App\Services\VisitTracker::tag(Locale::route('maqolabosh', [], $locale))."\n"
            .'📋 '.$this->t($chat, 'Author guidelines').': '.\App\Services\VisitTracker::tag(Locale::route('yoriqnoma', [], $locale))."\n"
            .'📢 '.config('journal.telegram_channel');

        $this->menu($chat, $text);
    }

    private function actionLanguage(TelegramChat $chat): void
    {
        $this->askLanguage($chat);
    }

    private function runSearch(TelegramChat $chat, string $term): void
    {
        if (mb_strlen($term) < 3) {
            $this->tg->sendMessage($chat->chat_id, $this->t($chat, 'Please send at least 3 characters.'));

            return;
        }

        $articles = Article::search($term)->with('issue')->latest('id')->limit(5)->get();
        if ($articles->isEmpty()) {
            $this->tg->sendMessage($chat->chat_id, $this->t($chat, 'Nothing found. Try another query.'));

            return;
        }

        $locale = $chat->locale ?? 'uz';
        foreach ($articles as $article) {
            $text = '📄 <b>'.e($article->title)."</b>\n👥 ".e($article->author)
                .($article->issue ? "\n📚 ".e($article->issue->title) : '')
                ."\n🔗 ".$this->papers->articleUrl($article, $locale);
            $this->tg->sendMessage($chat->chat_id, $text, ['inline_keyboard' => [[
                ['text' => '📜 '.$this->t($chat, 'Get certificate'), 'callback_data' => 'acert:'.$article->id],
                ['text' => '🌐 '.$this->t($chat, 'Open'), 'url' => \App\Services\VisitTracker::tag($this->papers->articleUrl($article, $locale))],
            ]]]);
        }
    }

    // ------------------------------------------------------------------ submission flow

    private function continueSubmission(TelegramChat $chat, array $message): void
    {
        $step = substr($chat->state, strlen('submit.'));
        $text = trim($message['text'] ?? '');

        if ($step === 'file') {
            $this->receiveFile($chat, $message);

            return;
        }

        $rules = self::STEPS[$step];
        if (($rules['optional'] ?? false) && $this->is($text, '⏭ Skip')) {
            $text = null;
        } elseif ($text === '') {
            $this->prompt($chat, $step);

            return;
        } elseif (mb_strlen($text) < ($rules['min'] ?? 1) || mb_strlen($text) > $rules['max']) {
            $this->tg->sendMessage($chat->chat_id, '⚠️ '.$this->t($chat, 'Please send between :min and :max characters.', [
                'min' => $rules['min'] ?? 1, 'max' => $rules['max'],
            ]));

            return;
        } elseif ($step === 'email' && ! filter_var($text, FILTER_VALIDATE_EMAIL)) {
            $this->tg->sendMessage($chat->chat_id, '⚠️ '.$this->t($chat, 'That does not look like an e-mail address.'));

            return;
        }

        $chat->remember([$step => $text]);
        $steps = array_keys(self::STEPS);
        $next = $steps[array_search($step, $steps, true) + 1];
        $chat->state = 'submit.'.$next;
        $chat->save();

        $this->prompt($chat, $next);
    }

    private function receiveFile(TelegramChat $chat, array $message): void
    {
        $document = $message['document'] ?? null;
        if (! $document) {
            $this->prompt($chat, 'file');

            return;
        }

        $extension = strtolower(pathinfo($document['file_name'] ?? '', PATHINFO_EXTENSION));
        if (! in_array($extension, config('journal.paper_extensions'), true)) {
            $this->tg->sendMessage($chat->chat_id, '⚠️ '.$this->t($chat, 'Only PDF, DOC and DOCX files are accepted.'));

            return;
        }
        if (($document['file_size'] ?? 0) > config('journal.max_upload_kb') * 1024) {
            $this->tg->sendMessage($chat->chat_id, '⚠️ '.$this->t($chat, 'The file is too large. Maximum size is :size MB.', ['size' => $this->maxMb()]));

            return;
        }

        $data = $chat->data ?? [];
        $chat->reset();
        $this->tg->sendMessage($chat->chat_id, '⏳ '.$this->t($chat, 'Saving your paper…'));

        $paper = $this->papers->submit([
            'title' => $data['title'] ?? 'Untitled',
            'authors' => $data['authors'] ?? ($chat->first_name ?: 'Unknown'),
            'affiliation' => $data['affiliation'] ?? null,
            'email' => $data['email'] ?? null,
            'abstract' => $data['abstract'] ?? null,
            'keywords' => $data['keywords'] ?? null,
            'locale' => $chat->locale ?? 'uz',
            'source' => 'telegram',
            'telegram_chat_id' => $chat->chat_id,
            'telegram_username' => $chat->username,
            // The file step states that sending the file means agreeing to CC BY 4.0 and Zenodo.
            'license_agreed_at' => now(),
        ], null, [
            'file_id' => $document['file_id'],
            'file_name' => $document['file_name'] ?? 'paper.'.$extension,
            'file_size' => $document['file_size'] ?? null,
        ]);

        // The certificate itself is delivered by PaperService::notifyAuthor().
        $this->menu($chat, $this->t($chat, 'Done! Track your paper any time with “My papers”.')."\n".route('papers.show', $paper));
    }

    private function prompt(TelegramChat $chat, string $step): void
    {
        $steps = array_keys(self::STEPS);
        $rules = self::STEPS[$step];
        $buttons = ($rules['optional'] ?? false) ? [['⏭ Skip', '❌ Cancel']] : [['❌ Cancel']];

        $this->tg->sendMessage(
            $chat->chat_id,
            '<b>'.$this->t($chat, 'Step :n of :total', ['n' => array_search($step, $steps, true) + 1, 'total' => count($steps)]).'</b>'
                ."\n".$this->t($chat, $rules['prompt'], ['size' => $this->maxMb()])
                .($step === 'file' ? "\n\n".$this->t($chat, 'By sending the file you also agree that, if accepted, the paper is published in open access under the CC BY 4.0 license and deposited in Zenodo, which gives it a permanent DOI.') : ''),
            $this->keyboard($chat, $buttons)
        );
    }

    // ------------------------------------------------------------------ callbacks

    private function onCallback(array $query): void
    {
        $data = $query['data'] ?? '';
        $message = $query['message'] ?? [];
        $chat = $this->chat($message['chat'] ?? ['id' => $query['from']['id']], $query['from']);
        $this->tg->answerCallback($query['id']);

        [$action, $id, $extra] = array_pad(explode(':', $data, 4), 3, null);

        if ($action === 'lang' && in_array($id, Locale::all(), true)) {
            $chat->update(['locale' => $id]);
            $this->menu($chat, $this->welcome($chat));

            return;
        }

        if ($action === 'cert') {
            $paper = Paper::where('id', $id)->where('telegram_chat_id', $chat->chat_id)->first();
            $paper ? $this->sendPaperCertificates($chat, $paper) : null;

            return;
        }

        if ($action === 'acert' && ($article = Article::find($id))) {
            // Articles that came through the bot or site get their certificate only after payment.
            if ($article->paper && ! $article->paper->certificateAllowed()) {
                $this->tg->sendMessage($chat->chat_id, '⏳ '.$this->t($chat, 'Your publication certificate will be sent as soon as the payment is confirmed.'));

                return;
            }
            $certificate = $this->certificates->forArticle($article, $chat->locale ?? 'uz', $article->paper);
            $this->papers->sendCertificateToChat($certificate, $chat->chat_id,
                '📜 '.e(Str::limit($article->title, 200))."\n🔗 ".$this->papers->articleUrl($article, $chat->locale ?? 'uz'));

            return;
        }

        if ($action === 'adm' && $this->isAdmin($chat->chat_id, $query['from']['id'] ?? null)) {
            $this->onAdminCallback($chat, $id, (int) $extra, (int) (explode(':', $data)[3] ?? 0), ($message['chat']['type'] ?? '') !== 'channel');
        }
    }

    // ------------------------------------------------------------------ editors

    private function onAdminCallback(TelegramChat $chat, string $command, int $paperId, int $issueId, bool $canReply = true): void
    {
        $paper = Paper::find($paperId);
        $locale = config('journal.admin_locale');
        if (! $paper) {
            return;
        }

        if (! $paper->isOpen() && $command !== 'review') {
            $this->tg->sendMessage($chat->chat_id, 'ℹ️ '.$paper->code.': '.__('status.'.$paper->status, [], $locale));

            return;
        }

        switch ($command) {
            case 'review':
                $this->papers->markUnderReview($paper);
                $this->tg->sendMessage($chat->chat_id, '🔍 '.$paper->code.': '.__('status.'.$paper->fresh()->status, [], $locale));
                break;

            case 'accept':
                $issues = Maqola::latest('id')->limit(6)->get();
                if ($issues->isEmpty()) {
                    $this->tg->sendMessage($chat->chat_id, '⚠️ '.__('Create an issue first in the admin panel.', [], $locale));
                    break;
                }
                $this->tg->sendMessage($chat->chat_id, '📚 '.__('Choose the issue to publish :code in:', ['code' => $paper->code], $locale), [
                    'inline_keyboard' => $issues->map(fn ($i) => [[
                        'text' => Str::limit($i->title, 60), 'callback_data' => "adm:pub:{$paper->id}:{$i->id}",
                    ]])->values()->all(),
                ]);
                break;

            case 'pub':
                $issue = Maqola::find($issueId);
                if ($issue) {
                    $article = $this->papers->accept($paper, $issue);
                    $this->tg->sendMessage($chat->chat_id, '✅ '.$paper->code.' → '.e($issue->title)."\n".$this->papers->articleUrl($article, 'uz')
                        .($article->hasFile() ? '' : "\n\n⚠️ ".__('The submission is a Word file. Upload the final PDF here:', [], $locale)."\n".route('admin.articles.edit', $article)));
                }
                break;

            case 'reject':
                if (! $canReply) {
                    // Channel posts are not delivered as messages, so a comment cannot be collected there.
                    $this->papers->reject($paper);
                    $this->tg->sendMessage($chat->chat_id, '❌ '.$paper->code.': '.__('status.rejected', [], $locale));
                    break;
                }
                $chat->update(['state' => 'admin.reject', 'data' => ['paper_id' => $paper->id]]);
                $this->tg->sendMessage($chat->chat_id, '✍️ '.__('Send a comment for the author of :code, or /skip.', ['code' => $paper->code], $locale));
                break;
        }
    }

    /** @return bool whether the message was consumed */
    private function onAdminMessage(TelegramChat $chat, string $text): bool
    {
        $locale = config('journal.admin_locale');

        if ($chat->state === 'admin.reject' && $text !== '') {
            $paper = Paper::find($chat->data['paper_id'] ?? 0);
            $chat->reset();
            if ($paper && $paper->isOpen()) {
                $this->papers->reject($paper, $text === '/skip' ? null : $text);
                $this->tg->sendMessage($chat->chat_id, '❌ '.$paper->code.': '.__('status.rejected', [], $locale));
            }

            return true;
        }

        if (str_starts_with($text, '/pending')) {
            $papers = Paper::whereIn('status', [Paper::SUBMITTED, Paper::UNDER_REVIEW])->oldest()->limit(10)->get();
            if ($papers->isEmpty()) {
                $this->tg->sendMessage($chat->chat_id, '✨ '.__('No papers are waiting for a decision.', [], $locale));
            }
            foreach ($papers as $paper) {
                $this->tg->sendMessage($chat->chat_id, $this->papers->storageCaption($paper)."\n🔗 ".route('admin.papers.show', $paper), $this->papers->adminKeyboard($paper));
            }

            return true;
        }

        return false;
    }

    // ------------------------------------------------------------------ helpers

    private function sendPaperCertificates(TelegramChat $chat, Paper $paper): void
    {
        if (Paper::certificateNeedsPayment()) {
            // One certificate only: the publication certificate, after the confirmed payment.
            if ($paper->certificateAllowed()) {
                $publication = $this->certificates->forArticle($paper->article, $paper->locale, $paper);
                $this->papers->sendCertificateToChat($publication, $chat->chat_id, '🏅 '.$this->t($chat, 'Publication certificate')
                    ."\n🔗 ".$this->papers->articleUrl($paper->article, $chat->locale ?? 'uz'));
            } else {
                $this->tg->sendMessage($chat->chat_id, '⏳ '.$this->t($chat, ! $paper->paymentRequired()
                    ? 'The publication certificate is sent when the paper is published.'
                    : ($paper->article
                        ? 'Your publication certificate will be sent as soon as the payment is confirmed.'
                        : 'The certificate is issued after the paper is accepted and the payment is confirmed.')));
            }

            return;
        }

        $certificate = $this->certificates->forSubmission($paper);
        $this->papers->sendCertificateToChat($certificate, $chat->chat_id, '📜 '.$this->t($chat, 'Submission certificate').' · '.$paper->code);

        if ($paper->article) {
            $publication = $this->certificates->forArticle($paper->article, $paper->locale, $paper);
            $this->papers->sendCertificateToChat($publication, $chat->chat_id, '🏅 '.$this->t($chat, 'Publication certificate')
                ."\n🔗 ".$this->papers->articleUrl($paper->article, $chat->locale ?? 'uz'));
        }
    }

    private function paperLine(TelegramChat $chat, Paper $paper): string
    {
        $line = '📄 <b>'.e(Str::limit($paper->title, 120)).'</b>'
            ."\n🏷 <code>".$paper->code.'</code> · '.$this->t($chat, 'status.'.$paper->status);

        if ($paper->article) {
            $line .= "\n🔗 ".$this->papers->articleUrl($paper->article, $chat->locale ?? 'uz');
        }

        return $line;
    }

    private function welcome(TelegramChat $chat): string
    {
        $channel = PaperService::channelHandle();

        return '👋 '.$this->t($chat, 'Welcome to :journal!', ['journal' => config('journal.name')])."\n\n"
            .$this->t($chat, 'Here you can submit a paper, track its review and receive certificates with a QR code. You can also find already published papers and get their permanent link.')
            .($channel ? "\n\n📢 ".$this->t($chat, 'New issues, submission deadlines and announcements — first in our channel.').' '.$channel : '');
    }

    private function askLanguage(TelegramChat $chat): void
    {
        $this->tg->sendMessage($chat->chat_id, '🌐 Tilni tanlang · Choose a language · Выберите язык', ['inline_keyboard' => [
            collect(Locale::LABELS)->map(fn ($label, $code) => ['text' => $label, 'callback_data' => 'lang:'.$code])->values()->all(),
        ]]);
    }

    private function menu(TelegramChat $chat, string $text): void
    {
        $m = self::MENU;
        $this->tg->sendMessage($chat->chat_id, $text, $this->keyboard($chat, [
            [$m['submit'], $m['mine']],
            [$m['search'], $m['about']],
            [$m['language']],
        ]));
    }

    private function keyboard(TelegramChat $chat, array $rows): array
    {
        return [
            'keyboard' => array_map(fn ($row) => array_map(fn ($label) => ['text' => $this->t($chat, $label)], $row), $rows),
            'resize_keyboard' => true,
        ];
    }

    /** Does the text match a button label in any language (the user may have just switched)? */
    private function is(string $text, string $label): bool
    {
        foreach (Locale::all() as $locale) {
            if ($text === __($label, [], $locale)) {
                return true;
            }
        }

        return false;
    }

    private function t(TelegramChat $chat, string $key, array $replace = []): string
    {
        return __($key, $replace, $chat->locale ?? 'uz');
    }

    private function chat(array $chat, array $from): TelegramChat
    {
        // As a string: MySQL binds PHP floats as (32-bit) integers, which corrupts big Telegram ids.
        $record = TelegramChat::firstOrNew(['chat_id' => (string) $chat['id']]);
        $record->username = $from['username'] ?? $chat['username'] ?? $record->username;
        // Groups and channels are named by their title, private chats by the person.
        $record->first_name = ($chat['title'] ?? null) ?: (trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: $record->first_name);
        if (! $record->exists && ($chat['type'] ?? 'private') !== 'private') {
            $record->locale = config('journal.admin_locale');
        }
        $record->save();

        return $record;
    }

    private function isAdmin($chatId, $userId): bool
    {
        $admins = array_map('strval', config('services.telegram.admin_chat_ids', []));

        return in_array((string) $chatId, $admins, true) || ($userId && in_array((string) $userId, $admins, true));
    }

    private function maxMb(): int
    {
        return (int) floor(config('journal.max_upload_kb') / 1024);
    }
}
