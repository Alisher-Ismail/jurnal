<?php

namespace Tests\Feature;

use App\Mail\PaperUpdateMail;
use App\Models\Article;
use App\Models\Certificate;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\User;
use App\Services\Originality\AuthorshipChecker;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** "One researcher must not submit another researcher's paper." */
class AuthorshipTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = 'Dengiz va koʻllar koʻplab chiqindilar inqiroziga duch kelmoqda: jadal iqtisodiy rivojlanish va aholining tez suratlarda oʻsishi bunga sabab boʻlmoqda. '
        .'Ushbu maqolada Arduino mikrokontrolleridan foydalangan holda dengiz va koʻl uchun chiqindi yigʻuvchi robot taqdim etilgan. '
        .'Robot oʻn ikki voltli batareya bilan ishlaydi va harakati Arduino dasturlash orqali boshqariladi. '
        .'Robot masofadan turib mobil telefon orqali boshqarilishi yoki avtomatik ravishda ishlashi mumkin, aloqa uchun Wi-Fi moduli qoʻllanilgan. '
        .'Sinovlar shuni koʻrsatdiki, robot bir soatda oʻrtacha yetti kilogramm suzuvchi chiqindini yigʻa oladi. ';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        // These tests cover the submission certificate that follows the originality check, which is
        // only issued when publication certificates do not wait for payment (the default since 2026-11-17).
        config(['journal.certificate_after_check' => true, 'journal.certificate_after_payment' => false]);
    }

    private function pdf(string $html): string
    {
        return Pdf::loadHTML('<div style="font-family: DejaVu Sans">'.$html.'</div>')->output();
    }

    private function docx(string $text): UploadedFile
    {
        $word = new \PhpOffice\PhpWord\PhpWord();
        $word->addSection()->addText($text);
        $path = sys_get_temp_dir().'/'.uniqid('a', true).'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);

        return new UploadedFile($path, 'paper.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    private function submit(string $authors, string $email): Paper
    {
        $this->post('/papers', [
            'title' => 'Chiqindi yigʻuvchi robot', 'authors' => $authors, 'email' => $email,
            'abstract' => str_repeat('Robot chiqindilarni yigʻadi va masofadan boshqariladi. ', 3), 'agree' => '1', 'license' => '1',
            'file' => $this->docx(str_repeat(self::BODY, 4)),
        ])->assertRedirect();

        return Paper::latest('id')->firstOrFail();
    }

    private function publishArticle(): Article
    {
        Storage::disk('public')->put('pdfs/robot.pdf', $this->pdf(str_repeat(self::BODY, 4)));
        $issue = Maqola::create(['volume' => '1', 'title' => 'Vol. 1', 'abstract' => '']);

        return Article::create(['volume' => (string) $issue->id, 'title' => 'Dengiz chiqindisini yigʻuvchi robot',
            'author' => 'Ismoilov Alisher Shokirovich, Xasanov Jamshidbek Nasirdin oʻgʻli', 'pdf' => 'pdfs/robot.pdf']);
    }

    public function test_someone_else_submitting_a_published_paper_is_stopped(): void
    {
        $this->publishArticle();

        $paper = $this->submit('Karimov Bekzod', 'thief@example.com');
        $check = $paper->check;

        $this->assertSame('mismatch', $check->conflictType());
        $this->assertTrue($check->certificate_held);
        $this->assertFalse(Certificate::where('paper_id', $paper->id)->exists(), 'No certificate for a stolen paper');
        Mail::assertSent(PaperUpdateMail::class, fn ($m) => $m->event === 'received');
        Mail::assertSent(PaperUpdateMail::class, fn ($m) => $m->event === 'held');
        Mail::assertNotSent(PaperUpdateMail::class, fn ($m) => $m->event === 'submitted');

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
        $this->actingAs($admin)->get(route('admin.papers.show', $paper).'?lang=en')
            ->assertSee('Authorship conflict')->assertSee('Ismoilov Alisher')->assertSee('Karimov Bekzod')->assertSee('issue certificate');
        $this->actingAs($admin)->get(route('admin.papers.index').'?lang=en')->assertSee('Authorship');
        $this->get(route('papers.show', $paper).'?lang=en')->assertSee('needs an additional check');

        // The editor verifies and decides to release it anyway.
        $this->actingAs($admin)->post(route('admin.papers.release', $paper))->assertSessionHas('success');
        $this->assertTrue(Certificate::where('paper_id', $paper->id)->where('type', 'submission')->exists());
        $this->assertFalse($paper->check->fresh()->certificate_held);
        Mail::assertSent(PaperUpdateMail::class, fn ($m) => $m->event === 'submitted' && $m->certificatePdf);
    }

    public function test_the_real_author_resubmitting_gets_the_certificate(): void
    {
        $this->publishArticle();

        // Same author, different spelling of the surname (Ismoilov / Ismailov) and order of names.
        $paper = $this->submit('Alisher Ismailov', 'alisher@example.com');

        $this->assertSame('same_authors', $paper->check->conflictType());
        $this->assertFalse($paper->check->certificate_held);
        $this->assertTrue(Certificate::where('paper_id', $paper->id)->where('type', 'submission')->exists());
        Mail::assertSent(PaperUpdateMail::class, fn ($m) => $m->event === 'submitted' && $m->certificatePdf);
    }

    public function test_original_paper_gets_its_certificate_after_the_check(): void
    {
        $this->publishArticle();
        $this->post('/papers', [
            'title' => 'Boshqa mavzu', 'authors' => 'Karimov Bekzod', 'email' => 'b@example.com',
            'abstract' => str_repeat('Kichik korxonalarda raqamli toʻlovlar oʻrganildi. ', 3), 'agree' => '1', 'license' => '1',
            'file' => $this->docx(str_repeat('Andijon viloyatidagi kichik korxonalarning raqamli toʻlov tizimlaridan foydalanish darajasi soʻrovnoma asosida oʻrganildi va tahlil qilindi. ', 6)),
        ]);
        $paper = Paper::latest('id')->first();

        $this->assertNull($paper->check->conflictType());
        $this->assertTrue(Certificate::where('paper_id', $paper->id)->exists());
    }

    public function test_proceedings_volume_names_are_read_from_above_the_copied_article(): void
    {
        $volume = '<p>'.str_repeat('Oldingi maqola matni, iqtisodiyot va moliya masalalari boʻyicha tahlil. ', 30).'</p>'
            .'<h3>DENGIZ CHIQINDISINI YIGʻUVCHI ROBOT</h3><p>Ismoilov Alisher Shokirovich<br>Andijon davlat universiteti</p>'
            .'<p>'.str_repeat(self::BODY, 4).'</p>';
        Storage::disk('public')->put('pdfs/volume.pdf', $this->pdf($volume));
        Maqola::create(['volume' => '5', 'title' => 'Konferensiya toʻplami 2025', 'abstract' => '', 'pdf' => 'pdfs/volume.pdf']);

        $thief = $this->submit('Karimov Bekzod', 'thief@example.com');
        $this->assertSame('mismatch', $thief->check->conflictType());
        $this->assertStringContainsString('Ismoilov', $thief->check->conflict['items'][0]['context']);

        // The real author writes his name in Cyrillic.
        $author = $this->submit('Исмоилов Алишер', 'alisher@example.com');
        $this->assertNotSame('mismatch', $author->check->conflictType());
    }

    public function test_surnames_are_extracted_across_scripts_and_formats(): void
    {
        $checker = app(AuthorshipChecker::class);
        $this->assertSame(['ismoilov', 'xasanov'], $checker->surnames('Ismoilov Alisher Shokirovich, Xasanov Jamshidbek Nasirdin oʻgʻli'));
        $this->assertContains('ismoilov', $checker->surnames('А.Ш. Исмоилов'));
        $this->assertContains('karimova', $checker->surnames('N. Karimova va B. Toshmatov'));
    }

    public function test_editor_records_an_antiplag_uz_result(): void
    {
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
        $paper = Paper::create(['title' => 'T', 'authors' => 'A', 'locale' => 'uz', 'source' => 'web']);

        $this->actingAs($admin)->post(route('admin.papers.external', $paper), [
            'external_service' => 'antiplag.uz', 'external_similarity' => '12.5', 'external_ai' => '4',
            'external_note' => 'Original', 'report' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 report'),
        ])->assertSessionHas('success');

        $check = $paper->check()->first();
        $this->assertSame(12.5, $check->external_similarity);
        Storage::disk('local')->assertExists($check->external_report);
        $this->actingAs($admin)->get(route('admin.papers.show', $paper).'?lang=en')->assertSee('antiplag.uz')->assertSee('12.5%');
        $this->actingAs($admin)->get(route('admin.papers.external.report', $paper))->assertOk();
    }
}
