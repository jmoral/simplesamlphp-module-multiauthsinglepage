<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage\fixtures\Source;

use SimpleSAML\Module\core\Auth\UserPassBase;

class SuccessAuthSource extends UserPassBase
{
    /**
     * @return array<mixed>
     */
    protected function login(string $username, string $password): array
    {
        return [
            'uid' => ['username'],
        ];
    }
}
