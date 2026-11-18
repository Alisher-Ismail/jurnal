<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Locale;
use App\Support\PageContent;
use Illuminate\Http\Request;

/** Rich-text pages (about, aims, team, guidelines...) in all three languages. */
class PageController extends Controller
{
    public function index()
    {
        $status = [];
        foreach (array_keys(PageContent::PAGES) as $key) {
            foreach (Locale::all() as $locale) {
                $status[$key][$locale] = filled(trim(strip_tags((string) PageContent::raw($key, $locale))));
            }
        }

        return view('admin.pages.index', compact('status'));
    }

    public function edit(string $page, string $locale)
    {
        abort_unless(PageContent::exists($page) && in_array($locale, Locale::all(), true), 404);

        return view('admin.pages.edit', [
            'page' => $page,
            'locale' => $locale,
            'html' => PageContent::raw($page, $locale),
        ]);
    }

    public function update(Request $request, string $page, string $locale)
    {
        abort_unless(PageContent::exists($page) && in_array($locale, Locale::all(), true), 404);
        $data = $request->validate(['html' => 'nullable|string']);

        PageContent::put($page, $locale, (string) ($data['html'] ?? ''));

        return back()->with('success', __('Saved.'));
    }
}
