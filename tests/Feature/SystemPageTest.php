<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('old-password'), 'type' => 'admin']);
    }

    public function test_panel_works_before_the_database_update_and_the_button_fixes_it(): void
    {
        // Simulate: new code uploaded, "Run database update" not pressed yet.
        foreach (['paper_checks', 'plagiarism_fingerprints', 'plagiarism_documents'] as $table) {
            \Illuminate\Support\Facades\Schema::drop($table);
        }
        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', 'like', '2026_11_15_%')->delete();
        \App\Support\SchemaState::reset();
        Mail::fake();
        $admin = $this->admin();
        $paper = \App\Models\Paper::create(['title' => 'Old paper', 'authors' => 'A', 'locale' => 'uz', 'source' => 'web']);

        $this->actingAs($admin)->get('/panel?lang=en')->assertOk()->assertSee('A database update is needed');
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()->assertSee('2026_11_15_000001_create_originality_tables');
        $this->actingAs($admin)->get('/panel/papers')->assertOk();
        $this->actingAs($admin)->get(route('admin.papers.show', $paper))->assertOk();
        $this->get(route('papers.show', $paper))->assertOk();

        // A submission still gets its certificate (the check is skipped until the tables exist).
        config(['journal.certificate_after_check' => true]);
        $this->post('/papers', [
            'title' => 'New paper', 'authors' => 'B. Author', 'email' => 'b@example.com',
            'abstract' => str_repeat('An abstract long enough for validation. ', 3), 'agree' => '1',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('p.pdf', 50, 'application/pdf'),
        ])->assertRedirect();
        Mail::assertSent(\App\Mail\PaperUpdateMail::class, fn ($m) => $m->event === 'submitted');

        $this->actingAs($admin)->post('/panel/system/migrate')->assertSessionHas('success');
        $this->assertTrue(\App\Support\SchemaState::originalityReady());
        $this->actingAs($admin)->get('/panel?lang=en')->assertOk()->assertDontSee('A database update is needed');
    }

    public function test_unreadable_pdfs_show_their_reason_and_are_retried(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('pdfs/broken.pdf', "%PDF-1.4\nnot really a pdf");
        Maqola::create(['volume' => '1', 'title' => 'Broken volume', 'abstract' => '', 'pdf' => 'pdfs/broken.pdf']);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/panel/system/build-index')->assertSessionHas('success');
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()
            ->assertSee('Could not read')->assertSee('Broken volume')->assertSee('Server check');

        // Pressing again retries the failed document instead of skipping it forever.
        $this->actingAs($admin)->post('/panel/system/build-index')->assertSessionHas('success', fn ($m) => str_contains($m, '1'));

        // Live case: an earlier successful run left a word count behind, then a later run failed.
        \App\Models\PlagiarismDocument::query()->update(['words' => 5000, 'indexed_at' => null, 'error' => 'RuntimeException: boom']);
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertSee('Could not read')->assertSee('RuntimeException: boom');
        $this->actingAs($admin)->post('/panel/system/build-index')->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Indexed 1'));
    }

    public function test_system_page_reports_found_and_missing_pdfs(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('pdfs/present.pdf', '%PDF-1.4');
        $issue = Maqola::create(['volume' => '1', 'title' => 'Issue', 'abstract' => '']);
        Article::create(['volume' => (string) $issue->id, 'title' => 'Has file', 'author' => 'A', 'pdf' => 'pdfs/present.pdf']);
        Article::create(['volume' => (string) $issue->id, 'title' => 'Lost file', 'author' => 'B', 'pdf' => 'pdfs/gone.pdf']);

        $this->actingAs($this->admin())->get('/panel/system?lang=en')
            ->assertOk()
            ->assertSee('Has file')
            ->assertSee('Lost file')
            ->assertSee('1 missing');

        $this->get('/files/article/'.Article::where('title', 'Has file')->value('id'))->assertOk();
    }

    public function test_file_with_different_apostrophe_is_still_found(): void
    {
        $dir = public_path('storage/pdfs-apostrophe-test');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/2025-yil 9-yanvar konferensiya toʻplami.pdf', '%PDF-1.4 test');
        $issue = Maqola::create(['volume' => '10', 'title' => 'Konferensiya', 'abstract' => '', 'pdf' => "pdfs-apostrophe-test/2025-yil 9-yanvar konferensiya to'plami.pdf"]);

        try {
            $this->assertNotNull($issue->pdfUrl());
            $this->assertFalse(collect(app(\App\Services\FileVault::class)->report())->firstWhere('record', 'issue '.$issue->id)['missing']);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
        }
    }

    public function test_admin_changes_own_password_and_resets_others(): void
    {
        $admin = $this->admin();
        $other = User::forceCreate(['name' => 'Kamoldin', 'email' => 'Kamoldin', 'password' => Hash::make('leaked'), 'type' => 'admin']);

        $this->actingAs($admin)->put('/panel/account', [
            'name' => 'Admin', 'email' => 'admin', 'current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($admin)->put('/panel/account', [
            'name' => 'Admin', 'email' => 'admin', 'current_password' => 'old-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ])->assertSessionHas('success');
        $this->assertTrue(Hash::check('new-password-1', $admin->fresh()->password));

        $this->actingAs($admin)->put("/panel/users/{$other->id}/password", ['password' => 'fresh-password'])->assertSessionHas('success');
        $this->assertTrue(Hash::check('fresh-password', $other->fresh()->password));

        $this->actingAs($admin)->delete("/panel/users/{$admin->id}")->assertSessionHas('error');
    }

    public function test_setup_helpers_show_chat_ids_and_send_tests(): void
    {
        config(['services.telegram.token' => 'TEST', 'services.telegram.admin_chat_ids' => ['555'], 'services.telegram.storage_chat_id' => '-100777']);
        Http::fake(function ($request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['ok' => true, 'result' => match ($method) {
                'getMe' => ['username' => 'emro_bot'],
                'getWebhookInfo' => ['url' => ''],
                'getUpdates' => [
                    ['update_id' => 1, 'message' => ['chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Alisher']]],
                    ['update_id' => 2, 'channel_post' => ['chat' => ['id' => -100777, 'type' => 'channel', 'title' => 'Emro files']]],
                ],
                default => ['message_id' => 1],
            }]);
        });
        Mail::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()->assertSee('-100777')->assertSee('Emro files')->assertSee('555');

        $this->actingAs($admin)->post('/panel/system/test-telegram')->assertSessionHas('success');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && ($r->data()['chat_id'] ?? null) == '-100777');

        $this->actingAs($admin)->post('/panel/system/test-mail', ['email' => 'me@example.com'])->assertSessionHas('success');
    }

    public function test_migrate_button_and_webhook_button(): void
    {
        config(['services.telegram.token' => 'TEST', 'services.telegram.webhook_secret' => 'secret-123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/panel/system/migrate')->assertSessionHas('success');

        // The panel is opened over plain http in tests, so the webhook is refused (Telegram needs HTTPS).
        $this->actingAs($admin)->post('/panel/system/webhook')->assertSessionHas('error');
        $this->actingAs($admin)->post('https://localhost/panel/system/webhook')->assertSessionHas('output');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'setWebhook'));
    }
}
