<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Maqola;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PdfStampTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = public_path('storage/pdfs-test');
        File::ensureDirectoryExists($this->dir);
        File::deleteDirectory(storage_path('app/stamped'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(storage_path('app/stamped'));
        parent::tearDown();
    }

    private function articleWithPdf(string $fileName): Article
    {
        $source = Pdf::loadHTML('<p>Original article text Олтинкўл oʻzbek</p><div style="page-break-after:always"></div><p>Second page</p>')->output();
        file_put_contents($this->dir.'/'.$fileName, $source);
        $issue = Maqola::create(['volume' => 'Vol. 1', 'title' => 'Vol. 1 No. 1 (2026)', 'abstract' => '']);

        return Article::create(['volume' => (string) $issue->id, 'title' => 'Stamped article', 'author' => 'A', 'pdf' => 'pdfs-test/'.$fileName]);
    }

    public function test_downloaded_pdf_has_journal_header_and_footer_on_every_page(): void
    {
        $article = $this->articleWithPdf('paper.pdf');

        $response = $this->get('/files/article/'.$article->id)->assertOk();
        $pdf = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2, preg_match_all('#/Type\s*/Page\b#', $pdf), 'Both pages kept');
        $this->assertStringContainsString(route('maqola', $article->id), $pdf, 'Footer links to the article');
        $this->assertNotSame(file_get_contents($this->dir.'/paper.pdf'), $pdf, 'Original is not served as is');
        $this->assertFileExists($this->dir.'/paper.pdf', 'Original file is untouched');

        // Served from cache the second time.
        $this->assertCount(1, File::files(storage_path('app/stamped')));
        $this->get('/files/article/'.$article->id)->assertOk();
        $this->assertCount(1, File::files(storage_path('app/stamped')));
    }

    public function test_old_direct_link_redirects_to_the_stamped_copy(): void
    {
        $article = Article::create(['volume' => '1', 'title' => 'Old', 'author' => 'A', 'pdf' => 'pdfs/Old name Олтинкўл.pdf']);

        $this->get('/storage/pdfs/Old name Олтинкўл.pdf')->assertRedirect(route('files.article', $article->id));
        $this->get('/storage/pdfs/unknown.pdf')->assertNotFound();
    }

    public function test_unreadable_pdf_falls_back_to_the_original(): void
    {
        file_put_contents($this->dir.'/broken.pdf', '%PDF-1.4 not really a pdf');
        $article = Article::create(['volume' => '1', 'title' => 'Broken', 'author' => 'A', 'pdf' => 'pdfs-test/broken.pdf']);

        $response = $this->get('/files/article/'.$article->id)->assertOk();
        $this->assertSame('%PDF-1.4 not really a pdf', $response->streamedContent());
    }
}
