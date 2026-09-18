<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage\Error;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\multiauthsinglepage\Error\ErrorCodes;
use SimpleSAML\Module\multiauthsinglepage\LoginThrottle;

/**
 * Tests for the module's custom SimpleSAML\Error\ErrorCodes registration.
 *
 * This exists so that LoginThrottle::ERROR_CODE always has a title/description
 * in the map any template renders from, including a theme's own (possibly
 * stale) copy of multiauthonepage.twig that just does
 * `errorcodes['title'][errorcode]` generically -- without this, that lookup
 * throws a Twig\Error\RuntimeError instead of showing a message.
 */
class ErrorCodesTest extends TestCase
{
    public function testLoginThrottleErrorCodeHasATitleAndDescription(): void
    {
        $messages = (new ErrorCodes())->getAllMessages();

        $this->assertArrayHasKey(LoginThrottle::ERROR_CODE, $messages['title']);
        $this->assertArrayHasKey(LoginThrottle::ERROR_CODE, $messages['descr']);
        $this->assertNotSame('', $messages['title'][LoginThrottle::ERROR_CODE]);
        $this->assertNotSame('', $messages['descr'][LoginThrottle::ERROR_CODE]);
    }


    public function testBuiltInCodesAreStillAvailable(): void
    {
        $messages = (new ErrorCodes())->getAllMessages();

        $this->assertArrayHasKey('WRONGUSERPASS', $messages['title']);
        $this->assertArrayHasKey('BADREQUEST', $messages['title']);
    }
}
