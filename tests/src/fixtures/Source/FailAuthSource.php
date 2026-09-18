<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage\fixtures\Source;

use SimpleSAML\Error;
use SimpleSAML\Module\core\Auth\UserPassBase;

/**
 * A username/password source that always rejects the credentials, counting
 * how many times it was actually asked to -- so a test can tell a real
 * failed attempt apart from one that LoginThrottle blocked pre-emptively.
 */
class FailAuthSource extends UserPassBase
{
    public static int $callCount = 0;


    /**
     * @return array<mixed>
     */
    protected function login(string $username, string $password): array
    {
        self::$callCount++;

        throw new Error\Error(Error\ErrorCodes::WRONGUSERPASS);
    }
}
