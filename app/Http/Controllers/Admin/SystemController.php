<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FileVault;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Maintenance tasks as buttons, for hosting without a terminal:
 * file report, database updates, Telegram webhook, copying PDFs to Telegram, cache.
 */
class SystemController extends Controller
{
    public function index(FileVault $vault, TelegramApi $telegram)
    {
        $bot = $webhook = $telegramError = $storageProblem = null;
        $chats = [];
        if ($telegram->enabled()) {
            try {
                $bot = $telegram->call('getMe');
                $webhook = $telegram->call('getWebhookInfo');
                // A saved id is not enough: the bot must actually be in the storage chat.
                if ($vault->usesTelegram()) {
                    $storageProblem = $this->chatProblem($telegram, (string) config('services.telegram.storage_chat_id'));
                }
                // Before the webhook is connected, recent updates reveal the chat ids needed in .env.
                if (empty($webhook['url'])) {
                    $chats = $this->recentChats($telegram);
                }
                // With the webhook connected, Telegram delivers updates to the site, which remembers each chat.
                $known = \App\Models\TelegramChat::latest('updated_at')->limit(25)->get()->map(fn ($c) => [
                    'id' => (string) $c->chat_id,
                    // Channels and supergroups share the -100 prefix; both can serve as storage.
                    'type' => $c->chat_id > 0 ? 'private' : (str_starts_with((string) $c->chat_id, '-100') ? 'channel / supergroup' : 'group'),
                    'name' => trim(($c->first_name ?: '').($c->username ? ' @'.$c->username : '')),
                ])->all();
                $chats = collect($chats)->concat($known)->unique('id')->values()->all();
            } catch (Throwable $e) {
                $telegramError = $e->getMessage();
            }
        }

        $files = $this->safe(fn () => $vault->report(), []);

        return view('admin.system', [
            'files' => $files,
            'missing' => collect($files)->where('missing', true)->count(),
            'pending' => \App\Support\SchemaState::pendingMigrations(),
            'bot' => $bot,
            'webhook' => $webhook,
            'telegramError' => $telegramError,
            'storageReady' => $vault->usesTelegram() && ! $storageProblem,
            'storageProblem' => $storageProblem,
            'journalProfile' => \App\Support\JournalProfile::get(),
            'zenodoBacklog' => $this->safe(fn () => app(\App\Services\ZenodoBacklog::class)->ready() ? app(\App\Services\ZenodoBacklog::class)->stats() : null, null),
            'abstracts' => $this->safe(fn () => app(\App\Services\AbstractFiller::class)->ready() ? app(\App\Services\AbstractFiller::class)->stats() : null, null),
            'channelInvite' => $this->safe(fn () => app(\App\Services\ChannelInviter::class)->ready() ? app(\App\Services\ChannelInviter::class)->stats() : null, null),
            'zenodo' => $this->safe(fn () => \App\Services\ZenodoService::settings(), ['token' => null, 'auto' => true, 'owner' => null]),
            'disk' => $this->safe(fn () => app(\App\Services\SpaceSaver::class)->usage() + ['folders' => app(\App\Services\SpaceSaver::class)->folderSizes()], null),
            'expectedWebhook' => request()->root().'/telegram/webhook/…',
            'php' => PHP_VERSION,
            'chats' => $chats,
            'telegramSettings' => $this->safe(fn () => \App\Support\RuntimeSettings::telegram(), ['token' => null, 'username' => null, 'storage_chat_id' => null, 'admin_chat_ids' => [], 'webhook_secret' => null]),
            'index' => $this->safe(fn () => app(\App\Services\Originality\PlagiarismIndex::class)->stats(), ['documents' => 0, 'failed' => 0, 'fingerprints' => 0, 'total' => 0, 'ready' => false]),
            'corpus' => $this->safe(fn () => app(\App\Services\Originality\CorpusHarvester::class)->stats(), ['ready' => false]),
            // The cron key is generated on first visit, so no .env editing is needed.
            'cronUrl' => $this->safe(fn () => route('cron', \App\Support\RuntimeSettings::cronSecret(true)), null),
            'cronLastRun' => $this->safe(fn () => ($at = \App\Support\AppSetting::get(\App\Http\Controllers\CronController::LAST_RUN)) ? \Illuminate\Support\Carbon::parse($at) : null, null),
            'server' => [
                'memory_limit' => ini_get('memory_limit'),
                'memory_raise' => $this->canRaiseMemory(),
                'max_execution_time' => ini_get('max_execution_time'),
                'pdf_parser' => class_exists(\Smalot\PdfParser\Parser::class),
                'zlib' => extension_loaded('zlib'),
                'mbstring' => extension_loaded('mbstring'),
                'fileinfo' => class_exists('finfo'),
                'php' => PHP_VERSION,
                'storage_writable' => is_writable(storage_path('app')),
            ],
            'web' => [
                'enabled' => app(\App\Services\Originality\WebChecker::class)->enabled(),
                'used' => app(\App\Services\Originality\WebChecker::class)->usedThisMonth(),
                'limit' => app(\App\Services\Originality\WebChecker::class)->monthlyLimit(),
            ],
            'mail' => [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'from' => config('mail.from.address'),
            ],
            'wordConverter' => [
                'libreoffice' => app(\App\Services\WordToPdf::class)->libreOffice(),
                'php' => extension_loaded('zip') && extension_loaded('xml') && extension_loaded('dom'),
            ],
        ]);
    }

    public function migrate()
    {
        $response = $this->run('migrate', ['--force' => true]);
        \App\Support\SchemaState::reset();

        return $response;
    }

    /** Shared hosting often forbids raising memory_limit at runtime; large PDFs need it. */
    private function canRaiseMemory(): bool
    {
        $before = ini_get('memory_limit');
        $ok = @ini_set('memory_limit', '768M') !== false && ini_get('memory_limit') === '768M';
        @ini_set('memory_limit', $before);

        return $ok;
    }

    /** The page must open even when a step fails (e.g. tables not created yet), so the update button stays reachable. */
    private function safe(callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    public function webhook()
    {
        // Use the address the panel is opened on, so APP_URL does not have to be exact.
        return $this->run('telegram:webhook', ['--url' => request()->root()]);
    }

    public function webhookDelete()
    {
        return $this->run('telegram:webhook', ['--delete' => true]);
    }

    /** Move files to Telegram, delete server copies, prune caches, remove leftover update zips and old logs. */
    public function filesToTelegram(\App\Services\SpaceSaver $saver)
    {
        @set_time_limit(120);
        try {
            $report = $saver->run(40);
            $leftovers = $saver->removeLeftovers();
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $lines = $report['failed'];
        foreach ($leftovers['removed'] as $name => $bytes) {
            $lines[] = '🗑 '.$name.' ('.number_format($bytes / 1048576, 1).' MB)';
        }
        $report['cache_freed_bytes'] += $leftovers['bytes'];
        \Illuminate\Support\Facades\Cache::forget('disk-usage');

        return back()->with($report['failed'] ? 'error' : 'success', \App\Services\SpaceSaver::summary($report))
            ->with('output', implode("\n", $lines));
    }

    /** Telegram settings from the panel (stored encrypted in the database, overriding .env). */
    public function telegramSave(Request $request)
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:100', 'regex:/^\d{5,}:[A-Za-z0-9_-]{20,}$/'],
            'storage_chat_id' => ['nullable', 'string', 'max:200'],
            'admin_chat_ids' => ['nullable', 'string', 'max:500'],
        ], ['token.regex' => __('This does not look like a bot token from @BotFather.')]);

        // Valid fields are saved even when another field has a problem; problems are reported together.
        $values = [];
        $problems = [];

        if (! empty($data['token'])) {
            try {
                $bot = (new TelegramApi($data['token']))->call('getMe');
                $values['token'] = $data['token'];
                $values['username'] = $bot['username'] ?? null;
            } catch (Throwable $e) {
                $problems[] = __('Telegram did not accept this token.').' '.$e->getMessage();
            }
        }

        $editors = preg_split('/[\s,;]+/', (string) ($data['admin_chat_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $bad = array_filter($editors, fn ($id) => ! preg_match('/^-?\d{4,}$/', $id));
        if ($bad) {
            $problems[] = __('Editor chat ids must be numbers: :id', ['id' => implode(', ', $bad)]);
        } else {
            $values['admin_chat_ids'] = $editors;
        }

        $storage = trim((string) ($data['storage_chat_id'] ?? ''));
        if ($storage === '' || preg_match('/^-?\d{4,}$/', $storage)) {
            $values['storage_chat_id'] = $storage ?: null;
        } elseif (preg_match('#t(?:elegram)?\.me/c/(\d{5,})#i', $storage, $m)) {
            // "Copy Post Link" in a private channel gives t.me/c/<id>/<post>; the chat id is -100<id>.
            $values['storage_chat_id'] = '-100'.$m[1];
        } elseif (preg_match('#(t\.me|telegram\.me)/(\+|joinchat/)#i', $storage)) {
            $problems[] = __('A private invite link cannot be used as the channel id. Leave the field empty, post any message in the channel (the bot must be its admin), reload this page and press "Storage channel" next to it.').' '.__('Or paste the link of any post in the channel (right-click the post → Copy Post Link).');
        } elseif (preg_match('#^(?:https?://)?(?:t\.me/|telegram\.me/|@)?([A-Za-z][A-Za-z0-9_]{4,})$#', $storage, $m)) {
            // Public channel: ask Telegram for its numeric id.
            try {
                $token = $values['token'] ?? config('services.telegram.token');
                $chat = (new TelegramApi($token))->call('getChat', ['chat_id' => '@'.$m[1]]);
                $values['storage_chat_id'] = (string) $chat['id'];
            } catch (Throwable $e) {
                $problems[] = __('Telegram could not find the channel @:name. Is the bot an admin of it?', ['name' => $m[1]]);
            }
        } else {
            $problems[] = __('The storage channel id must be a number like -1001234567890.');
        }

        // The bot must be able to post in the storage chat, and it must not be public.
        if (! empty($values['storage_chat_id']) && $values['storage_chat_id'] !== (string) \App\Support\RuntimeSettings::telegram()['storage_chat_id']) {
            $token = $values['token'] ?? config('services.telegram.token');
            if ($token && $problem = $this->chatProblem(new TelegramApi($token), $values['storage_chat_id'], $token)) {
                $problems[] = $values['storage_chat_id'].': '.$problem;
                unset($values['storage_chat_id']);
            }
        }

        if (! \App\Support\RuntimeSettings::telegram()['webhook_secret']) {
            $values['webhook_secret'] = \Illuminate\Support\Str::random(40);
        }
        \App\Support\RuntimeSettings::saveTelegram($values);

        if ($problems) {
            return back()->withInput(['admin_chat_ids' => $data['admin_chat_ids'] ?? ''])
                ->with('error', implode(' ', $problems).(count($values) > 1 ? ' '.__('Other settings were saved.') : ''));
        }

        return back()->with('success', __('Telegram settings saved.'));
    }

    /** One-click: use a chat the bot has seen as the storage channel or as an editor. */
    public function telegramUseChat(Request $request)
    {
        $data = $request->validate([
            'chat_id' => ['required', 'regex:/^-?\d{4,}$/'],
            'role' => 'required|in:storage,editor',
        ]);
        $current = \App\Support\RuntimeSettings::telegram();

        if ($problem = $this->chatProblem(app(TelegramApi::class), $data['chat_id'])) {
            return back()->with('error', $data['chat_id'].': '.$problem);
        }

        \App\Support\RuntimeSettings::saveTelegram($data['role'] === 'storage'
            ? ['storage_chat_id' => $data['chat_id']]
            : ['admin_chat_ids' => array_merge($current['admin_chat_ids'] ?: config('services.telegram.admin_chat_ids', []), [$data['chat_id']])]);

        return back()->with('success', __('Telegram settings saved.'));
    }

    /** Null when the bot can reach the chat; otherwise a short explanation. Refreshes the chat's name on the way. */
    private function chatProblem(TelegramApi $telegram, string $chatId, ?string $token = null): ?string
    {
        try {
            $chat = $telegram->call('getChat', ['chat_id' => $chatId]);
            if (! empty($chat['title'])) {
                \App\Models\TelegramChat::where('chat_id', $chatId)->update(['first_name' => mb_substr($chat['title'], 0, 255), 'username' => $chat['username'] ?? null]);
            }
            // Public channels can be read by anyone, so getChat alone does not prove the bot may post there.
            if (($chat['type'] ?? '') === 'channel') {
                $botId = strtok((string) ($token ?? config('services.telegram.token')), ':');
                $member = $telegram->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
                if (($member['status'] ?? '') !== 'administrator' || ($member['can_post_messages'] ?? false) === false) {
                    return __('Make the bot an admin of this channel with the right to post messages.');
                }
                if (! empty($chat['username'])) {
                    return __('This channel is public (@:name): everyone would see the manuscripts. Use a private channel for storage.', ['name' => $chat['username']]);
                }
            }

            return null;
        } catch (Throwable $e) {
            return \App\Services\Telegram\TelegramError::hint($e->getMessage()) ?? $e->getMessage();
        }
    }

    /** Zenodo connection: personal access token (stored encrypted) and automatic deposit on acceptance. */
    public function zenodoSave(Request $request, \App\Services\ZenodoService $zenodo)
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9]{20,200}$/'],
            'auto' => 'nullable|boolean',
            'disconnect' => 'nullable|boolean',
        ], ['token.regex' => __('This does not look like a Zenodo access token.')]);

        $auto = $request->boolean('auto');
        if ($request->boolean('disconnect')) {
            \App\Services\ZenodoService::save('', $auto, null);

            return redirect()->to(route('admin.system').'#zenodo')->with('success', __('Zenodo disconnected.'));
        }

        $token = null;
        if (! empty($data['token'])) {
            try {
                $zenodo->check($data['token']);
            } catch (Throwable $e) {
                return redirect()->to(route('admin.system').'#zenodo')->with('error', __('Zenodo did not accept this token.').' '.$e->getMessage());
            }
            $token = $data['token'];
        }
        \App\Services\ZenodoService::save($token, $auto, $token ? auth()->user()->name : null);

        return redirect()->to(route('admin.system').'#zenodo')->with('success', __('Saved.'));
    }

    /** Journal details printed on certificates. */
    public function journalSave(Request $request)
    {
        $data = $request->validate([
            'indexing' => 'nullable|string|max:2000',
            'issn' => ['nullable', 'string', 'max:40', 'regex:/^[0-9X\s\-\(\)A-Za-z:,]+$/'],
            'website' => 'nullable|string|max:120',
            'editor_name' => 'nullable|string|max:120',
            'editor_title' => 'nullable|string|max:120',
            'payment_fee' => 'nullable|string|max:60',
            'payment_info' => 'nullable|string|max:1000',
        ]);
        $data['indexing'] = array_values(array_unique(array_filter(array_map(
            fn ($line) => mb_substr(trim($line, " \t-•*·◆"), 0, 60),
            preg_split('/\r\n|\r|\n|;/', (string) ($data['indexing'] ?? ''))
        ))));
        \App\Support\JournalProfile::save(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data));

        return redirect(route('admin.system').'#certificate')->with('success', __('Saved.'));
    }

    /** Sample certificate with the current settings, built from the latest article (nothing is saved). */
    public function certificatePreview(string $type, \App\Services\CertificateService $certificates)
    {
        abort_unless(in_array($type, ['publication', 'submission'], true), 404);

        return response($certificates->previewPdf($type, app()->getLocale()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate-preview.pdf"',
        ]);
    }

    /** Switch automatic Telegram channel posts of new papers on or off. */
    public function channelAutopost(Request $request, \App\Services\ChannelPoster $poster)
    {
        \App\Services\ChannelPoster::setEnabled($request->boolean('enabled'));
        $status = $poster->botStatus(true);

        return back()->with($request->boolean('enabled') && ! $status['ok'] ? 'error' : 'success',
            $request->boolean('enabled')
                ? ($status['ok'] ? __('New papers will be posted to :channel automatically.', ['channel' => $poster->chat()])
                    : __('Switched on, but the bot cannot post in :channel yet (:reason). Make the bot an admin with the right to post messages.', ['channel' => $poster->chat(), 'reason' => $status['reason']]))
                : __('Automatic channel posts are off.'))->withFragment('channel-autopost');
    }

    /** Gives the next older articles a Zenodo DOI (also done a few at a time by the cron job). */
    public function zenodoBacklog(Request $request, \App\Services\ZenodoBacklog $backlog)
    {
        if ($request->boolean('retry')) {
            $backlog->retryFailed();
        }
        $r = $backlog->run(50, 10);
        $stats = $backlog->stats();
        if ($request->wantsJson()) {
            return response()->json($r + \Illuminate\Support\Arr::except($stats, 'reasons'));
        }

        return back()->with($r['failed'] ? 'error' : 'success',
            __('DOIs received: :done. Failed: :failed. Remaining: :remaining.', ['done' => $r['done'], 'failed' => $r['failed'], 'remaining' => $stats['remaining']])
            .($r['stopped'] ? ' '.$r['stopped'] : ''))->withFragment('zenodo-backlog');
    }

    /** Announce every page in the sitemap to Bing, Yandex & co. (IndexNow). */
    public function indexNow(\App\Services\IndexNow $indexNow)
    {
        if (! $indexNow->key()) {
            return back()->with('error', __('IndexNow key file is missing.'));
        }
        $r = $indexNow->everything();

        return back()->with(in_array($r['status'], [200, 202], true) ? 'success' : 'error',
            __('Sent to search engines: :count pages (answer :status).', ['count' => $r['sent'], 'status' => $r['status'] ?? '—']))->withFragment('indexnow');
    }

    /** Reads the next batch of PDFs and fills empty abstracts (or excerpts) on the article pages. */
    public function abstracts(\App\Services\AbstractFiller $filler)
    {
        if (! $filler->ready()) {
            return back()->with('error', __('Run the database update first.'));
        }
        $r = $filler->run(25);

        return back()->with('success', __('Abstracts found: :abstracts. Excerpts: :excerpts. Nothing usable: :none. Errors: :failed.', $r).' '
            .__('Remaining: :count.', ['count' => $filler->stats()['remaining']]))->withFragment('abstracts');
    }

    /** One-time invitation to the Telegram channel for authors of published papers. */
    public function channelInvite(\App\Services\ChannelInviter $inviter)
    {
        if (! $inviter->ready()) {
            return back()->with('error', __('Run the database update first.'));
        }

        @set_time_limit(300);
        $result = $inviter->send();

        return back()->with($result['failed'] ? 'error' : 'success', __('Channel invitations sent: :sent. Failed: :failed.', $result));
    }

    public function testMail(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);

        try {
            Mail::raw(__('Test e-mail from :journal. E-mail sending works.', ['journal' => config('journal.name')]), function ($message) use ($data) {
                $message->to($data['email'])->subject(config('journal.short_name').' · '.__('Test e-mail'));
            });
        } catch (Throwable $e) {
            return back()->with('error', __('E-mail could not be sent').': '.$e->getMessage());
        }

        return back()->with('success', config('mail.default') === 'log'
            ? __('MAIL_MAILER is "log": the message was written to the log file, not sent. Set the SMTP values in .env.')
            : __('Test e-mail sent to :email. Check the inbox (and spam folder).', ['email' => $data['email']]));
    }

    public function testTelegram(TelegramApi $telegram)
    {
        $targets = array_filter(array_unique(array_merge(
            config('services.telegram.admin_chat_ids', []),
            [(string) config('services.telegram.storage_chat_id')]
        )));
        if (! $targets) {
            return back()->with('error', __('Add TELEGRAM_ADMIN_CHAT_IDS and TELEGRAM_STORAGE_CHAT_ID to .env first.'));
        }

        $text = '✅ '.e(__('Test message from :journal.', ['journal' => config('journal.name')]));
        $lines = [];
        $failed = 0;
        foreach ($targets as $chatId) {
            try {
                try {
                    $telegram->sendMessage($chatId, $text);
                    $lines[] = "✓ {$chatId}";
                } catch (\App\Services\Telegram\TelegramError $e) {
                    if (! $e->migrateTo) {
                        throw $e;
                    }
                    // The group was upgraded to a supergroup: switch to its new id and retry.
                    \App\Support\RuntimeSettings::replaceChat((string) $chatId, $e->migrateTo);
                    $telegram->sendMessage($e->migrateTo, $text);
                    $lines[] = "✓ {$e->migrateTo} (".__('new id of :old, saved', ['old' => $chatId]).')';
                }
            } catch (Throwable $e) {
                $failed++;
                $hint = \App\Services\Telegram\TelegramError::hint($e->getMessage());
                $lines[] = "✗ {$chatId}: ".$e->getMessage().($hint ? "\n   → ".$hint : '');
            }
        }

        return back()
            ->with($failed ? 'error' : 'success', $failed
                ? __('Sent to :ok of :all chats. See the details below.', ['ok' => count($targets) - $failed, 'all' => count($targets)])
                : __('Test message sent to every chat.'))
            ->with('output', implode("\n", $lines));
    }

    /** @return array<int, array{id: string, type: string, name: string}> */
    private function recentChats(TelegramApi $telegram): array
    {
        $chats = [];
        foreach ($telegram->call('getUpdates', ['limit' => 100, 'timeout' => 0]) as $update) {
            foreach (['message', 'channel_post', 'my_chat_member', 'edited_message', 'callback_query'] as $kind) {
                $chat = $update[$kind]['chat'] ?? $update[$kind]['message']['chat'] ?? null;
                if ($chat) {
                    $chats[(string) $chat['id']] = [
                        'id' => (string) $chat['id'],
                        'type' => $chat['type'] ?? '',
                        'name' => $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')).(isset($chat['username']) ? ' @'.$chat['username'] : ''),
                    ];
                }
            }
        }

        return array_values($chats);
    }

    /** Index the archive for the plagiarism check, in ~45 s steps (press again to continue). */
    public function buildIndex(\App\Services\Originality\PlagiarismIndex $index)
    {
        if (! \App\Support\SchemaState::originalityReady()) {
            return back()->with('error', __('Run the database update first.'));
        }
        $result = $index->sync(45, true);
        if ($result['busy'] ?? false) {
            return back()->with('success', __('The index is being updated by another task. Try again in a minute.'));
        }

        return back()->with('success', __('Indexed :n document(s). :p still waiting.', ['n' => $result['indexed'], 'p' => $result['pending']]));
    }

    /** Harvest the next batch of published Uzbek research (about 45 s). Cron does this automatically. */
    public function harvestCorpus(\App\Services\Originality\CorpusHarvester $harvester)
    {
        if (! \App\Support\SchemaState::corpusReady()) {
            return back()->with('error', __('Run the database update first.'));
        }
        $r = $harvester->harvest(45);

        return back()->with($r['error'] ? 'error' : 'success', __(':n works added from :p pages.', ['n' => $r['works'], 'p' => $r['pages']])
            .($r['error'] ? ' '.$r['error'] : ''));
    }

    public function clearCache()
    {
        // Stamped PDFs are regenerated on the next download.
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/stamped'));

        return $this->run('optimize:clear');
    }

    private function run(string $command, array $arguments = [])
    {
        @set_time_limit(600);

        try {
            $code = Artisan::call($command, $arguments);
            $output = trim(preg_replace('/\e\[[\d;]*m/', '', Artisan::output()));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($code === 0 ? 'success' : 'error', __('Done.'))->with('output', $output);
    }

}
