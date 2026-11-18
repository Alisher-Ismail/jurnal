<?php

namespace App\Console\Commands;

use App\Services\Originality\CorpusHarvester;
use Illuminate\Console\Command;

class HarvestCorpus extends Command
{
    protected $signature = 'corpus:harvest {--seconds=50 : Time budget for this run}';

    protected $description = 'Harvest published Uzbek research from OpenAlex into the plagiarism corpus (resumes automatically)';

    public function handle(CorpusHarvester $harvester): int
    {
        $result = $harvester->harvest((float) $this->option('seconds'));
        $this->line(sprintf('%d works from %d pages%s%s', $result['works'], $result['pages'],
            $result['finished'] ? ', complete' : '', $result['error'] ? ' — '.$result['error'] : ''));

        return self::SUCCESS;
    }
}
