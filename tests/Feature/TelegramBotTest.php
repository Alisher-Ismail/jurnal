<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\Paper;
use App\Models\TelegramChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 1001;
    private const EDITOR = 2002;
    private const STORAGE = -100500;

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.token' => 'TEST',
            'services.telegram.username' => 'emro_test_bot',
            'services.telegram.storage_chat_id' => (string) self::STORAGE,
            'services.telegram.admin_chat_ids' => [(string) self::EDITOR],
            'services.telegram.webhook_secret' => 'hook-secret',
        ]);

        Http::fake(function (Request $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['ok' => true, 'result' => match ($method) {
                'sendDocument' => ['message_id' => 77, 'document' => ['file_id' => 'STORED-FILE-ID']],
                'getFile' => ['file_path' => 'documents/file.pdf'],
                'answerCallbackQuery', 'editMessageReplyMarkup' => true,
                default => ['message_id' => 1],
            }]);
        });
    }

    private function send(array $message): void
    {
        $message += ['message_id' => $this->updateId, 'date' => time()];
        $message['chat'] ??= ['id' => self::USER, 'type' => 'private'];
        $message['from'] ??= ['id' => self::USER, 'first_name' => 'Vali', 'username' => 'vali'];

        $this->postJson('/telegram/webhook/hook-secret', ['update_id' => $this->updateId++, 'message' => $message],
            ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    }

    private function text(string $text, int $from = self::USER): void
    {
        $this->send(['text' => $text, 'chat' => ['id' => $from, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => 'X']]);
    }

    private function press(string $data, int $from = self::USER): void
    {
        $this->postJson('/telegram/webhook/hook-secret', ['update_id' => $this->updateId++, 'callback_query' => [
            'id' => 'cb'.$this->updateId, 'data' => $data, 'from' => ['id' => $from, 'first_name' => 'X'],
            'message' => ['message_id' => 5, 'chat' => ['id' => $from, 'type' => 'private']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    }

    private function sentTo(int $chatId, string $method): \Illuminate\Support\Collection
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/'.$method))
            ->filter(fn (Request $r) => (string) ($r->data()['chat_id'] ?? $this->multipartField($r, 'chat_id')) === (string) $chatId);
    }

    private function multipartField(Request $request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return (string) $part['contents'];
            }
        }

        return null;
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->postJson('/telegram/webhook/wrong', ['update_id' => 1])->assertForbidden();
    }

    public function test_user_submits_a_paper_step_by_step(): void
    {
        $this->text('/start');
        $this->press('lang:en');
        $this->assertSame('en', TelegramChat::where('chat_id', self::USER)->value('locale'));

        $this->text('📝 Submit a paper');
        $this->text('Sensor networks for smart irrigation');
        $this->text('Aliyev Vali, Karimova Nodira');
        $this->text('⏭ Skip');                      // affiliation
        $this->text('not-an-email');                 // rejected, asked again
        $this->text('vali@example.com');
        $this->text('An abstract about irrigation.');
        $this->text('⏭ Skip');                      // keywords
        $this->send(['document' => ['file_id' => 'USER-FILE', 'file_name' => 'irrigation.exe', 'file_size' => 1000]]);
        $this->assertSame(0, Paper::count(), 'Wrong file types must be refused');

        $this->send(['document' => ['file_id' => 'USER-FILE', 'file_name' => 'irrigation.pdf', 'file_size' => 120000]]);

        $paper = Paper::firstOrFail();
        $this->assertSame('telegram', $paper->source);
        $this->assertSame('Sensor networks for smart irrigation', $paper->title);
        $this->assertNull($paper->affiliation);
        $this->assertSame('vali@example.com', $paper->email);
        $this->assertSame('en', $paper->locale);
        $this->assertSame('STORED-FILE-ID', $paper->file_id, 'File is kept in the storage channel');
        $this->assertSame(77, (int) $paper->storage_message_id);

        $this->assertTrue($this->sentTo(self::STORAGE, 'sendDocument')->isNotEmpty(), 'Copied to storage chat');
        // No certificate at submission: the author gets the tracking code and the payment details.
        $this->assertTrue($this->sentTo(self::USER, 'sendDocument')->isEmpty(), 'No certificate before acceptance and payment');
        $this->assertTrue($this->sentTo(self::USER, 'sendMessage')
            ->contains(fn (Request $r) => str_contains($r->data()['text'] ?? '', $paper->code)), 'Author told the tracking code');
        $this->assertTrue($this->sentTo(self::EDITOR, 'sendDocument')->isNotEmpty(), 'Editors notified');
        $this->assertNull(TelegramChat::where('chat_id', self::USER)->value('state'));
    }

    public function test_editor_accepts_from_telegram_and_author_gets_link(): void
    {
        $issue = Maqola::create(['volume' => 'Vol. 1', 'title' => 'Issue One', 'abstract' => '']);
        $paper = Paper::create(['title' => 'Bot paper', 'authors' => 'A. Author', 'locale' => 'uz', 'source' => 'telegram',
            'telegram_chat_id' => self::USER, 'file_id' => 'STORED-FILE-ID', 'file_name' => 'p.pdf']);

        $this->press("adm:accept:{$paper->id}", self::USER);   // not an editor → ignored
        $this->assertSame(Paper::SUBMITTED, $paper->fresh()->status);

        $this->press("adm:accept:{$paper->id}", self::EDITOR);
        $this->press("adm:pub:{$paper->id}:{$issue->id}", self::EDITOR);

        $paper->refresh();
        $this->assertSame(Paper::ACCEPTED, $paper->status);
        $this->assertSame('STORED-FILE-ID', $paper->article->pdf_file_id, 'Article PDF stays in Telegram');
        // Published: the author gets the link; the certificate waits for the payment.
        $this->assertTrue($this->sentTo(self::USER, 'sendDocument')->isEmpty(), 'No certificate before payment');
        $this->assertTrue($this->sentTo(self::USER, 'sendMessage')
            ->contains(fn (Request $r) => str_contains($r->data()['text'] ?? '', (string) $paper->article_id)), 'Article link delivered');

        app(\App\Services\PaperService::class)->confirmPayment($paper, 'Admin');
        $this->assertTrue($this->sentTo(self::USER, 'sendDocument')->isNotEmpty(), 'Publication certificate delivered after payment');
    }

    public function test_editor_rejects_with_a_comment(): void
    {
        $paper = Paper::create(['title' => 'P', 'authors' => 'A', 'locale' => 'ru', 'source' => 'telegram', 'telegram_chat_id' => self::USER]);

        $this->press("adm:reject:{$paper->id}", self::EDITOR);
        $this->text('Не соответствует тематике', self::EDITOR);

        $this->assertSame(Paper::REJECTED, $paper->fresh()->status);
        $this->assertSame('Не соответствует тематике', $paper->fresh()->admin_note);
    }

    public function test_search_returns_existing_papers_and_certificate(): void
    {
        $issue = Maqola::create(['volume' => 'Vol. 1', 'title' => 'Issue One', 'abstract' => '']);
        $article = Article::create(['volume' => (string) $issue->id, 'title' => 'Arduino robot for sea waste', 'author' => 'Ismoilov A.', 'keywords' => '', 'abstract' => '']);
        TelegramChat::create(['chat_id' => self::USER, 'locale' => 'uz']);

        $this->text('🔎 Maqola qidirish');
        $this->text('Arduino');
        $this->assertTrue($this->sentTo(self::USER, 'sendMessage')
            ->contains(fn (Request $r) => str_contains($r->data()['text'] ?? '', '/maqola/'.$article->id)));

        $this->press('acert:'.$article->id);
        $this->assertDatabaseHas('certificates', ['article_id' => $article->id, 'type' => 'publication']);
        $this->assertTrue($this->sentTo(self::USER, 'sendDocument')->isNotEmpty());
    }

    public function test_web_author_links_telegram_with_start_payload(): void
    {
        $paper = Paper::create(['title' => 'Web paper', 'authors' => 'W. Author', 'locale' => 'en', 'source' => 'web']);

        $this->text('/start p_'.$paper->code);

        $this->assertSame(self::USER, (int) $paper->fresh()->telegram_chat_id);
        // Linked; there is no certificate yet, the author is told it follows acceptance and payment.
        $this->assertTrue($this->sentTo(self::USER, 'sendDocument')->isEmpty(), 'No certificate before acceptance and payment');
        $this->assertTrue($this->sentTo(self::USER, 'sendMessage')
            ->contains(fn (Request $r) => str_contains($r->data()['text'] ?? '', 'payment is confirmed')), 'Author told when the certificate comes');
    }
}
