<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A DOC/DOCX manuscript must never be published as the article "PDF". */
class WordSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX = "PK\x03\x04\x14\x00\x06\x00word/document.xml fake docx";
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function paperWithFile(string $contents, string $name): Paper
    {
        Storage::disk('local')->put('papers/'.$name, $contents);

        return Paper::create(['title' => 'Arduino robot', 'authors' => 'A. Author', 'email' => 'a@example.com', 'locale' => 'uz',
            'source' => 'web', 'file_name' => $name, 'file_path' => 'papers/'.$name]);
    }

    private function admin(): User
    {
        return User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
    }

    private function realDocx(): string
    {
        $word = new \PhpOffice\PhpWord\PhpWord();
        $section = $word->addSection();
        $section->addText('ARDUINOGA ASOSLANGAN ROBOT — Олтинкўл', ['bold' => true]);
        $section->addText('Ushbu maqolada robot taklif etiladi.');
        $path = sys_get_temp_dir().'/'.uniqid('t', true).'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);
        $contents = file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    public function test_word_submission_is_converted_to_pdf_automatically(): void
    {
        config(['journal.word_converter' => 'phpword']);
        $issue = Maqola::create(['volume' => '1', 'title' => 'Issue', 'abstract' => '']);
        $paper = $this->paperWithFile($this->realDocx(), 'arduino.docx');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.papers.show', $paper).'?lang=en')->assertSee('converted to PDF automatically');
        $preview = $this->actingAs($admin)->get(route('admin.papers.preview', $paper))->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($preview->baseResponse->getFile()->getPathname()));

        $this->actingAs($admin)->post(route('admin.papers.accept', $paper), [
            'issue_id' => $issue->id, 'title' => 'Arduino robot', 'authors' => 'A. Author',
        ])->assertRedirect()->assertSessionHas('success');

        $article = Article::firstOrFail();
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get($article->pdf));
        $this->assertNotNull($article->pdfUrl());
    }

    public function test_unconvertible_word_file_requires_an_uploaded_pdf(): void
    {
        $issue = Maqola::create(['volume' => '1', 'title' => 'Issue', 'abstract' => '']);
        $paper = $this->paperWithFile(self::DOCX, 'broken.docx');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.papers.accept', $paper), [
            'issue_id' => $issue->id, 'title' => 'Arduino robot', 'authors' => 'A. Author',
        ])->assertSessionHas('error');
        $this->assertSame(0, Article::count());

        $this->actingAs($admin)->post(route('admin.papers.accept', $paper), [
            'issue_id' => $issue->id, 'title' => 'Arduino robot', 'authors' => 'A. Author',
            'pdf' => UploadedFile::fake()->createWithContent('final.pdf', self::PDF),
        ])->assertRedirect();
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get(Article::firstOrFail()->pdf));
    }

    public function test_article_holding_a_word_file_can_be_converted(): void
    {
        config(['journal.word_converter' => 'phpword']);
        Storage::disk('public')->put('pdfs/arduino.pdf', $this->realDocx());
        $article = Article::create(['volume' => '1', 'title' => 'Arduino', 'author' => 'A', 'pdf' => 'pdfs/arduino.pdf']);
        $this->assertSame('not_pdf', $article->pdfProblem());

        $this->actingAs($this->admin())->post(route('admin.articles.convert', $article))->assertSessionHas('success');

        $article->refresh();
        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get($article->pdf));
        $this->assertNull($article->pdfProblem());
    }

    public function test_pdf_submission_is_published_as_is(): void
    {
        $issue = Maqola::create(['volume' => '1', 'title' => 'Issue', 'abstract' => '']);
        $paper = $this->paperWithFile(self::PDF, 'paper.pdf');

        $this->actingAs($this->admin())->post(route('admin.papers.accept', $paper), [
            'issue_id' => $issue->id, 'title' => 'Arduino robot', 'authors' => 'A. Author',
        ])->assertRedirect();

        $this->assertStringStartsWith('%PDF', Storage::disk('public')->get(Article::firstOrFail()->pdf));
    }

    public function test_a_docx_stored_as_article_pdf_is_offered_as_docx_and_hidden_from_pages(): void
    {
        Storage::disk('public')->put('pdfs/arduino.pdf', self::DOCX);
        $article = Article::create(['volume' => '1', 'title' => 'Arduino', 'author' => 'A', 'pdf' => 'pdfs/arduino.pdf']);

        $this->assertNull($article->pdfUrl(), 'No PDF button for a Word file');
        $this->assertSame('not_pdf', $article->pdfProblem());

        $response = $this->get('/files/article/'.$article->id)->assertOk();
        $this->assertStringContainsString('arduino.docx', $response->headers->get('Content-Disposition'));

        $this->get('/maqolaeng/'.$article->id)->assertOk()->assertDontSee('/files/article/'.$article->id);
    }
}
