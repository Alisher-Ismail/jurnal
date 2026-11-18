<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin wrapper over the Telegram Bot API (https://core.telegram.org/bots/api). */
class TelegramApi
{
    public function __construct(private ?string $token = null)
    {
        $this->token = $token ?? config('services.telegram.token');
    }

    public function enabled(): bool
    {
        return filled($this->token);
    }

    public function call(string $method, array $params = [], int $timeout = 30): array
    {
        $this->ensureEnabled();

        $response = Http::timeout($timeout)->asJson()
            ->post($this->endpoint($method), array_filter($params, fn ($v) => $v !== null));

        return $this->unwrap($method, self::decode($response->body()));
    }

    /** Big ids (users, channels) stay strings, so a 32-bit PHP never turns them into floats. */
    private static function decode(string $body): ?array
    {
        $json = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);

        return is_array($json) ? $json : null;
    }

    /** Multipart call used to upload a local file (or raw contents) to Telegram. */
    public function upload(string $method, array $params, string $field, string $contents, string $filename): array
    {
        $this->ensureEnabled();

        $request = Http::timeout(120)->attach($field, $contents, $filename);
        foreach (array_filter($params, fn ($v) => $v !== null) as $key => $value) {
            $params[$key] = is_array($value) ? json_encode($value) : $value;
        }

        return $this->unwrap($method, self::decode($request->post($this->endpoint($method), array_filter($params, fn ($v) => $v !== null))->body()));
    }

    public function sendMessage($chatId, string $text, ?array $markup = null, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $markup,
        ], $extra));
    }

    /** Send a document that is already on Telegram servers (by file_id). */
    public function sendDocumentById($chatId, string $fileId, ?string $caption = null, ?array $markup = null): array
    {
        return $this->call('sendDocument', [
            'chat_id' => $chatId,
            'document' => $fileId,
            'caption' => $caption ? mb_substr($caption, 0, 1024) : null,
            'parse_mode' => 'HTML',
            'reply_markup' => $markup,
        ]);
    }

    public function sendDocumentContents($chatId, string $contents, string $filename, ?string $caption = null, ?array $markup = null): array
    {
        return $this->upload('sendDocument', [
            'chat_id' => $chatId,
            'caption' => $caption ? mb_substr($caption, 0, 1024) : null,
            'parse_mode' => 'HTML',
            'reply_markup' => $markup,
        ], 'document', $contents, $filename);
    }

    public function answerCallback(string $id, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
    }

    /** Temporary download URL for a stored file. Telegram keeps these valid for at least an hour. */
    public function fileUrl(string $fileId): string
    {
        $path = Cache::remember('tg-file:'.$fileId, now()->addMinutes(50), function () use ($fileId) {
            return $this->call('getFile', ['file_id' => $fileId])['file_path'];
        });

        return 'https://api.telegram.org/file/bot'.$this->token.'/'.$path;
    }

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'.$this->token.'/'.$method;
    }

    private function unwrap(string $method, ?array $json): array
    {
        if (! ($json['ok'] ?? false)) {
            throw TelegramError::from($method, $json);
        }

        return is_array($json['result']) ? $json['result'] : ['value' => $json['result']];
    }

    private function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }
    }
}
