<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthManager;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\PaperController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TelegramWebhookController;
use App\Support\Locale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
| Every page exists in three languages. URLs keep their original shape so
| links already indexed by Google Scholar keep working:
|   /maqola/5 (uz)   /maqolaeng/5 (en)   /maqolaru/5 (ru)
*/
foreach (Locale::SUFFIXES as $locale => $sfx) {
    Route::middleware(["locale:$locale", 'visit'])->group(function () use ($sfx, $locale) {
        Route::get($sfx === '' ? '/' : "/$sfx", [SiteController::class, 'home'])->name("maqolabosh$sfx");
        Route::get("/mvolume$sfx", [SiteController::class, 'archive'])->name("mvolume$sfx");
        Route::get("/maqolavolume$sfx/{id}", [SiteController::class, 'issue'])->whereNumber('id')->name("maqolavolume$sfx");
        Route::get("/maqola$sfx/{id}", [SiteController::class, 'article'])->whereNumber('id')->name("maqola$sfx");
        Route::get("/maqolaauthor$sfx/{id}", [SiteController::class, 'author'])->name("maqolaauthor$sfx");
        Route::get("/maqolaqidiruv$sfx", [SiteController::class, 'search'])->name("maqolaqidiruv$sfx");
        Route::get("/mavzular$sfx", [SiteController::class, 'topics'])->name("mavzular$sfx");
        Route::get("/mavzu$sfx/{slug}", [SiteController::class, 'topic'])->where('slug', '[a-z0-9-]+')->name("mavzu$sfx");
        Route::get("/haqimiz$sfx", [SiteController::class, 'about'])->name("haqimiz$sfx");
        Route::get("/maqsadimiz$sfx", [SiteController::class, 'aim'])->name("maqsadimiz$sfx");
        Route::get("/jamoa$sfx", [SiteController::class, 'team'])->name("jamoa$sfx");
        Route::get("/yoriqnoma$sfx", [SiteController::class, 'instruction'])->name("yoriqnoma$sfx");
        Route::get("/kochirma$sfx", [SiteController::class, 'plagiarism'])->name("kochirma$sfx");
        Route::get("/submit$sfx", [SiteController::class, 'submit'])->name("submit$sfx");
        // "How to publish a paper" guide, at an address in each language's own search words.
        Route::get(['uz' => '/maqola-chop-etish', 'en' => '/publish-article', 'ru' => '/publikatsiya-statyi'][$locale], [SiteController::class, 'guide'])->name("guide$sfx");
        Route::get("/contact$sfx", [SiteController::class, 'contact'])->name("contact$sfx");
    });
}

Route::middleware('locale')->group(function () {
    Route::post('/papers', [PaperController::class, 'store'])->middleware('throttle:6,10')->name('papers.store');
    Route::get('/track', [PaperController::class, 'track'])->name('papers.track');
    Route::get('/papers/{paper}', [PaperController::class, 'show'])->name('papers.show');

    Route::get('/certificate/{certificate}', [CertificateController::class, 'show'])->name('certificate.show');
    Route::get('/certificate/{certificate}/pdf', [CertificateController::class, 'download'])->name('certificate.download');

    Route::get('/files/article/{id}', [FileController::class, 'article'])->whereNumber('id')->middleware([\App\Http\Middleware\AllowZenodo::class, 'visit:download'])->name('files.article');
    Route::get('/files/issue/{id}', [FileController::class, 'issue'])->whereNumber('id')->middleware('visit:download')->name('files.issue');
    // Old direct PDF links, rewritten here by public/.htaccess so they are stamped as well.
    Route::get('/storage/pdfs/{file}', [FileController::class, 'legacy'])->where('file', '.+')->middleware('visit:download')->name('files.legacy');

    Route::get('/login', [AuthManager::class, 'adminlogin'])->name('login');
    Route::post('/adminlogin', [AuthManager::class, 'adminloginPost'])->middleware('throttle:10,1')->name('adminlogin.post');
});

// For search engines and open-access aggregators (BASE, CORE, OpenAIRE).
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)->name('sitemap');
Route::match(['get', 'post'], '/oai', \App\Http\Controllers\OaiController::class)->middleware('throttle:120,1')->name('oai');
Route::get('/export/articles.json', [\App\Http\Controllers\ExportController::class, 'articles'])
    ->middleware(['throttle:60,1', \App\Http\Middleware\AllowZenodo::class])->name('export.articles');

Route::post('/telegram/webhook/{secret}', TelegramWebhookController::class)->name('telegram.webhook');
Route::get('/cron/{secret}', \App\Http\Controllers\CronController::class)->middleware('throttle:6,1')->name('cron');

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'locale'])->group(function () {
    Route::get('/adminlogout', [AuthManager::class, 'adminlogout'])->name('adminlogout');

    // Old dashboard URLs.
    Route::redirect('/adminhome', '/panel')->name('adminhome');
    Route::redirect('/adminhomeru', '/panel?lang=ru');
    Route::redirect('/adminhomeeng', '/panel?lang=en');

    Route::prefix('panel')->name('admin.')->group(function () {
        Route::get('/', Admin\DashboardController::class)->name('dashboard');
        Route::get('/visitors', Admin\VisitorsController::class)->name('visitors');

        Route::get('/papers', [Admin\PaperController::class, 'index'])->name('papers.index');
        Route::get('/papers/{paper}', [Admin\PaperController::class, 'show'])->name('papers.show');
        Route::get('/papers/{paper}/file', [Admin\PaperController::class, 'file'])->name('papers.file');
        Route::get('/papers/{paper}/preview', [Admin\PaperController::class, 'preview'])->name('papers.preview');
        Route::post('/papers/{paper}/check', [Admin\PaperController::class, 'check'])->name('papers.check');
        Route::post('/papers/{paper}/external', [Admin\PaperController::class, 'external'])->name('papers.external');
        Route::get('/papers/{paper}/external-report', [Admin\PaperController::class, 'externalReport'])->name('papers.external.report');
        Route::post('/papers/{paper}/review', [Admin\PaperController::class, 'review'])->name('papers.review');
        Route::put('/papers/{paper}/taqriz', [Admin\PaperController::class, 'saveReview'])->name('papers.taqriz');
        Route::post('/papers/{paper}/accept', [Admin\PaperController::class, 'accept'])->name('papers.accept');
        Route::post('/papers/{paper}/reject', [Admin\PaperController::class, 'reject'])->name('papers.reject');

        Route::get('/account', [Admin\AccountController::class, 'edit'])->name('account');
        Route::put('/account', [Admin\AccountController::class, 'update'])->name('account.update');

        // Editors (journal managers) run the whole journal: submissions, issues, articles, authors and pages.
        Route::post('/papers/{paper}/release', [Admin\PaperController::class, 'release'])->name('papers.release');
        Route::post('/papers/{paper}/resend', [Admin\PaperController::class, 'resend'])->name('papers.resend');
        Route::delete('/papers/{paper}', [Admin\PaperController::class, 'destroy'])->name('papers.destroy');

        Route::resource('issues', Admin\IssueController::class)->except('show')->parameters(['issues' => 'issue']);
        Route::post('/issues/{issue}/import-articles', [Admin\IssueController::class, 'importArticles'])->name('issues.import');
        Route::resource('articles', Admin\ArticleController::class)->except('show');
        Route::post('/articles/{article}/certificate', [Admin\ArticleController::class, 'certificate'])->name('articles.certificate');
        Route::post('/articles/{article}/convert', [Admin\ArticleController::class, 'convert'])->name('articles.convert');
        Route::post('/articles/{article}/zenodo', [Admin\ArticleController::class, 'zenodo'])->name('articles.zenodo');
        Route::post('/articles/{article}/channel', [Admin\ArticleController::class, 'channel'])->name('articles.channel');
        Route::post('/issues/{issue}/channel', [Admin\IssueController::class, 'channel'])->name('issues.channel');

        Route::get('/authors', [Admin\AuthorController::class, 'index'])->name('authors.index');
        Route::post('/authors', [Admin\AuthorController::class, 'store'])->name('authors.store');
        Route::put('/authors/{author}', [Admin\AuthorController::class, 'update'])->name('authors.update');
        Route::delete('/authors/{author}', [Admin\AuthorController::class, 'destroy'])->name('authors.destroy');

        Route::get('/pages', [Admin\PageController::class, 'index'])->name('pages.index');
        Route::get('/pages/{page}/{locale}', [Admin\PageController::class, 'edit'])->name('pages.edit');
        Route::put('/pages/{page}/{locale}', [Admin\PageController::class, 'update'])->name('pages.update');

        // Administrator only: payment confirmation, user accounts and the System (settings) page.
        Route::middleware('admin')->group(function () {
            Route::post('/papers/{paper}/payment', [Admin\PaperController::class, 'payment'])->name('papers.payment');

            Route::post('/users', [Admin\AccountController::class, 'store'])->name('users.store');
            Route::put('/users/{user}/password', [Admin\AccountController::class, 'password'])->name('users.password');
            Route::delete('/users/{user}', [Admin\AccountController::class, 'destroy'])->name('users.destroy');

            Route::get('/system', [Admin\SystemController::class, 'index'])->name('system');
            Route::post('/system/migrate', [Admin\SystemController::class, 'migrate'])->name('system.migrate');
            Route::post('/system/webhook', [Admin\SystemController::class, 'webhook'])->name('system.webhook');
            Route::delete('/system/webhook', [Admin\SystemController::class, 'webhookDelete'])->name('system.webhook.delete');
            Route::post('/system/files-to-telegram', [Admin\SystemController::class, 'filesToTelegram'])->name('system.files');
            Route::post('/system/clear-cache', [Admin\SystemController::class, 'clearCache'])->name('system.cache');
            Route::post('/system/build-index', [Admin\SystemController::class, 'buildIndex'])->name('system.index');
            Route::post('/system/harvest', [Admin\SystemController::class, 'harvestCorpus'])->name('system.harvest');
            Route::post('/system/test-mail', [Admin\SystemController::class, 'testMail'])->name('system.mail');
            Route::post('/system/channel-invite', [Admin\SystemController::class, 'channelInvite'])->name('system.channelInvite');
            Route::post('/system/abstracts', [Admin\SystemController::class, 'abstracts'])->name('system.abstracts');
            Route::post('/system/indexnow', [Admin\SystemController::class, 'indexNow'])->name('system.indexnow');
            Route::post('/system/zenodo-backlog', [Admin\SystemController::class, 'zenodoBacklog'])->name('system.zenodo.backlog');
            Route::post('/system/channel-autopost', [Admin\SystemController::class, 'channelAutopost'])->name('system.channel.autopost');
            Route::post('/system/test-telegram', [Admin\SystemController::class, 'testTelegram'])->name('system.telegram');
            Route::put('/system/telegram', [Admin\SystemController::class, 'telegramSave'])->name('system.telegram.save');
            Route::post('/system/telegram/chat', [Admin\SystemController::class, 'telegramUseChat'])->name('system.telegram.chat');
            Route::put('/system/journal', [Admin\SystemController::class, 'journalSave'])->name('system.journal');
            Route::put('/system/zenodo', [Admin\SystemController::class, 'zenodoSave'])->name('system.zenodo');
            Route::get('/system/certificate-preview/{type}', [Admin\SystemController::class, 'certificatePreview'])->name('system.certificate.preview');
        });
    });
});
