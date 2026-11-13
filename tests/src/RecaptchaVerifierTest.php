<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleSAML\Module\multiauthsinglepage\RecaptchaVerifier;

/**
 * Tests for the reCAPTCHA v3 verification gate.
 */
class RecaptchaVerifierTest extends TestCase
{
    private const array KEYS = ['siteKey' => 'site-key', 'secretKey' => 'secret-key'];

    private const array ENFORCE = self::KEYS + ['mode' => RecaptchaVerifier::MODE_ENFORCE];

    private const array GOOD_RESPONSE = [
        'success' => true,
        'score' => 0.9,
        'action' => 'login',
        'hostname' => 'idp.example.org',
    ];


    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    }


    /**
     * @param array<mixed> $response
     */
    private static function respond(array $response): callable
    {
        return fn (): array => $response;
    }


    public function testDisabledWithoutBothKeysAlwaysAllows(): void
    {
        $neverCalled = function (): array {
            $this->fail('The transport should not be called when reCAPTCHA is disabled.');
        };

        foreach ([[], ['siteKey' => 'site-key'], ['secretKey' => 'secret-key']] as $config) {
            $verifier = new RecaptchaVerifier($config + ['mode' => RecaptchaVerifier::MODE_ENFORCE], $neverCalled);
            $this->assertFalse($verifier->isEnabled());
            $this->assertTrue($verifier->isAllowed(null, 'alice'));
        }
    }


    public function testExposesSiteKeyAndAction(): void
    {
        $verifier = new RecaptchaVerifier(self::KEYS);
        $this->assertTrue($verifier->isEnabled());
        $this->assertSame('site-key', $verifier->getSiteKey());
        $this->assertSame('login', $verifier->getAction());

        $verifier = new RecaptchaVerifier(self::KEYS + ['action' => 'signin']);
        $this->assertSame('signin', $verifier->getAction());
    }


    public function testDefaultsToLogMode(): void
    {
        $this->assertFalse((new RecaptchaVerifier(self::KEYS))->isEnforcing());
        $this->assertTrue((new RecaptchaVerifier(self::ENFORCE))->isEnforcing());
    }


    public function testSendsTheExpectedParameters(): void
    {
        $sent = null;
        $verifier = new RecaptchaVerifier(
            self::ENFORCE,
            function (string $url, array $params, array $config) use (&$sent): array {
                $sent = [$url, $params];
                return self::GOOD_RESPONSE;
            },
        );

        $this->assertTrue($verifier->isAllowed('the-token', 'alice'));
        $this->assertSame(RecaptchaVerifier::DEFAULT_VERIFY_URL, $sent[0]);
        $this->assertSame(
            ['secret' => 'secret-key', 'response' => 'the-token', 'remoteip' => '203.0.113.42'],
            $sent[1],
        );
    }


    public function testGoodTokenIsAllowed(): void
    {
        $verifier = new RecaptchaVerifier(self::ENFORCE, self::respond(self::GOOD_RESPONSE));
        $this->assertTrue($verifier->isAllowed('token', 'alice'));
    }


    public function testScoreExactlyAtThresholdIsAllowed(): void
    {
        $verifier = new RecaptchaVerifier(
            self::ENFORCE + ['minScore' => 0.7],
            self::respond(['score' => 0.7] + self::GOOD_RESPONSE),
        );
        $this->assertTrue($verifier->isAllowed('token', 'alice'));
    }


    /**
     * @return array<string, array{0: array<mixed>, 1: string|null, 2?: array<string, mixed>}>
     */
    public static function rejectedAttempts(): array
    {
        return [
            'missing token' => [self::GOOD_RESPONSE, null],
            'empty token' => [self::GOOD_RESPONSE, ''],
            'low score' => [['score' => 0.3] + self::GOOD_RESPONSE, 'token'],
            'below custom min score' => [['score' => 0.6] + self::GOOD_RESPONSE, 'token', ['minScore' => 0.7]],
            'integer zero score' => [['score' => 0] + self::GOOD_RESPONSE, 'token'],
            'missing score' => [array_diff_key(self::GOOD_RESPONSE, ['score' => 0]), 'token'],
            'other action' => [['action' => 'homepage'] + self::GOOD_RESPONSE, 'token'],
            'missing action' => [array_diff_key(self::GOOD_RESPONSE, ['action' => 0]), 'token'],
            'other hostname' => [
                ['hostname' => 'evil.example.org'] + self::GOOD_RESPONSE,
                'token',
                ['hostnames' => ['idp.example.org']],
            ],
            'expired or reused token' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']], 'token'],
            'invalid token' => [['success' => false, 'error-codes' => ['invalid-input-response']], 'token'],
            'failure without error codes' => [['success' => false], 'token'],
            'success not strictly true' => [['success' => 'true'] + self::GOOD_RESPONSE, 'token'],
        ];
    }


    /**
     * @param array<mixed> $response
     * @param array<string, mixed> $extraConfig
     */
    #[DataProvider('rejectedAttempts')]
    public function testRejectedInEnforceMode(array $response, ?string $token, array $extraConfig = []): void
    {
        $verifier = new RecaptchaVerifier($extraConfig + self::ENFORCE, self::respond($response));
        $this->assertFalse($verifier->isAllowed($token, 'alice'));
    }


    /**
     * @param array<mixed> $response
     * @param array<string, mixed> $extraConfig
     */
    #[DataProvider('rejectedAttempts')]
    public function testOnlyLoggedInLogMode(array $response, ?string $token, array $extraConfig = []): void
    {
        $verifier = new RecaptchaVerifier($extraConfig + self::KEYS, self::respond($response));
        $this->assertTrue($verifier->isAllowed($token, 'alice'));
    }


    public function testMatchingHostnameIsAllowed(): void
    {
        $verifier = new RecaptchaVerifier(
            self::ENFORCE + ['hostnames' => ['idppru.example.org', 'idp.example.org']],
            self::respond(self::GOOD_RESPONSE),
        );
        $this->assertTrue($verifier->isAllowed('token', 'alice'));
    }


    public function testTransportFailureFailsOpenByDefault(): void
    {
        $verifier = new RecaptchaVerifier(self::ENFORCE, function (): array {
            throw new \RuntimeException('connection timed out');
        });
        $this->assertTrue($verifier->isAllowed('token', 'alice'));
    }


    public function testTransportFailureFailsClosedWhenConfigured(): void
    {
        $verifier = new RecaptchaVerifier(self::ENFORCE + ['failOpen' => false], function (): array {
            throw new \RuntimeException('connection timed out');
        });
        $this->assertFalse($verifier->isAllowed('token', 'alice'));
    }


    /**
     * A rejected secret key is our misconfiguration, not the user's fault: it is
     * treated like Google being unreachable, so it does not lock everyone out.
     */
    public function testBadSecretKeyFailsOpenByDefault(): void
    {
        $response = ['success' => false, 'error-codes' => ['invalid-input-secret']];

        $verifier = new RecaptchaVerifier(self::ENFORCE, self::respond($response));
        $this->assertTrue($verifier->isAllowed('token', 'alice'));

        $verifier = new RecaptchaVerifier(self::ENFORCE + ['failOpen' => false], self::respond($response));
        $this->assertFalse($verifier->isAllowed('token', 'alice'));
    }


    /**
     * A missing token is not an outage: the page did not send one (a bot, or
     * reCAPTCHA blocked in the browser), so failOpen does not apply.
     */
    public function testMissingTokenIsRejectedEvenWhenFailingOpen(): void
    {
        $verifier = new RecaptchaVerifier(self::ENFORCE + ['failOpen' => true], function (): array {
            $this->fail('Google should not be asked about a missing token.');
        });
        $this->assertFalse($verifier->isAllowed(null, 'alice'));
    }


    /**
     * The browser's diagnostic tells the login page what went wrong, but only for a
     * rejected attempt, and only its outcome part (without the milliseconds).
     */
    public function testClientProblemIsReportedForARejectedAttempt(): void
    {
        $verifier = new RecaptchaVerifier(self::ENFORCE);
        $this->assertFalse($verifier->isAllowed(null, 'alice', 'no-script:5004'));
        $this->assertSame(RecaptchaVerifier::CLIENT_NO_SCRIPT, $verifier->getClientProblem());

        $this->assertFalse($verifier->isAllowed('', 'alice', 'timeout:5000'));
        $this->assertSame(RecaptchaVerifier::CLIENT_TIMEOUT, $verifier->getClientProblem());
    }


    public function testNoClientProblemWhenAllowedOrUnreported(): void
    {
        $verifier = new RecaptchaVerifier(self::ENFORCE, self::respond(self::GOOD_RESPONSE));
        $this->assertTrue($verifier->isAllowed('token', 'alice', 'ok:350'));
        $this->assertNull($verifier->getClientProblem());

        $this->assertFalse($verifier->isAllowed(null, 'alice'));
        $this->assertNull($verifier->getClientProblem());

        // in log mode nothing is rejected, so there is nothing to explain to the user
        $verifier = new RecaptchaVerifier(self::KEYS);
        $this->assertTrue($verifier->isAllowed(null, 'alice', 'no-script:5000'));
        $this->assertNull($verifier->getClientProblem());
    }


    /**
     * The diagnostic comes from the browser and ends up in the log: anything not in
     * the expected "<outcome>[:<ms>]" shape is replaced, never logged as sent.
     */
    public function testUnexpectedClientDiagnosticIsNotTrusted(): void
    {
        $verifier = new RecaptchaVerifier(self::ENFORCE);
        foreach (["no-script:5000\nFAKE LOG LINE", 'other', 'no-script:abc', str_repeat('x', 500)] as $diag) {
            $this->assertFalse($verifier->isAllowed(null, 'alice', $diag));
            $this->assertSame('invalid', $verifier->getClientProblem());
        }
    }
}
