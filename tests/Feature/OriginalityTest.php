<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\User;
use App\Services\Originality\AiTextAnalyzer;
use App\Services\Originality\OriginalityService;
use App\Services\Originality\PlagiarismIndex;
use App\Services\Originality\WebChecker;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OriginalityTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLISHED = 'Fargʻona vodiysida sugʻorish suvidan foydalanish samaradorligini oshirish masalalari koʻrib chiqildi. '
        .'Tomchilatib sugʻorish tizimi joriy etilgan xoʻjaliklarda suv sarfi oʻrtacha qirq foizga kamaygani aniqlandi. '
        .'Tuproq namligini oʻlchash uchun arzon sensorlar tarmogʻi yaratildi va maʼlumotlar har soatda serverga yuborildi. '
        .'Olingan natijalar fermerlarga sugʻorish jadvalini aniq rejalashtirish imkonini berdi va hosildorlik oshdi. ';

    private const ORIGINAL = 'Ushbu ishda Andijon viloyatidagi kichik korxonalarning raqamli toʻlov tizimlaridan foydalanish darajasi oʻrganildi. '
        .'Soʻrovnomada ikki yuz oltmish nafar tadbirkor ishtirok etdi va ularning aksariyati mobil ilovalarni afzal koʻrishini bildirdi. '
        .'Tadqiqot natijalari soliq maʼmuriyatchiligini soddalashtirish boʻyicha amaliy tavsiyalar ishlab chiqishga xizmat qiladi. ';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function publishArticle(): Article
    {
        Storage::disk('public')->put('pdfs/published.pdf', Pdf::loadHTML('<p>'.str_repeat(self::PUBLISHED, 3).'</p>')->output());
        $issue = Maqola::create(['volume' => '1', 'title' => 'Vol. 1', 'abstract' => '']);

        return Article::create(['volume' => (string) $issue->id, 'title' => 'Sugʻorish samaradorligi', 'author' => 'A', 'pdf' => 'pdfs/published.pdf']);
    }

    private function paperWithText(string $text, string $name): Paper
    {
        $word = new \PhpOffice\PhpWord\PhpWord();
        $word->addSection()->addText($text);
        $tmp = sys_get_temp_dir().'/'.uniqid('o', true).'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($tmp);
        Storage::disk('local')->put('papers/'.$name, file_get_contents($tmp));
        @unlink($tmp);

        return Paper::create(['title' => $name, 'authors' => 'B', 'locale' => 'uz', 'source' => 'web', 'file_name' => $name, 'file_path' => 'papers/'.$name]);
    }

    public function test_copied_text_is_detected_and_original_text_is_not(): void
    {
        $article = $this->publishArticle();
        $service = app(OriginalityService::class);

        $copied = $service->run($this->paperWithText(str_repeat(self::PUBLISHED, 3), 'copied.docx'), false);
        $this->assertSame('done', $copied->status);
        $this->assertGreaterThan(80, $copied->similarity);
        $this->assertSame('article', $copied->sources[0]['type']);
        $this->assertSame($article->id, $copied->sources[0]['ref_id']);
        $this->assertNotEmpty($copied->passages);

        $original = $service->run($this->paperWithText(str_repeat(self::ORIGINAL, 4), 'original.docx'), false);
        $this->assertSame('done', $original->status);
        $this->assertLessThan(5, $original->similarity);
    }

    public function test_a_second_submission_of_the_same_paper_is_flagged(): void
    {
        $service = app(OriginalityService::class);
        $first = $this->paperWithText(str_repeat(self::ORIGINAL, 4), 'first.docx');
        $service->run($first, false);

        $again = $service->run($this->paperWithText(str_repeat(self::ORIGINAL, 4), 'again.docx'), false);
        $this->assertGreaterThan(80, $again->similarity);
        $this->assertSame('paper', $again->sources[0]['type']);
        $this->assertSame($first->id, $again->sources[0]['ref_id']);
    }

    public function test_web_search_ignores_own_site_and_respects_the_monthly_cap(): void
    {
        config(['services.brave.key' => 'KEY', 'journal.web_check_sentences' => 3, 'journal.web_check_monthly_limit' => 2, 'app.url' => 'https://journal.emro.uz/public']);
        Http::fake(['api.search.brave.com/*' => Http::sequence()
            ->push(['web' => ['results' => [['title' => 'Copy elsewhere', 'url' => 'https://example.org/paper'], ['title' => 'Our own page', 'url' => 'https://journal.emro.uz/public/maqola/5']]]])
            ->push(['web' => ['results' => [['title' => 'Only us', 'url' => 'https://journal.emro.uz/public/maqola/6']]]])]);

        $sentences = 'Tadqiqot davomida Fargʻona vodiysidagi oltita fermer xoʻjaligida tuproq namligi har kuni ikki marta oʻlchab borildi va natijalar qayd etildi. '
            .'Sensorlar tarmogʻi orqali olingan maʼlumotlar har soatda markaziy serverga yuborilib, fermerlar uchun qulay hisobotlar shaklida taqdim etildi. '
            .'Tomchilatib sugʻorish usuli qoʻllanilgan maydonlarda suv sarfi anʼanaviy usulga nisbatan qariyb qirq foizga kamaygani aniqlandi. ';
        $web = app(WebChecker::class);
        $result = $web->check($sentences);

        $this->assertSame(2, $result['checked'], 'Stops at the monthly cap');
        $this->assertSame('monthly_limit', $result['note']);
        $this->assertSame(1, $result['found'], 'A match on our own site does not count');
        $this->assertSame('https://example.org/paper', $result['matches'][0]['results'][0]['url']);
        $this->assertCount(1, $result['matches'][0]['results']);
        $this->assertSame(2, $web->usedThisMonth());
        Http::assertSent(fn ($r) => $r->hasHeader('X-Subscription-Token', 'KEY') && str_starts_with($r['q'], '"'));
    }

    public function test_ai_indicator_separates_generated_style_and_needs_enough_text(): void
    {
        $ai = app(AiTextAnalyzer::class);
        $this->assertNull($ai->analyze('Qisqa matn.')['score']);

        $generated = str_repeat('Moreover, artificial intelligence plays a crucial role in modern education. It is important to note that digital tools foster collaboration. Furthermore, educators must navigate the complexities of this ever-evolving landscape. In conclusion, a holistic approach can pave the way for the future. ', 8);
        $human = 'We measured soil moisture twice a day on six farms. Readings drifted after rain, so the second sensor batch was recalibrated in June. '
            .'Water use fell. On the drip-irrigated plots the drop was about forty percent compared with furrow irrigation, although two farms saw almost no change because their pumps were oversized. '
            .'Farmers liked the hourly reports but asked for SMS alerts. Costs: each sensor node was about twelve dollars. '
            .'One question remains open—why did the yield of the second cotton variety not respond at all, even where the moisture stayed in the target band for the whole season? We do not know yet. ';
        $human = str_repeat($human, 4);

        $aiScore = $ai->analyze($generated)['score'];
        $humanScore = $ai->analyze($human)['score'];
        $this->assertGreaterThan(65, $aiScore);
        $this->assertLessThan(40, $humanScore);
        $this->assertGreaterThan($humanScore, $aiScore);
    }

    public function test_editor_sees_report_and_can_rerun_and_build_index(): void
    {
        $this->publishArticle();
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
        $paper = $this->paperWithText(str_repeat(self::PUBLISHED, 3), 'copied.docx');

        $this->actingAs($admin)->post('/panel/system/build-index')->assertSessionHas('success');
        $this->assertSame(1, app(PlagiarismIndex::class)->stats()['documents']);

        $this->actingAs($admin)->post(route('admin.papers.check', $paper))->assertSessionHas('success');
        $this->actingAs($admin)->get(route('admin.papers.show', $paper).'?lang=en')
            ->assertOk()->assertSee('Originality check')->assertSee('Sugʻorish samaradorligi')->assertSee('Matching passages');
        $this->actingAs($admin)->get(route('admin.papers.index').'?lang=en')->assertOk()->assertSee($paper->fresh()->check->similarity.'%');
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()->assertSee('Plagiarism index');
    }
}
