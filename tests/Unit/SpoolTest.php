<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Tests\Support\TestCase;
use WatchTower\Transport\Spool;

final class SpoolTest extends TestCase
{
    public function test_batches_are_json_lines_claimed_oldest_first(): void
    {
        $spool = new Spool($this->spoolDir, 1 << 20);
        $spool->push('{"records":[{"t":"a"}]}');
        usleep(1000);
        $spool->push("{\"records\":[{\"t\":\"b\"}]}\n");

        $this->assertSame(2, $spool->count());
        $claimed = $spool->claim(1);
        $this->assertCount(1, $claimed);
        $this->assertSame(['{"records":[{"t":"a"}]}'], $spool->read($claimed[0]));
        $this->assertSame(1, $spool->count());

        $spool->release($claimed[0]);
        $this->assertSame(2, $spool->count());

        foreach ($spool->claim(5) as $file) {
            $spool->remove($file);
        }

        $this->assertSame(0, $spool->count());
    }

    public function test_size_cap_drops_the_oldest_batches(): void
    {
        $spool = new Spool($this->spoolDir, 2048);
        $payload = '{"records":['.str_repeat('1,', 300).'1]}';

        for ($i = 0; $i < 6; $i++) {
            $spool->push($payload);
            usleep(1000);
        }

        $this->assertLessThanOrEqual(2048, $spool->size());
        $this->assertSame(3, $spool->count());
    }

    public function test_abandoned_claims_are_released(): void
    {
        $spool = new Spool($this->spoolDir, 1 << 20);
        $spool->push('{"records":[]}');
        [$claimed] = $spool->claim(1);
        touch($claimed, time() - 120);

        $this->assertCount(1, $spool->claim(1));
    }
}
