<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\User;
use App\Services\SpaceSaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Files live in Telegram; server copies are deleted only after Telegram's copy is confirmed. */
class SpaceSaverTest extends TestCase
{
    use RefreshDatabase;

    /** file_id => size Telegram reports */
    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => '123456:'.str_repeat('a', 30), 'services.telegram.storage_chat_id' => '-1001234567890']);
        Storage::fake('public');
        Storage::fake('local');

        Http::fake(function (Request $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($method === 'sendDocument') {
                $id = 'TG-'.(count($this->remote) + 1);
                $this->remote[$id] = strlen((string) collect($request->data())->firstWhere('name', 'document')['contents'] ?? '');

                return Http::response(['ok' => true, 'result' => ['message_id' => 9, 'document' => ['file_id' => $id]]]);
            }

            return Http::response(['ok' => true, 'result' => match ($method) {
                'getFile' => ['file_path' => 'documents/x.pdf', 'file_size' => $this->remote[$request['file_id']] ?? 0],
                default => true,
            }]);
        });
    }

    private function pdf(string $path, int $bytes = 2000): void
    {
        Storage::disk('public')->put($path, '%PDF-1.4'.str_repeat('x', $bytes));
    }

    public function test_files_move_to_telegram_and_old_links_keep_working(): void
    {
        $this->pdf('pdfs/issue.pdf');
        $this->pdf('pdfs/shared.pdf', 3000);
        $this->pdf('pdfs/orphan-of-a-deleted-article.pdf');
        Storage::disk('public')->put('pdfs/word.pdf', "PK\x03\x04 a docx named .pdf");
        Storage::disk('local')->put('papers/m.docx', "PK\x03\x04 manuscript");

        $issue = Maqola::create(['volume' => '1', 'title' => 'Issue one', 'abstract' => '', 'pdf' => 'pdfs/issue.pdf']);
        $a = Article::create(['volume' => (string) $issue->id, 'title' => 'First', 'author' => 'A', 'pdf' => 'pdfs/shared.pdf']);
        $b = Article::create(['volume' => (string) $issue->id, 'title' => 'Second', 'author' => 'B', 'pdf' => 'pdfs/shared.pdf']);
        $word = Article::create(['volume' => (string) $issue->id, 'title' => 'Word', 'author' => 'C', 'pdf' => 'pdfs/word.pdf']);
        $paper = Paper::create(['title' => 'Manuscript', 'authors' => 'D', 'locale' => 'uz', 'source' => 'web', 'file_path' => 'papers/m.docx', 'file_name' => 'm.docx']);

        $report = app(SpaceSaver::class)->run();

        $this->assertSame(3, $report['moved'], json_encode($report));
        Storage::disk('public')->assertMissing('pdfs/issue.pdf');
        Storage::disk('public')->assertMissing('pdfs/shared.pdf');
        Storage::disk('local')->assertMissing('papers/m.docx');
        Storage::disk('public')->assertExists('pdfs/orphan-of-a-deleted-article.pdf'); // no record: never touched
        Storage::disk('public')->assertExists('pdfs/word.pdf'); // not a real PDF: an editor must fix it first

        $this->assertNotNull($issue->fresh()->pdf_file_id);
        $this->assertSame($a->fresh()->pdf_file_id, $b->fresh()->pdf_file_id, 'Both records keep the shared file');
        $this->assertNotNull($paper->fresh()->file_id);
        $this->assertNull($word->fresh()->pdf_file_id);

        // Old Scholar links still lead to the file.
        $this->get('/storage/pdfs/shared.pdf')->assertRedirect(route('files.article', $a->id));
    }

    public function test_server_copy_is_kept_when_telegram_has_a_different_file(): void
    {
        $this->pdf('pdfs/issue.pdf');
        $issue = Maqola::create(['volume' => '1', 'title' => 'Issue', 'abstract' => '', 'pdf' => 'pdfs/issue.pdf', 'pdf_file_id' => 'OLD']);
        $this->remote['OLD'] = 5; // Telegram's copy is not this file

        $report = app(SpaceSaver::class)->run();

        $this->assertSame(0, $report['moved']);
        $this->assertCount(1, $report['failed']);
        Storage::disk('public')->assertExists('pdfs/issue.pdf');
        $this->assertSame('OLD', $issue->fresh()->pdf_file_id);
    }

    public function test_old_caches_and_leftover_packages_are_removed_and_the_page_shows_usage(): void
    {
        $old = storage_path('app/stamped/space-test-old.pdf');
        $new = storage_path('app/stamped/space-test-new.pdf');
        $zip = base_path('jurnal-space-test.zip');
        $other = base_path('site-backup-space-test.zip');
        @mkdir(dirname($old), 0775, true);
        file_put_contents($old, str_repeat('x', 1000));
        touch($old, now()->subDays(30)->getTimestamp());
        file_put_contents($new, 'x');
        file_put_contents($zip, 'zip');
        file_put_contents($other, 'zip');

        // A copy of the old theme folder, so the real public/admin is never touched by tests.
        $theme = storage_path('framework/testing/theme-'.uniqid());
        foreach (['plugins/jquery/jquery.js', 'dist/css/adminlte.css', 'dist/img/journalicon.png', 'dist/img/journal.png'] as $file) {
            @mkdir(dirname($theme.'/'.$file), 0775, true);
            file_put_contents($theme.'/'.$file, str_repeat('x', 100));
        }
        $saver = app(SpaceSaver::class);
        $saver->themeRoot = $theme;
        $this->app->instance(SpaceSaver::class, $saver);
        $this->assertSame([], $saver->unusedPackages(), 'A developer vendor/ (dev packages installed) is never pruned');

        try {
            $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
            $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()
                ->assertSee('Server space')->assertSee('Move files to Telegram (free server space)');

            $this->actingAs($admin)->post('/panel/system/files-to-telegram?lang=en')
                ->assertSessionHas('success', fn ($m) => str_contains($m, 'MB freed'))
                ->assertSessionHas('output', fn ($o) => str_contains($o, 'jurnal-space-test.zip'));

            $this->assertFileDoesNotExist($old);
            $this->assertFileExists($new, 'Recent cache entries keep downloads fast');
            $this->assertFileDoesNotExist($zip);
            $this->assertFileExists($other, 'Archives that are not update packages may be backups: kept');
            $this->assertDirectoryDoesNotExist($theme.'/plugins');
            $this->assertDirectoryDoesNotExist($theme.'/dist/css');
            $this->assertFileExists($theme.'/dist/img/journalicon.png', 'The site icon and logo stay');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($theme);
            @unlink($old);
            @unlink($new);
            @unlink($zip);
            @unlink($other);
        }
    }
}
