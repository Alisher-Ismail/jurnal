<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Article;
use Illuminate\Support\Facades\Storage;

class ArticleController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $article = Article::all();
        return view('admin.article', compact('article'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Validate the request data
        $request->validate([
            'volume' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'keyword' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'abstract' => 'required|string',
            'pdf' => 'required|file|mimes:pdf|max:10048',
        ]);

        // Handle file upload
        if ($request->hasFile('pdf')) {
            $pdfPath = $request->file('pdf')->store('pdfs', 'public');
        }

        // Store the data in the database
        $article = new Article([
            'volume' => $request->input('volume'),
            'title' => $request->input('title'),
            'keywords' => $request->input('keyword'),
            'author' => $request->input('author'),
            'abstract' => $request->input('abstract'),
            'pdf' => $pdfPath
        ]);
        $article->save();

        // Redirect or return response
        return response()->json('Muvaffaqiyatli Saqlandi!', 200);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $article = Article::all();
        $articl = Article::find($id);
        return view('admin.article_edit', compact('article', 'articl'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {      
       // Validate the request data
       $request->validate([
        'vid' => 'required|integer',
        'volume' => 'required|string|max:255',
        'title' => 'required|string|max:255',
        'keyword' => 'required|string|max:255',
        'author' => 'required|string|max:255',
        'abstract' => 'required|string',
    ]);

    $aid = $request->vid;
    $article = Article::find($aid);
    // Handle file upload
    if ($request->hasFile('pdf')) {
        Storage::disk('public')->delete($article->pdf);
        $pdfPath = $request->file('pdf')->store('pdfs', 'public');
        $article->pdf = $pdfPath; 
    }

    $article->volume = $request->input('volume');
    $article->title = $request->input('title');
    $article->keywords = $request->input('keyword');
    $article->author = $request->input('author');
    $article->abstract = $request->input('abstract');
    // Store the data in the database
    
    $article->save();

    // Redirect or return response
    return response()->json('Muvaffaqiyatli Saqlandi!', 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
{
    $selectedIds = $request->input('selectedIds', []);

    if (empty($selectedIds)) {
        return redirect()->back()->with('error', 'Tanlangan elementlar topilmadi.');
    }

    try {
        // Fetch the articles to be deleted
        $articles = Article::whereIn('id', $selectedIds)->get();

        foreach ($articles as $article) {
            // Delete the PDF file associated with each article
            if ($article->pdf) {
                Storage::disk('public')->delete($article->pdf);
            }
        }

        // Delete the articles from the database
        Article::whereIn('id', $selectedIds)->delete();

        return response()->json('O`chirildi.');
    } catch (\Exception $e) {
        return response()->json($e->getMessage(), 500); // Handle any errors
    }
}

}
