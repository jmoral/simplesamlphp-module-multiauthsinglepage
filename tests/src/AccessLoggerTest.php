<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\multiauthsinglepage\AccessLogger;

/**
 * Tests for the AccessLogger webservice notifier.
 */
class AccessLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    }


    public function testDisabledWithoutUrl(): void
    {
        $logger = new AccessLogger([], function (): void {
            $this->fail('The transport should not be called when no url is configured.');
        });

        $this->assertFalse($logger->isEnabled());

        $logger->registerFailedAttempt('alice', 'https://sp.example.org');
    }


    public function testSendsTheExpectedParameters(): void
    {
        $sent = null;
        $logger = new AccessLogger(
            ['url' => 'https://webservice.example.org/endpoint'],
            function (string $url, array $params, array $config) use (&$sent): void {
                $sent = [$url, $params];
            },
        );

        $this->assertTrue($logger->isEnabled());

        $logger->registerFailedAttempt('alice', 'https://sp.example.org');

        $this->assertSame('https://webservice.example.org/endpoint', $sent[0]);
        $this->assertSame(
            [
                'destino' => 'alice',
                'a' => 'registraAcceso1faFallido',
                'origen' => '203.0.113.42',
                'serviceProvider' => 'https://sp.example.org',
                'sistemaAutenticacion' => 'contraseña',
                'idpExterno' => 'no aplica',
                'forwarded' => '',
                'podName' => '',
                'nodeName' => '',
            ],
            $sent[1],
        );
    }


    public function testConfigOverridesTheDefaultLabels(): void
    {
        $sent = null;
        $logger = new AccessLogger(
            [
                'url' => 'https://webservice.example.org/endpoint',
                'sistemaAutenticacion' => 'LDAP-UJA',
                'idpExterno' => 'idp-partner',
            ],
            function (string $url, array $params) use (&$sent): void {
                $sent = $params;
            },
        );

        $logger->registerFailedAttempt('alice', 'https://sp.example.org');

        $this->assertSame('LDAP-UJA', $sent['sistemaAutenticacion']);
        $this->assertSame('idp-partner', $sent['idpExterno']);
    }


    public function testMissingUsernameOrServiceProviderFallBackToPlaceholders(): void
    {
        $sent = null;
        $logger = new AccessLogger(
            ['url' => 'https://webservice.example.org/endpoint'],
            function (string $url, array $params) use (&$sent): void {
                $sent = $params;
            },
        );

        $logger->registerFailedAttempt(null, null);

        $this->assertSame('desconocido', $sent['destino']);
        $this->assertSame('', $sent['serviceProvider']);
    }


    public function testTransportFailureIsSwallowed(): void
    {
        $logger = new AccessLogger(
            ['url' => 'https://webservice.example.org/endpoint'],
            function (): void {
                throw new \RuntimeException('connection refused');
            },
        );

        // Must not throw.
        $logger->registerFailedAttempt('alice', 'https://sp.example.org');
        $this->addToAssertionCount(1);
    }
}
