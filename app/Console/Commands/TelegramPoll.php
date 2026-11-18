<?php

namespace App\Console\Commands;

use App\Services\Telegram\BotHandler;
use App\Services\Telegram\TelegramApi;
use Illuminate\Console\Command;
use Throwable;

/** Long-polling runner for local development, where Telegram cannot reach a webhook. */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Process one batch of updates and exit}';

    protected $description = 'Run the Telegram bot with long polling (no public URL needed)';

    public function handle(TelegramApi $api, BotHandler $bot): int
    {
        $api->call('deleteWebhook');
        $me = $api->call('getMe');
        $this->info('Polling as @'.$me['username'].' — press Ctrl+C to stop.');

        $offset = 0;
        do {
            try {
                $updates = $api->call('getUpdates', ['offset' => $offset, 'timeout' => 25], 35);
            } catch (Throwable $e) {
                $this->warn($e->getMessage());
                sleep(3);
                continue;
            }

            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;
                $bot->handle($update);
                $this->line('· update '.$update['update_id']);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
