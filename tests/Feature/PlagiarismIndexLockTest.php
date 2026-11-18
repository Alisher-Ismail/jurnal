<?php

namespace Tests\Feature;

use App\Services\Originality\PlagiarismIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlagiarismIndexLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_sync_waits_while_another_one_runs(): void
    {
        $lock = Cache::lock('plagiarism-index-sync', 60);
        $this->assertTrue($lock->get());

        try {
            $result = app(PlagiarismIndex::class)->sync(1);
            $this->assertTrue($result['busy']);
            $this->assertSame(0, $result['indexed']);
        } finally {
            $lock->release();
        }

        // Once the other run is over, syncing works again and releases the lock afterwards.
        $result = app(PlagiarismIndex::class)->sync(0);
        $this->assertArrayNotHasKey('busy', $result);
        $this->assertTrue(Cache::lock('plagiarism-index-sync', 5)->get());
    }
}
