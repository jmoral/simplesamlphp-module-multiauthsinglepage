<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\multiauthsinglepage\AccessChecker;

/**
 * Tests for the AccessChecker webservice gate.
 */
class AccessCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    }


    public function testDisabledWithoutUrlAlwaysAllows(): void
    {
        $checker = new AccessChecker([], function (): int {
            $this->fail('The transport should not be called when no url is configured.');
        });

        $this->assertFalse($checker->isEnabled());
        $this->assertTrue($checker->isAllowed('alice', 'https://sp.example.org'));
    }


    public function testSendsTheExpectedParameters(): void
    {
        $sent = null;
        $checker = new AccessChecker(
            ['url' => 'https://webservice.example.org/endpoint'],
            function (string $url, array $params, array $config) use (&$sent): int {
                $sent = [$url, $params];
                return 200;
            },
        );

        $this->assertTrue($checker->isEnabled());
        $this->assertTrue($checker->isAllowed('alice', 'https://sp.example.org'));

        $this->assertSame('https://webservice.example.org/endpoint', $sent[0]);
        $this->assertSame(
            [
                'destino' => 'alice',
                'a' => 'compruebaCondicionesAcceso',
                'origen' => '203.0.113.42',
                'serviceProvider' => 'https://sp.example.org',
                'forwarded' => '',
                'podName' => '',
                'nodeName' => '',
            ],
            $sent[1],
        );
    }


    public function testMissingUsernameOrServiceProviderFallBackToPlaceholders(): void
    {
        $sent = null;
        $checker = new AccessChecker(
            ['url' => 'https://webservice.example.org/endpoint'],
            function (string $url, array $params) use (&$sent): int {
                $sent = $params;
                return 200;
            },
        );

        $checker->isAllowed(null, null);

        $this->assertSame('desconocido', $sent['destino']);
        $this->assertSame('', $sent['serviceProvider']);
    }


    public function testForwardedAndPodInfoAreIncludedWhenPresent(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
        putenv('POD_NAME=multiauthsinglepage-abc123');
        putenv('NODE_NAME=node-7');

        $sent = null;
        $checker = new AccessChecker(
            ['url' => 'https://webservice.example.org/endpoint'],
            function (string $url, array $params) use (&$sent): int {
                $sent = $params;
                return 200;
            },
        );

        try {
            $checker->isAllowed('alice', 'https://sp.example.org');
        } finally {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
            putenv('POD_NAME');
            putenv('NODE_NAME');
        }

        $this->assertSame('198.51.100.7', $sent['forwarded']);
        $this->assertSame('multiauthsinglepage-abc123', $sent['podName']);
        $this->assertSame('node-7', $sent['nodeName']);
    }


    public function test429IsNotAllowed(): void
    {
        $checker = new AccessChecker(
            ['url' => 'https://webservice.example.org/endpoint'],
            fn (): int => 429,
        );

        $this->assertFalse($checker->isAllowed('alice', 'https://sp.example.org'));
    }


    public function testOtherUnexpectedStatusFailsOpen(): void
    {
        $checker = new AccessChecker(
            ['url' => 'https://webservice.example.org/endpoint'],
            fn (): int => 500,
        );

        $this->assertTrue($checker->isAllowed('alice', 'https://sp.example.org'));
    }


    public function testTransportFailureFailsOpen(): void
    {
        $checker = new AccessChecker(
            ['url' => 'https://webservice.example.org/endpoint'],
            function (): int {
                throw new \RuntimeException('connection refused');
            },
        );

        // Must not throw, and must not block the login.
        $this->assertTrue($checker->isAllowed('alice', 'https://sp.example.org'));
    }
}
