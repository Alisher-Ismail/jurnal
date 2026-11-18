<?php

namespace Tests\Feature;

use App\Models\ExternalWork;
use App\Models\Paper;
use App\Models\PlagiarismDocument;
use App\Models\User;
use App\Services\Originality\CorpusHarvester;
use App\Services\Originality\OriginalityService;
use App\Services\Originality\PlagiarismIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Published Uzbek research harvested from OpenAlex and used to catch submissions of other people's papers. */
class CorpusTest extends TestCase
{
    use RefreshDatabase;

    private const ABSTRACT = 'Ushbu maqolada Fargʻona vodiysida sugʻorish suvidan foydalanish samaradorligini oshirish masalalari koʻrib chiqilgan. '
        .'Tomchilatib sugʻorish tizimi joriy etilgan xoʻjaliklarda suv sarfi oʻrtacha qirq foizga kamaygani aniqlandi va hosildorlik oshdi. '
        .'Tuproq namligini oʻlchash uchun arzon sensorlar tarmogʻi yaratildi, maʼlumotlar har soatda serverga yuborildi va tahlil qilindi. '
        .'Natijalar fermerlarga sugʻorish jadvalini aniq rejalashtirish imkonini berdi hamda suv resurslarini tejashga xizmat qildi.';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['journal.certificate_after_check' => true, 'journal.certificate_after_payment' => false, 'journal.corpus.sampling' => 2, 'journal.corpus.from_year' => 2026]);
    }

    private function inverted(string $text): array
    {
        $index = [];
        foreach (preg_split('/\s+/u', $text) as $i => $word) {
            $index[$word][] = $i;
        }

        return $index;
    }

    private function fakeOpenAlex(): void
    {
        $work = [
            'id' => 'https://openalex.org/W123456',
            'doi' => 'https://doi.org/10.1234/test.1',
            'title' => 'Fargʻona vodiysida sugʻorish samaradorligi',
            'publication_year' => 2023,
            'authorships' => [['author' => ['display_name' => 'Alisher Ismoilov']], ['author' => ['display_name' => 'Nodira Karimova']]],
            'primary_location' => ['landing_page_url' => 'https://example-journal.uz/article/77', 'source' => ['display_name' => 'Agro ilm']],
            'best_oa_location' => ['pdf_url' => 'https://example-journal.uz/article/77.pdf'],
            'abstract_inverted_index' => $this->inverted(self::ABSTRACT),
        ];
        Http::fake(function (Request $request) use ($work) {
            $affiliationPass = str_contains($request['filter'], 'institutions.country_code:uz,');
            // The work sits in the first weekly window of the affiliation pass.
            $hit = $affiliationPass && str_contains($request['filter'], 'from_publication_date:2026-01-01,to_publication_date:2026-01-07');

            return Http::response([
                'meta' => ['count' => ($hit || $request['per-page'] == 1) && $affiliationPass ? 1 : 0],
                'results' => $hit ? [$work] : [],
            ]);
        });
    }

    private function paper(string $authors): Paper
    {
        $word = new \PhpOffice\PhpWord\PhpWord();
        $word->addSection()->addText(self::ABSTRACT.' Qoʻshimcha matn: tadqiqot natijalari jadvallarda keltirilgan va muhokama qilingan.');
        $tmp = sys_get_temp_dir().'/'.uniqid('c', true).'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($tmp);
        Storage::disk('local')->put('papers/'.basename($tmp), file_get_contents($tmp));
        @unlink($tmp);

        return Paper::create(['title' => 'Sugʻorish', 'authors' => $authors, 'email' => 'x@example.com', 'locale' => 'uz', 'source' => 'web',
            'file_name' => 'p.docx', 'file_path' => 'papers/'.basename($tmp)]);
    }

    public function test_harvest_stores_works_and_resumes_until_complete(): void
    {
        $this->fakeOpenAlex();
        $harvester = app(CorpusHarvester::class);

        $result = $harvester->harvest(30);
        $this->assertTrue($result['finished']);
        $this->assertSame(1, ExternalWork::count());
        $work = ExternalWork::first();
        $this->assertSame('W123456', $work->openalex_id);
        $this->assertSame('Alisher Ismoilov, Nodira Karimova', $work->authors);
        $this->assertSame('https://example-journal.uz/article/77', $work->link());
        $doc = PlagiarismDocument::where('type', 'external')->firstOrFail();
        $this->assertGreaterThan(5, $doc->fingerprints);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.openalex.org/works') && $r['per-page'] == 200 && $r['page'] == 1 && ! isset($r['cursor']));
        $this->assertSame(1, $harvester->stats()['expected']);

        // Done: no requests again within a day.
        Http::fake();
        $this->assertSame(0, $harvester->harvest(30)['pages']);
        Http::assertNothingSent();

        // The archive sync must never delete harvested works.
        app(PlagiarismIndex::class)->sync(5);
        $this->assertSame(1, PlagiarismDocument::where('type', 'external')->count());
    }

    public function test_submitting_a_published_uzbek_paper_by_someone_else_is_flagged(): void
    {
        $this->fakeOpenAlex();
        app(CorpusHarvester::class)->harvest(30);

        $thief = app(OriginalityService::class)->run($this->paper('Toshmatov Bekzod'), false);
        $this->assertSame('mismatch', $thief->conflictType());
        $this->assertTrue($thief->certificate_held);
        $item = $thief->conflict['items'][0];
        $this->assertSame('external', $item['type']);
        $this->assertSame('Alisher Ismoilov, Nodira Karimova', $item['source_authors']);
        $this->assertGreaterThanOrEqual(0.5, $item['coverage']);

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
        $this->actingAs($admin)->get(route('admin.papers.show', $thief->paper).'?lang=en')
            ->assertSee('Published elsewhere')->assertSee('Alisher Ismoilov')->assertSee('example-journal.uz/article/77');

        // A real author (written "Ismoilov A." on the form) is cleared.
        $author = app(OriginalityService::class)->run($this->paper('Ismoilov A.'), false);
        $this->assertNotSame('mismatch', $author->conflictType());
    }

    public function test_cron_link_needs_the_secret_and_system_page_shows_the_corpus(): void
    {
        $this->fakeOpenAlex();
        config(['journal.cron_secret' => 'cron-secret-123']);

        $this->get('/cron/wrong')->assertNotFound();
        $this->get('/cron/cron-secret-123')->assertOk()->assertJson(['finished' => true]);
        $this->assertSame(1, ExternalWork::count());

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()
            ->assertSee('Uzbek publications corpus')->assertSee('/cron/cron-secret-123')->assertSee('last run');
    }

    public function test_cron_key_is_generated_without_editing_env(): void
    {
        $this->fakeOpenAlex();
        config(['journal.cron_secret' => null]);
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin', 'password' => Hash::make('x12345678'), 'type' => 'admin']);

        $this->actingAs($admin)->get('/panel/system?lang=en')->assertOk()->assertSee('not set up yet')->assertSee('Copy command');
        $secret = \App\Support\RuntimeSettings::cronSecret();
        $this->assertSame(32, strlen($secret));
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertSee('/cron/'.$secret); // the same key every time

        $this->get('/cron/'.$secret)->assertOk();
        $this->actingAs($admin)->get('/panel/system?lang=en')->assertSee('last run');
    }
}
