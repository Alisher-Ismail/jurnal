<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthManager;
use App\Http\Controllers\VolumeController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\QidiruvController;
use Illuminate\Http\Request;
use Carbon\Carbon;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
//navbat
//navbat
//login and registration
Route::get('/login', [AuthManager::class, 'adminlogin'])->name('login');
Route::post('/adminlogin', [AuthManager::class, 'adminloginPost'])->name('adminlogin.post');
Route::get('/adminlogout', [AuthManager::class, 'adminlogout'])->name('adminlogout');
//login and registration
//
//Route::get('/qidiruv', [QidiruvController::class, 'qidiruv'])->name('qidiruv');
Route::get('/', [QidiruvController::class, 'qidiruv'])->name('qidiruv');
Route::get('/maqola/{id}', [QidiruvController::class, 'maqola'])->name('maqola');
//

Route::middleware('auth')->group(function () {
Route::get('/adminhome', function () { return view('admin.base'); })->name('adminhome'); 
//Route::get('/', function () {return view('admin.base');})->name('/');
//volume
Route::prefix('volume')->name('volume.')->group(function () {
    Route::get('/', [VolumeController::class, 'index'])->name('index');
    Route::post('/', [VolumeController::class, 'store'])->name('store');
    Route::delete('/', [VolumeController::class, 'destroy'])->name('delete');
    Route::get('/{id}/edit', [VolumeController::class, 'edit'])->name('edit');
    Route::put('/', [VolumeController::class, 'update'])->name('update');
});
//volume
Route::prefix('article')->name('article.')->group(function () {
    Route::get('/', [ArticleController::class, 'index'])->name('index');
    Route::post('/', [ArticleController::class, 'store'])->name('store');
    Route::delete('/', [ArticleController::class, 'destroy'])->name('delete');
    Route::get('/{id}/edit', [ArticleController::class, 'edit'])->name('edit');
    Route::post('/-update', [ArticleController::class, 'update'])->name('update');
});

});
