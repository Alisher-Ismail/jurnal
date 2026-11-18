<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Certificate;
use App\Models\Maqola;
use App\Models\Paper;
use App\Services\FileVault;

class DashboardController extends Controller
{
    public function __invoke(FileVault $vault)
    {
        return view('admin.dashboard', [
            'counts' => [
                'pending' => Paper::whereIn('status', [Paper::SUBMITTED, Paper::UNDER_REVIEW])->count(),
                'papers' => Paper::count(),
                'articles' => Article::count(),
                'issues' => Maqola::count(),
                'certificates' => Certificate::count(),
            ],
            'recent' => Paper::latest()->limit(8)->get(),
            'telegram' => [
                'bot' => filled(config('services.telegram.token')),
                'storage' => $vault->usesTelegram(),
                'admins' => count(config('services.telegram.admin_chat_ids', [])),
                'username' => config('services.telegram.username'),
            ],
        ]);
    }
}
