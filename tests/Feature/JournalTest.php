<?php

namespace Tests\Feature;

use App\Mail\PaperUpdateMail;
use App\Models\Article;
use App\Models\Certificate;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalTest extends TestCase
{
    use RefreshDatabase;

    private function issueWithArticle(): array
    {
        $issue = Maqola::create(['volume' => 'Vol. 1', 'title' => 'Vol. 1 No. 1 (2026)', 'abstract' => 'First issue']);
        $article = Article::create([
            'volume' => (string) $issue->id, 'title' => 'Robots that collect sea waste',
            'author' => 'Ismoilov Alisher, Xasanov Jamshidbek', 'keywords' => 'arduino, robots', 'abstract' => 'Abstract text',
        ]);

        return [$issue, $article];
    }

    public function test_public_pages_render_in_all_languages(): void
    {
        [$issue, $article] = $this->issueWithArticle();

        foreach (['', 'eng', 'ru'] as $sfx) {
            $this->get($sfx === '' ? '/' : "/$sfx")->assertOk();
            foreach (["/mvolume$sfx", "/maqolavolume$sfx/{$issue->id}", "/maqola$sfx/{$article->id}", "/haqimiz$sfx",
                "/maqsadimiz$sfx", "/jamoa$sfx", "/yoriqnoma$sfx", "/kochirma$sfx", "/submit$sfx", "/contact$sfx",
                "/maqolaqidiruv$sfx?search=robot", "/maqolaauthor$sfx/Xasanov%20Jamshidbek"] as $url) {
                $this->get($url)->assertOk();
            }
        }

        $this->get("/maqolaru/{$article->id}")->assertSee('Аннотация', false)->assertSee('citation_author', false);
        $this->get('/maqola/999')->assertNotFound();
    }

    public function test_pages_tolerate_incomplete_legacy_rows(): void
    {
        $issue = Maqola::create(['volume' => '7', 'title' => 'Conference proceedings', 'abstract' => '']);
        \Illuminate\Support\Facades\DB::table('article')->insert([
            ['volume' => (string) $issue->id, 'title' => 'Legacy one', 'author' => ' E.Muydinov,  R. Bekmirzaev ', 'keywords' => null, 'abstract' => null, 'pdf' => 'pdfs/missing.pdf', 'created_at' => null, 'updated_at' => null],
            ['volume' => '999', 'title' => 'Orphan article', 'author' => null, 'keywords' => null, 'abstract' => null, 'pdf' => null, 'created_at' => null, 'updated_at' => null],
        ]);

        foreach (['', 'eng', 'ru'] as $sfx) {
            $this->get($sfx === '' ? '/' : "/$sfx")->assertOk();
            $this->get("/mvolume$sfx")->assertOk();
            $this->get("/maqolavolume$sfx/{$issue->id}")->assertOk()->assertSee('Legacy one');
            foreach (Article::pluck('id') as $id) {
                $this->get("/maqola$sfx/$id")->assertOk();
            }
        }
        // Old author links had a leading space (%20ELMIRA...).
        $this->get('/maqolaauthoreng/%20R.%20Bekmirzaev')->assertOk()->assertSee('Legacy one');
        $this->get('/files/article/'.Article::where('title', 'Legacy one')->value('id'))->assertNotFound();
    }

    public function test_search_finds_articles(): void
    {
        $this->issueWithArticle();

        $this->get('/maqolaqidiruveng?search=sea waste')->assertSee('Robots that collect sea waste');
        $this->get('/maqolaqidiruveng?search=nothing-like-this')->assertSee('Nothing found');
    }

    public function test_web_submission_creates_paper_and_sends_certificate(): void
    {
        Mail::fake();
        Storage::fake('local');

        $response = $this->post('/papers', [
            'title' => 'Machine learning for water quality',
            'authors' => 'Aliyev Vali, Karimova Nodira',
            'email' => 'author@example.com',
            'abstract' => str_repeat('A careful study of irrigation water quality. ', 3),
            'agree' => '1',
            'file' => UploadedFile::fake()->create('paper.pdf', 200, 'application/pdf'),
        ]);

        $paper = Paper::firstOrFail();
        $response->assertRedirect(route('papers.show', $paper));
        $this->assertSame('web', $paper->source);
        $this->assertSame(Paper::SUBMITTED, $paper->status);
        Storage::disk('local')->assertExists($paper->file_path);
        $this->assertDatabaseHas('certificates', ['paper_id' => $paper->id, 'type' => Certificate::SUBMISSION]);

        Mail::assertSent(PaperUpdateMail::class, fn ($mail) => $mail->hasTo('author@example.com')
            && $mail->event === 'submitted' && $mail->certificatePdf !== null);

        $this->get(route('papers.show', $paper))->assertOk()->assertSee($paper->code);
        $this->get('/track?code='.strtolower($paper->code))->assertRedirect(route('papers.show', $paper));
    }

    public function test_web_submission_validates_input(): void
    {
        $this->post('/papers', ['title' => 'x'])->assertSessionHasErrors(['title', 'authors', 'email', 'abstract', 'file', 'agree']);
        $this->assertSame(0, Paper::count());
    }

    public function test_certificate_pdf_and_verification_page(): void
    {
        [, $article] = $this->issueWithArticle();
        $certificate = app(\App\Services\CertificateService::class)->forArticle($article, 'uz');

        $this->get(route('certificate.show', $certificate))->assertOk()->assertSee($certificate->code);
        $pdf = $this->get(route('certificate.download', $certificate));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Issuing again returns the same certificate instead of a duplicate.
        $this->assertTrue($certificate->is(app(\App\Services\CertificateService::class)->forArticle($article, 'en')));
    }

    public function test_certificate_shows_the_indexing_entered_in_the_panel(): void
    {
        [, $article] = $this->issueWithArticle();
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
        $html = fn () => view('certificates.certificate', [
            'certificate' => app(\App\Services\CertificateService::class)->forArticle($article, 'en'), 'qr' => '', 'issue' => $article->issue,
            'locale' => 'en', 't' => fn ($k, $r = []) => __($k, $r, 'en'),
        ])->render();

        $this->assertStringNotContainsString('INDEXED IN', $html(), 'Nothing is claimed until editors enter it');

        $this->actingAs($admin)->put('/panel/system/journal', [
            'indexing' => "Google Scholar\n - OpenAlex\n\nGoogle Scholar", 'issn' => '3030-3478', 'editor_name' => 'B. Karimov',
        ])->assertRedirect();
        $this->assertSame(['Google Scholar', 'OpenAlex'], \App\Support\JournalProfile::get()['indexing']);

        $rendered = $html();
        $this->assertStringContainsString('INDEXED IN', $rendered);
        $this->assertStringContainsString('OpenAlex', $rendered);
        $this->assertStringContainsString('ISSN 3030-3478', $rendered);
        $this->assertStringContainsString('B. Karimov', $rendered);

        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()->assertSee('OpenAlex')->assertSee('Preview');
        $preview = $this->actingAs($admin)->get('/panel/system/certificate-preview/publication');
        $preview->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(0, Certificate::count() - 1, 'The preview saves nothing');
    }

    public function test_admin_can_accept_and_reject_papers(): void
    {
        Mail::fake();
        [$issue] = $this->issueWithArticle();
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'a@example.com', 'password' => Hash::make('secret123'), 'type' => 'admin']);
        $paper = Paper::create(['title' => 'A paper', 'authors' => 'Author One', 'email' => 'one@example.com', 'locale' => 'en', 'source' => 'web']);
        $other = Paper::create(['title' => 'Weak paper', 'authors' => 'Author Two', 'email' => 'two@example.com', 'locale' => 'ru', 'source' => 'web']);

        $this->get('/panel')->assertRedirect('/login');

        $this->actingAs($admin)->get('/panel')->assertOk();
        $this->actingAs($admin)->get(route('admin.papers.show', $paper))->assertOk();

        $this->actingAs($admin)->post(route('admin.papers.accept', $paper), [
            'issue_id' => $issue->id, 'title' => 'A paper (final)', 'authors' => 'Author One, Author Three',
        ])->assertRedirect(route('admin.papers.show', $paper));

        $paper->refresh();
        $this->assertSame(Paper::ACCEPTED, $paper->status);
        $this->assertSame('A paper (final)', $paper->article->title);
        $this->assertSame((string) $issue->id, (string) $paper->article->volume);
        $this->assertDatabaseHas('author', ['name' => 'Author Three']);
        $this->assertDatabaseHas('certificates', ['article_id' => $paper->article_id, 'type' => Certificate::PUBLICATION]);
        Mail::assertSent(PaperUpdateMail::class, fn ($m) => $m->event === 'accepted' && $m->hasTo('one@example.com'));

        $this->actingAs($admin)->post(route('admin.papers.reject', $other), ['note' => 'Out of scope'])->assertRedirect();
        $this->assertSame(Paper::REJECTED, $other->fresh()->status);
        Mail::assertSent(PaperUpdateMail::class, fn ($m) => $m->event === 'rejected' && $m->hasTo('two@example.com'));

        // A decided paper cannot be accepted again.
        $this->actingAs($admin)->post(route('admin.papers.accept', $other), [
            'issue_id' => $issue->id, 'title' => 'x', 'authors' => 'y',
        ])->assertSessionHas('error');
    }

    public function test_admin_can_edit_page_content_per_language(): void
    {
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'a@example.com', 'password' => Hash::make('secret123'), 'type' => 'admin']);

        $this->actingAs($admin)->put(route('admin.pages.update', ['about', 'ru']), ['html' => '<p>О журнале — тест</p>'])->assertRedirect();

        $this->get('/haqimizru')->assertSee('О журнале — тест', false);
    }

    public function test_admin_can_issue_certificate_for_existing_article(): void
    {
        [, $article] = $this->issueWithArticle();
        Mail::fake();
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'a@example.com', 'password' => Hash::make('secret123'), 'type' => 'admin']);

        $this->actingAs($admin)->post(route('admin.articles.certificate', $article), ['locale' => 'en', 'email' => 'old@example.com'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('certificates', ['article_id' => $article->id, 'type' => Certificate::PUBLICATION]);
        Mail::assertSent(\App\Mail\ArticleCertificateMail::class, fn ($m) => $m->hasTo('old@example.com'));
    }
}
