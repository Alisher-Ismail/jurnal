<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Article;
use Carbon\Carbon;

class QidiruvController extends Controller
{
    public function qidiruv()
    {
        $article = Article::all();
        $articl = Article::orderBy('id', 'desc')->first();
        return view('client.qidiruv', compact('article', 'articl'));
    }

    public function maqola($id)
    {
        $article = Article::findorfail($id);
        // Now you can use the $vaqt value
        return view('client.maqola', compact('article'));
    }
    //navbat store
}
