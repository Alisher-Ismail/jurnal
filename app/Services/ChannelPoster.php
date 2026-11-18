<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Maqola;
use App\Services\Telegram\TelegramApi;
use App\Support\AppSetting;
use App\Support\Locale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Announces published papers in the journal's Telegram channel (the bot posts as the channel).
 * Automatic: every article published after the switch was turned on, once it has its DOI
 * (or three hours after publication if it gets none), at most two per cron run. Old articles
 * are never posted in bulk; editors can post one by hand or announce a whole issue.
 */
class ChannelPoster
{
    public const SETTING = 'channel_autopost';

    /** Without a DOI after this long (Zenodo off or failed), the article is announced anyway. */
    private const WAIT_FOR_DOI_HOURS = 3;

    public function __construct(private TelegramApi $telegram)
    {
    }

    /** "@emrojournal" from the channel link in the journal settings. */
    public function chat(): ?string
    {
        return PaperService::channelHandle();
    }

    /** @return array{enabled: bool, since: ?string} */
    public static function settings(): array
    {
        $saved = (array) (AppSetting::get(self::SETTING, []) ?: []);

        return ['enabled' => (bool) ($saved['enabled'] ?? false), 'since' => $saved['since'] ?? null];
    }

    public static function setEnabled(bool $enabled): void
    {
        $saved = self::settings();
        // Only papers published from now on are announced: switching on never floods the channel with the archive.
        AppSetting::put(self::SETTING, ['enabled' => $enabled, 'since' => $enabled ? now()->toIso8601String() : $saved['since']]);
    }

    public function ready(): bool
    {
        return $this->telegram->enabled() && $this->chat() !== null && Schema::hasColumn('article', 'channel_posted_at');
    }

    /** Whether the bot may post in the channel (checked with Telegram, remembered for 10 minutes). */
    public function botStatus(bool $fresh = false): array
    {
        if (! $this->telegram->enabled() || ! $this->chat()) {
            return ['ok' => false, 'reason' => 'not configured'];
        }
        if ($fresh) {
            Cache::forget('channel-bot-status');
        }

        return Cache::remember('channel-bot-status', now()->addMinutes(10), function () {
            try {
                $me = $this->telegram->call('getMe');
                $member = $this->telegram->call('getChatMember', ['chat_id' => $this->chat(), 'user_id' => $me['id']]);
                $canPost = ($member['status'] ?? '') === 'creator'
                    || (($member['status'] ?? '') === 'administrator' && ($member['can_post_messages'] ?? false));

                return ['ok' => $canPost, 'reason' => $canPost ? null : 'status: '.($member['status'] ?? '?')];
            } catch (Throwable $e) {
                return ['ok' => false, 'reason' => $e->getMessage()];
            }
        });
    }

    /** Articles waiting to be announced automatically. */
    public function due()
    {
        $since = self::settings()['since'];

        return Article::query()
            ->whereNull('channel_posted_at')
            ->where('created_at', '>=', Carbon::parse($since ?? now())->utc())
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', ''))
                ->orWhere('created_at', '<', now()->subHours(self::WAIT_FOR_DOI_HOURS)))
            ->orderBy('id');
    }

    /** @return array{posted: int, error: ?string} */
    public function run(int $max = 2): array
    {
        $result = ['posted' => 0, 'error' => null];
        if (! self::settings()['enabled'] || ! $this->ready()) {
            return $result;
        }
        foreach ($this->due()->limit($max)->get() as $article) {
            try {
                $this->postArticle($article);
                $result['posted']++;
            } catch (Throwable $e) {
                $result['error'] = $e->getMessage();
                break; // try again on the next run
            }
        }

        return $result;
    }

    public function postArticle(Article $article): array
    {
        $url = VisitTracker::tag(Locale::route('maqola', $article->id, 'uz'));
        $message = $this->telegram->sendMessage($this->chat(), $this->articleText($article), [
            'inline_keyboard' => [[
                ['text' => '📖 Maqolani oʻqish', 'url' => $url],
                ['text' => '📤 Maqola yuborish', 'url' => $this->botUrl() ?? VisitTracker::tag(Locale::route('submit', [], 'uz'))],
            ]],
        ], ['disable_web_page_preview' => true]);
        // Query builder: no "saved" hooks (IndexNow, topics) and no change to updated_at.
        DB::table('article')->where('id', $article->id)->update([
            'channel_posted_at' => now(), 'channel_message_id' => $message['message_id'] ?? null,
        ]);

        return $message;
    }

    public function articleText(Article $article): string
    {
        $authors = $article->authorList();
        $lines = ['📄 <b>Yangi maqola</b>'];
        $lines[] = '';
        $lines[] = '<b>«'.e(Str::limit(trim((string) $article->title), 300)).'»</b>';
        if ($authors) {
            $lines[] = '👤 '.e(Str::limit(implode(', ', $authors), 300));
        }
        if ($article->issue) {
            $lines[] = '📚 '.e($article->issue->title);
        }
        if ($article->doi) {
            $lines[] = '🔗 DOI: <a href="'.e($article->doiUrl()).'">'.e($article->doi).'</a>';
            // Only what is true from day one: Zenodo/DataCite/OpenAIRE follow the DOI; Google Scholar comes later.
            $lines[] = '🗂 Zenodo (CERN) · DataCite · OpenAIRE · Google Scholar’da indekslanadi';
        } else {
            $lines[] = '🗂 Google Scholar’da indekslanadi';
        }
        if ($tags = $this->hashtags($article)) {
            $lines[] = '';
            $lines[] = $tags;
        }
        $lines[] = '';
        $lines[] = '📤 Maqolangizni yuboring: '.e(($this->botHandle() ?? '').' ').'· 1 kunda chop etiladi';

        return implode("\n", $lines);
    }

    /** Up to three of the article's keywords as Telegram hashtags (#raqamli_iqtisodiyot). */
    private function hashtags(Article $article): string
    {
        $tags = [];
        foreach ($article->keywordList() as $keyword) {
            $tag = preg_replace("/[‘’ʻʼ`´']/u", '', mb_strtolower(trim($keyword))); // sun’iy → suniy
            $tag = preg_replace('/[^\p{L}\p{N}_]+/u', '_', $tag);
            $tag = trim(preg_replace('/_+/', '_', $tag), '_');
            if (mb_strlen($tag) >= 3 && mb_strlen($tag) <= 40 && ! preg_match('/^\d+$/', $tag)) {
                $tags[$tag] = '#'.$tag;
            }
            if (count($tags) === 3) {
                break;
            }
        }

        return implode(' ', $tags);
    }

    /** One post announcing a whole issue, with links to its articles. */
    public function postIssue(Maqola $issue): array
    {
        $articles = $issue->articles()->orderBy('id')->get();
        $head = ['📚 <b>Yangi son: '.e($issue->title).'</b>', $articles->count().' ta maqola · ochiq kirish, DOI bilan', ''];
        $list = [];
        $budget = 3600 - mb_strlen(implode("\n", $head));
        foreach ($articles as $i => $article) {
            $line = ($i + 1).'. <a href="'.e(VisitTracker::tag(Locale::route('maqola', $article->id, 'uz'))).'">'.e(Str::limit(trim((string) $article->title), 90)).'</a>';
            if (mb_strlen($line) + 1 > $budget) {
                $list[] = '… yana '.($articles->count() - $i).' ta maqola';
                break;
            }
            $budget -= mb_strlen($line) + 1;
            $list[] = $line;
        }
        $text = implode("\n", array_merge($head, $list, ['', '📤 Maqolangizni yuboring: '.e($this->botHandle() ?? '')]));

        return $this->telegram->sendMessage($this->chat(), $text, ['inline_keyboard' => [[
            ['text' => '📖 Sonni ochish', 'url' => VisitTracker::tag(Locale::route('maqolavolume', $issue->id, 'uz'))],
        ]]]);
    }

    private function botHandle(): ?string
    {
        $bot = config('services.telegram.username');

        return $bot ? '@'.ltrim($bot, '@') : null;
    }

    private function botUrl(): ?string
    {
        $bot = $this->botHandle();

        return $bot ? 'https://t.me/'.ltrim($bot, '@') : null;
    }
}
