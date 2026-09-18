<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\multiauthsinglepage\LoginThrottle;

/**
 * Tests for the LoginThrottle local exponential-backoff gate.
 */
class LoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_URI'] = '/module.php/multiauthsinglepage/login';
    }


    public function testFirstThreeFailuresAreNeverBlocked(): void
    {
        $throttle = new LoginThrottle();
        $username = 'alice-' . uniqid();

        $throttle->registerFailedAttempt($username);
        $this->assertFalse($throttle->isBlocked($username));

        $throttle->registerFailedAttempt($username);
        $this->assertFalse($throttle->isBlocked($username));

        $throttle->registerFailedAttempt($username);
        $this->assertFalse($throttle->isBlocked($username));
    }


    public function testTheFourthConsecutiveFailureStartsBlocking(): void
    {
        $throttle = new LoginThrottle();
        $username = 'bob-' . uniqid();

        for ($i = 0; $i < 4; $i++) {
            $throttle->registerFailedAttempt($username);
        }

        $this->assertTrue($throttle->isBlocked($username));
    }


    public function testResetAfterASuccessLiftsTheBlockAndStartsOverFromScratch(): void
    {
        $throttle = new LoginThrottle();
        $username = 'carol-' . uniqid();

        for ($i = 0; $i < 4; $i++) {
            $throttle->registerFailedAttempt($username);
        }
        $this->assertTrue($throttle->isBlocked($username));

        $throttle->reset($username);
        $this->assertFalse($throttle->isBlocked($username));

        // Back to the free attempts after a reset.
        $throttle->registerFailedAttempt($username);
        $throttle->registerFailedAttempt($username);
        $throttle->registerFailedAttempt($username);
        $this->assertFalse($throttle->isBlocked($username));
    }


    public function testDifferentUsernamesAreTrackedIndependently(): void
    {
        $throttle = new LoginThrottle();
        $dave = 'dave-' . uniqid();
        $erin = 'erin-' . uniqid();

        for ($i = 0; $i < 4; $i++) {
            $throttle->registerFailedAttempt($dave);
        }

        $this->assertTrue($throttle->isBlocked($dave));
        $this->assertFalse($throttle->isBlocked($erin));
    }
}
