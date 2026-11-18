<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Author;
use App\Models\Maqola;
use App\Support\PageContent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Public journal pages. The language comes from the "locale" route middleware. */
class SiteController extends Controller
{
    public function home()
    {
        // Newest by publication date, not by when the record was added.
        $current = Maqola::latest('created_at')->latest('id')->first();

        return view('site.home', [
            'current' => $current,
            'currentArticles' => $current ? $current->articles()->orderBy('id')->limit(6)->get()->each->setRelation('issue', $current) : collect(),
            'latest' => Article::with('issue')->latest('id')->limit(6)->get(),
            'stats' => [
                'issues' => Maqola::count(),
                'articles' => Article::count(),
                'authors' => Author::count(),
            ],
            'about' => Str::limit(trim(html_entity_decode(strip_tags((string) PageContent::get('about')))), 420),
        ]);
    }

    public function archive()
    {
        return view('site.archive', [
            'issues' => Maqola::withCount('articles')->latest('created_at')->latest('id')->paginate(12),
        ]);
    }

    public function issue($id)
    {
        $issue = Maqola::findOrFail($id);

        return view('site.issue', [
            'issue' => $issue,
            // The issue is attached to each article (its date and volume), without one query per article.
            'articles' => $issue->articles()->orderBy('id')->get()->each->setRelation('issue', $issue),
        ]);
    }

    public function article($id)
    {
        $article = Article::with('issue')->findOrFail($id);
        $related = $article->issue
            ? $article->issue->articles()->whereKeyNot($article->id)->latest('id')->limit(4)->get()->each->setRelation('issue', $article->issue)
            : collect();

        return view('site.article', compact('article', 'related'));
    }

    public function author($name)
    {
        $raw = urldecode($name);
        $name = trim(preg_replace('/\s+/u', ' ', $raw));
        if ($name !== $raw && $name !== '') {
            // Old links with stray spaces (" Dr. Danish Ather") are one page, not duplicates.
            return redirect(lroute('maqolaauthor', $name), 301);
        }
        $articles = Article::with('issue')
            ->where('author', 'like', '%'.addcslashes($name, '%_\\').'%')
            ->latest('id')->paginate(10);

        $page = (int) request('page');

        return view('site.list', [
            'heading' => $name,
            'eyebrow' => __('Author'),
            'articles' => $articles,
            // Same list in every language: the Uzbek page is the main version.
            'canonical' => \App\Support\Locale::route('maqolaauthor', $name, 'uz').($page > 1 ? '?page='.$page : ''),
            // Empty results and one- or two-letter names are not worth indexing.
            'noindex' => $articles->total() === 0 || mb_strlen($name) < 3,
        ]);
    }

    /** "How to publish a paper" guide (a normal action: Route::view would add its own parameters to every link). */
    public function guide()
    {
        return view('site.guide');
    }

    /** A topic (an article keyword shared by several articles): the articles about it. */
    public function topic(string $slug)
    {
        $topic = \App\Models\Topic::where('slug', $slug)->first();
        if (! $topic) {
            // A keyword that is not (yet) a topic: show the search for it instead of an error.
            return redirect(lroute('maqolaqidiruv').'?search='.urlencode(str_replace('-', ' ', $slug)), 302);
        }
        $articles = $topic->articles()->with('issue')->latest('article.id')->paginate(20);
        $ids = $topic->articles()->pluck('article.id');
        // Topics that share articles with this one, the biggest first: links for readers and crawlers.
        $related = \App\Models\Topic::query()
            ->join('article_topic', 'article_topic.topic_id', '=', 'topics.id')
            ->whereIn('article_topic.article_id', $ids)->where('topics.id', '!=', $topic->id)
            ->where('topics.articles_count', '>=', 2)
            ->groupBy('topics.id', 'topics.slug', 'topics.name', 'topics.articles_count')
            ->selectRaw('topics.id, topics.slug, topics.name, topics.articles_count, COUNT(*) AS shared')
            ->orderByDesc('shared')->orderByDesc('topics.articles_count')->limit(18)->get();
        $page = (int) request('page');

        return view('site.topic', [
            'topic' => $topic,
            'articles' => $articles,
            'related' => $related,
            'canonical' => \App\Support\Locale::route('mavzu', $topic->slug, 'uz').($page > 1 ? '?page='.$page : ''),
            'noindex' => ! $topic->indexable(),
        ]);
    }

    /** All topics with several articles, the biggest first. */
    public function topics()
    {
        return view('site.topics', [
            'topics' => \App\Models\Topic::where('articles_count', '>=', \App\Models\Topic::INDEX_MIN)
                ->orderByDesc('articles_count')->orderBy('name')->get(['slug', 'name', 'articles_count']),
        ]);
    }

    public function search(Request $request)
    {
        $q = Str::limit(trim((string) $request->input('search')), 100, '');
        $articles = $q === ''
            ? Article::whereRaw('1 = 0')->paginate(10)
            : Article::search($q)->with('issue')->latest('id')->paginate(10)->withQueryString();

        return view('site.list', [
            'heading' => $q === '' ? __('Search') : '“'.$q.'”',
            'eyebrow' => __('Search results'),
            'articles' => $articles,
            'query' => $q,
            'noindex' => true, // search results are not pages to index
        ]);
    }

    public function about()
    {
        return $this->page('about');
    }

    public function aim()
    {
        return $this->page('aim');
    }

    public function team()
    {
        return $this->page('team');
    }

    public function instruction()
    {
        return $this->page('instruction');
    }

    public function plagiarism()
    {
        return $this->page('plagiarism');
    }

    public function submit()
    {
        return view('site.submit', [
            'guidelines' => PageContent::get('submission'),
        ]);
    }

    public function contact()
    {
        return view('site.contact');
    }

    private function page(string $key)
    {
        return view('site.page', [
            'key' => $key,
            'title' => __(PageContent::PAGES[$key]['title']),
            'html' => PageContent::get($key),
        ]);
    }
}
