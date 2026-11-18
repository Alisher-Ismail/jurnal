<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Author;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    public function index()
    {
        return view('admin.authors', ['authors' => Author::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        Author::firstOrCreate($request->validate(['name' => 'required|string|max:255']));

        return back()->with('success', __('Saved.'));
    }

    public function update(Request $request, Author $author)
    {
        $author->update($request->validate(['name' => 'required|string|max:255']));

        return back()->with('success', __('Saved.'));
    }

    public function destroy(Author $author)
    {
        $author->delete();

        return back()->with('success', __('Deleted.'));
    }
}
