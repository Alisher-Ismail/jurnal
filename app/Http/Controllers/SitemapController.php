<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Maqola;
use App\Support\Locale;
use Illuminate\Support\Facades\Cache;

/** sitemap.xml for search engines: home, archive, journal pages, every issue and article in all languages. */
class SitemapController extends Controller
{
    public function __invoke()
    {
        $xml = Cache::remember('sitemap.xml', now()->addHour(), function () {
            $urls = [];
            $add = function (string $name, $params, ?\DateTimeInterface $modified, string $priority) use (&$urls) {
                $alternates = [];
                foreach (Locale::all() as $locale) {
                    $alternates[$locale] = Locale::route($name, $params, $locale);
                }
                foreach ($alternates as $loc) {
                    $urls[] = compact('loc', 'alternates', 'modified', 'priority');
                }
            };

            $add('maqolabosh', [], Maqola::max('updated_at') ? now() : null, '1.0');
            $add('mvolume', [], null, '0.8');
            $add('mavzular', [], null, '0.7');
            $add('guide', [], null, '0.9');
            foreach (['haqimiz', 'maqsadimiz', 'jamoa', 'yoriqnoma', 'kochirma', 'submit', 'contact'] as $page) {
                $add($page, [], null, '0.5');
            }
            foreach (Maqola::orderBy('id')->get(['id', 'updated_at', 'created_at']) as $issue) {
                $add('maqolavolume', $issue->id, $issue->updated_at ?? $issue->created_at, '0.8');
            }
            // Articles: only their main (Uzbek) address, which every language version names as canonical.
            foreach (Article::orderBy('id')->cursor(['id', 'updated_at', 'created_at']) as $article) {
                $urls[] = ['loc' => Locale::route('maqola', $article->id, 'uz'), 'alternates' => [],
                    'modified' => $article->updated_at ?? $article->created_at, 'priority' => '0.6'];
            }

            // Topic pages with several articles (main Uzbek address, like the articles).
            if (\Illuminate\Support\Facades\Schema::hasTable('topics')) {
                foreach (\App\Models\Topic::where('articles_count', '>=', \App\Models\Topic::INDEX_MIN)->orderByDesc('articles_count')->get(['slug', 'updated_at']) as $topic) {
                    $urls[] = ['loc' => Locale::route('mavzu', $topic->slug, 'uz'), 'alternates' => [], 'modified' => $topic->updated_at, 'priority' => '0.6'];
                }
            }

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
            foreach ($urls as $u) {
                $out .= '<url><loc>'.e($u['loc']).'</loc>';
                if ($u['modified']) {
                    $out .= '<lastmod>'.$u['modified']->format('Y-m-d').'</lastmod>';
                }
                foreach ($u['alternates'] as $lang => $href) {
                    $out .= '<xhtml:link rel="alternate" hreflang="'.$lang.'" href="'.e($href).'"/>';
                }
                $out .= '<priority>'.$u['priority'].'</priority></url>'."\n";
            }

            return $out.'</urlset>'."\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
