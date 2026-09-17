<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\multiauthsinglepage\AccessBackoff;

/**
 * Tests for the AccessBackoff exponential-backoff counter.
 */
class AccessBackoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_URI'] = '/module.php/multiauthsinglepage/login';
    }


    public function testWaitGrowsExponentiallyOnRepeatedBlocks(): void
    {
        $backoff = new AccessBackoff();
        $username = 'alice-' . uniqid();

        $this->assertSame(2, $backoff->registerBlock($username));
        $this->assertSame(4, $backoff->registerBlock($username));
        $this->assertSame(8, $backoff->registerBlock($username));
        $this->assertSame(16, $backoff->registerBlock($username));
    }


    public function testWaitIsCappedAtTheMaximum(): void
    {
        $backoff = new AccessBackoff();
        $username = 'bob-' . uniqid();

        $wait = 0;
        for ($i = 0; $i < 20; $i++) {
            $wait = $backoff->registerBlock($username);
        }

        $this->assertSame(300, $wait);
    }


    public function testResetStartsTheBackoffOverFromScratch(): void
    {
        $backoff = new AccessBackoff();
        $username = 'carol-' . uniqid();

        $backoff->registerBlock($username);
        $backoff->registerBlock($username);
        $backoff->reset($username);

        $this->assertSame(2, $backoff->registerBlock($username));
    }


    public function testDifferentUsernamesAreTrackedIndependently(): void
    {
        $backoff = new AccessBackoff();
        $alice = 'alice-' . uniqid();
        $dave = 'dave-' . uniqid();

        $backoff->registerBlock($alice);
        $backoff->registerBlock($alice);

        $this->assertSame(2, $backoff->registerBlock($dave));
    }
}
