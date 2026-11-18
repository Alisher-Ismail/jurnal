<?php

namespace App\Console\Commands;

use App\Services\FileVault;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Reports, for every issue and article, whether its PDF can actually be served and from where. */
class CheckFiles extends Command
{
    protected $signature = 'files:check {--missing : Only list files that cannot be found}';

    protected $description = 'Show where each issue/article PDF is stored, and which are missing';

    public function handle(FileVault $vault): int
    {
        $rows = collect($vault->report())->when($this->option('missing'), fn ($c) => $c->where('missing', true));
        $missing = collect($vault->report())->where('missing', true)->count();

        $this->table(['Record', 'Title', 'Stored path (database)', 'Found at'], $rows->map(fn ($r) => [
            $r['record'], Str::limit($r['title'], 45), Str::limit((string) $r['path'], 50), $r['where'],
        ])->all());

        $missing
            ? $this->warn("{$missing} file(s) are missing on this server: re-upload them in Panel → Articles / Issues.")
            : $this->info('All files found.');

        return self::SUCCESS;
    }
}
