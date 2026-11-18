<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts visits to the public website as daily totals: visitors, page views, PDF downloads,
 * per-article views, where visitors came from, language and device. Search-engine crawlers and
 * other bots are counted separately (by name) and never as visitors. No IP address is stored:
 * a visitor is a hash of IP + browser + day, kept two days only to count each person once a day.
 */
class VisitTracker
{
    /** Days begin at midnight in Uzbekistan (the app itself keeps UTC). */
    public const TZ = 'Asia/Tashkent';

    private const BOTS = [
        'Googlebot' => 'googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google|google-read-aloud|storebot-google',
        'Google Scholar' => 'scholar',
        'Bingbot' => 'bingbot|bingpreview|msnbot|adidxbot',
        'Yandex' => 'yandex',
        'Baidu' => 'baiduspider',
        'DuckDuckGo' => 'duckduckbot|duckassist',
        'Apple' => 'applebot',
        'Telegram' => 'telegrambot',
        'Facebook' => 'facebookexternalhit|facebookcatalog|meta-externalagent',
        'AI' => 'gptbot|chatgpt|oai-searchbot|claudebot|claude-web|anthropic|perplexity|ccbot|bytespider|amazonbot|cohere|diffbot',
        'SEO' => 'ahrefs|semrush|mj12bot|dotbot|petalbot|serpstat|dataforseo|blexbot|seznambot|barkrowler|megaindex',
        'Zenodo/OAI' => 'zenodo|openaire|core\.ac|base-search|oai|harvest',
    ];

    private const AGENT_NAMES = [
        'gptbot' => 'GPTBot (OpenAI)', 'chatgpt' => 'ChatGPT', 'oai-searchbot' => 'OAI-SearchBot (ChatGPT search)',
        'claudebot' => 'ClaudeBot (Anthropic)', 'claude-web' => 'Claude', 'anthropic' => 'Anthropic',
        'perplexity' => 'Perplexity', 'ccbot' => 'CCBot (Common Crawl)', 'bytespider' => 'Bytespider (ByteDance)',
        'amazonbot' => 'Amazonbot', 'cohere' => 'Cohere', 'diffbot' => 'Diffbot',
        'ahrefs' => 'Ahrefs', 'semrush' => 'Semrush', 'mj12bot' => 'Majestic', 'dotbot' => 'Moz DotBot', 'petalbot' => 'PetalBot (Huawei)',
        'serpstat' => 'Serpstat', 'dataforseo' => 'DataForSEO', 'blexbot' => 'BLEXBot', 'seznambot' => 'SeznamBot', 'barkrowler' => 'Barkrowler', 'megaindex' => 'MegaIndex',
    ];

    private const OTHER_BOT = '/bot\b|bot\/|crawl|spider|slurp|preview|fetch|scan|monitor|uptime|check|curl|wget|python|java\/|httpclient|okhttp|go-http|axios|node|guzzle|php\/|ruby|perl|libwww|headless|phantom|lighthouse|pagespeed|gtmetrix|whatsapp|vkshare|skype|discord|slack|embedly|quora|pinterest|archive\.org|ia_archiver|validator|feed|rss/i';

    /** "Googlebot", "Bingbot" …, "Other bots", or null for a person's browser. */
    public static function botName(?string $agent): ?string
    {
        $agent = (string) $agent;
        foreach (self::BOTS as $name => $pattern) {
            if (preg_match('/'.$pattern.'/i', $agent, $m)) {
                // AI and SEO crawlers are many different companies: keep which one it was.
                if (in_array($name, ['AI', 'SEO'], true)) {
                    return $name.': '.(self::AGENT_NAMES[strtolower($m[0])] ?? $m[0]);
                }

                return $name;
            }
        }
        // Every real browser announces itself as "Mozilla/5.0 …".
        if (! str_starts_with($agent, 'Mozilla/') || preg_match(self::OTHER_BOT, $agent)) {
            return 'Other bots';
        }

        return null;
    }

    /** Records a request after its response has been sent ($kind: page or download). */
    public function record(Request $request, Response $response, string $kind): void
    {
        try {
            if (! in_array($request->getMethod(), ['GET'], true) || $response->getStatusCode() !== 200
                || $request->headers->get('Purpose') === 'prefetch' || str_contains((string) $request->headers->get('Sec-Purpose'), 'prefetch')
                || auth()->check()) {
                return; // editors browsing their own site are not visitors
            }
            $day = now(self::TZ)->toDateString();
            $agent = (string) $request->userAgent();

            if ($bot = self::botName($agent)) {
                $this->days($day, ['bots' => 1]);
                $this->counts($day, [['bot', $bot]]);

                return;
            }

            $hash = substr(hash('sha256', config('app.key').'|'.$day.'|'.$request->ip().'|'.$agent), 0, 16);
            $new = DB::table('visit_uniques')->insertOrIgnore(['day' => $day, 'hash' => $hash]) > 0;

            $rows = [];
            $source = $this->source($request);
            if ($source !== null) {
                $rows[] = ['source', $source];
            } elseif ($new) {
                $rows[] = ['source', 'Direct'];
            }
            if ($new) {
                $rows[] = ['device', preg_match('/Mobi|Android|iPhone|iPad/i', $agent) ? 'mobile' : 'desktop'];
            }

            $id = (int) $request->route('id');
            if ($kind === 'download') {
                $route = (string) $request->route()?->getName();
                if ($route === 'files.article' && $id) {
                    $rows[] = ['download', (string) $id];
                    $this->bump($id, 'downloads_count');
                }
                // Where PDF downloads come from: many skip the article page (Google Scholar, shared PDF links).
                $rows[] = ['dlsource', $source ?? ($request->headers->get('referer') ? 'Journal page' : 'Direct')];
                $this->days($day, ['downloads' => 1, 'visitors' => $new ? 1 : 0]);
            } else {
                $page = $this->page((string) $request->route()?->getName());
                $rows[] = ['page', $page];
                $rows[] = ['lang', app()->getLocale()];
                if ($page === 'article' && $id) {
                    $rows[] = ['article', (string) $id];
                    $this->bump($id, 'views_count');
                }
                $this->days($day, ['views' => 1, 'visitors' => $new ? 1 : 0]);
            }
            $this->counts($day, $rows);

            if (random_int(1, 300) === 1) {
                DB::table('visit_uniques')->where('day', '<', now(self::TZ)->subDays(2)->toDateString())->delete();
            }
        } catch (Throwable $e) {
            // Statistics must never break a page (e.g. before the database update has run).
        }
    }

    /** The running totals shown on the article page (query builder: no model events, no updated_at change). */
    private function bump(int $articleId, string $column): void
    {
        static $ready = null; // one schema check per request
        $ready ??= Schema::hasColumn('article', 'views_count');
        if ($ready) {
            DB::table('article')->where('id', $articleId)->increment($column);
        }
    }

    private function days(string $day, array $add): void
    {
        $add = array_filter($add);
        if (! $add) {
            return;
        }
        $grammar = DB::getQueryGrammar();
        DB::table('visit_days')->upsert(
            [['day' => $day] + $add],
            ['day'],
            array_map(fn ($c) => DB::raw($grammar->wrap($c).' + '.(int) $add[$c]), array_combine(array_keys($add), array_keys($add)))
        );
    }

    /** @param array<int, array{0: string, 1: string}> $rows */
    private function counts(string $day, array $rows): void
    {
        if (! $rows) {
            return;
        }
        DB::table('visit_counts')->upsert(
            array_map(fn ($r) => ['day' => $day, 'kind' => $r[0], 'item' => mb_substr($r[1], 0, 120), 'hits' => 1], $rows),
            ['day', 'kind', 'item'],
            ['hits' => DB::raw(DB::getQueryGrammar()->wrap('hits').' + 1')]
        );
    }

    /** Marks a link we post elsewhere, so visits through it are counted under that source: "?from=telegram". */
    public static function tag(string $url, string $source = 'telegram'): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'from='.$source;
    }

    /** Where the visitor came from (another website), or null when they came from this site or typed the address. */
    private function source(Request $request): ?string
    {
        // Links we post ourselves carry ?from=… (or utm_source): apps such as Telegram send no referrer.
        $tag = strtolower(trim((string) ($request->query('from') ?: $request->query('utm_source'))));
        if ($tag !== '' && preg_match('/^[a-z0-9._-]{2,30}$/', $tag)) {
            return ['telegram' => 'Telegram', 'tg' => 'Telegram', 'bot' => 'Telegram', 'email' => 'E-mail', 'mail' => 'E-mail',
                'instagram' => 'Instagram', 'facebook' => 'Facebook', 'whatsapp' => 'WhatsApp', 'zenodo' => 'Zenodo', 'nudge' => 'Taklif kartasi (sayt)'][$tag] ?? ucfirst($tag);
        }
        $host = strtolower((string) parse_url((string) $request->headers->get('referer'), PHP_URL_HOST));
        if ($host === '' || $host === strtolower($request->getHost())) {
            return null;
        }
        $host = preg_replace('/^(www\.|m\.)/', '', $host);
        $known = [
            'Google Scholar' => '/^scholar\.google\./',
            'Google' => '/(^|\.)google\.[a-z.]+$|googleusercontent|^com\.google/',
            'Yandex' => '/(^|\.)yandex\.|(^|\.)ya\.ru$/',
            'Bing' => '/(^|\.)bing\.com$/',
            'Telegram' => '/(^|\.)t\.me$|telegram\.org$|^org\.telegram/',
            'Facebook' => '/facebook\.com$|fb\.com$/',
            'Instagram' => '/instagram\.com$/',
            'Zenodo' => '/zenodo\.org$/',
            'DuckDuckGo' => '/duckduckgo\.com$/',
            'ChatGPT' => '/chatgpt\.com$|openai\.com$/',
            'emro.uz' => '/(^|\.)emro\.uz$/',
        ];
        foreach ($known as $name => $pattern) {
            if (preg_match($pattern, $host)) {
                return $name;
            }
        }

        return $host;
    }

    private function page(string $route): string
    {
        $base = preg_replace('/(eng|ru)$/', '', $route);

        return [
            'maqolabosh' => 'home', 'maqola' => 'article', 'maqolavolume' => 'issue', 'mvolume' => 'archive',
            'maqolaauthor' => 'author', 'maqolaqidiruv' => 'search', 'submit' => 'submit',
            'mavzu' => 'topic', 'mavzular' => 'topic', 'guide' => 'guide',
        ][$base] ?? 'other';
    }
}
