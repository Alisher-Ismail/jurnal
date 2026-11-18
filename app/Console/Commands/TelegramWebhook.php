<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApi;
use Illuminate\Console\Command;

class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {--delete : Remove the webhook} {--url= : Override the public base URL}';

    protected $description = 'Register (or remove) the Telegram webhook and the bot command menu';

    public function handle(TelegramApi $api): int
    {
        if ($this->option('delete')) {
            $api->call('deleteWebhook');
            $this->info('Webhook removed.');

            return self::SUCCESS;
        }

        $secret = config('services.telegram.webhook_secret');
        if (! $secret) {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET in .env first.');

            return self::FAILURE;
        }

        $base = rtrim($this->option('url') ?: config('app.url'), '/');
        $url = $base.'/telegram/webhook/'.$secret;
        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram requires HTTPS. {$url} will not work; use `php artisan telegram:poll` locally.");

            return self::FAILURE;
        }

        $api->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query', 'channel_post', 'my_chat_member'],
            'drop_pending_updates' => false,
        ]);

        foreach (['uz' => null, 'en' => 'en', 'ru' => 'ru'] as $locale => $code) {
            $api->call('setMyCommands', ['language_code' => $code, 'commands' => [
                ['command' => 'start', 'description' => __('Main menu', [], $locale)],
                ['command' => 'lang', 'description' => __('Change language', [], $locale)],
                ['command' => 'cancel', 'description' => __('Cancel the current action', [], $locale)],
            ]]);
        }

        $this->info("Webhook set to {$url}");

        return self::SUCCESS;
    }
}
