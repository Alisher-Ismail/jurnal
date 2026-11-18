<?php

namespace App\Console\Commands;

use App\Services\SpaceSaver;
use Illuminate\Console\Command;

/**
 * Moves journal files (issues, articles, submitted manuscripts) to the Telegram storage channel
 * and deletes the server copy once Telegram's copy is confirmed. Old links keep working: they
 * are served from Telegram. Files over 20 MB stay on the server (bot download limit).
 */
class MoveFilesToTelegram extends Command
{
    protected $signature = 'files:to-telegram {--seconds=240 : Stop after this many seconds}';

    protected $description = 'Move files up to 20 MB to the Telegram storage chat and free server space';

    public function handle(SpaceSaver $saver): int
    {
        $report = $saver->run((int) $this->option('seconds'));

        foreach ($report['failed'] as $line) {
            $this->error($line);
        }
        $this->info(SpaceSaver::summary($report));

        return self::SUCCESS;
    }
}
