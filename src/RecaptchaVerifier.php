<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage;

use SimpleSAML\Logger;

/**
 * Verifies a Google reCAPTCHA v3 token submitted with a username/password
 * login attempt, before the credentials are checked against the
 * authentication source.
 *
 * Configured through the "recaptcha" option of the
 * multiauthsinglepage:Multiauthsinglepage authsource; disabled unless both a
 * "siteKey" and a "secretKey" are given.
 *
 * Every verification is logged (score, action, hostname and outcome), so the
 * "minScore" threshold can be tuned from real traffic. In "log" mode (the
 * default) that is all it does: no attempt is ever rejected. In "enforce"
 * mode an attempt is rejected when the token is missing, invalid, expired,
 * for another action or hostname, or scores below "minScore".
 *
 * A failure to reach Google, or a rejected "secretKey" (a configuration
 * problem, not something the user did), fails open by default ("failOpen"),
 * so an outage or a misconfiguration never locks every user out.
 */
class RecaptchaVerifier
{
    /**
     * The \SimpleSAML\Error\Error code thrown when an attempt is rejected.
     */
    public const string ERROR_CODE = 'MULTIAUTHCAPTCHA';

    /**
     * The POST parameter the login page sends the token in.
     */
    public const string REQUEST_PARAM = 'g-recaptcha-response';

    /**
     * The POST parameter js/recaptcha.js reports what happened in the browser in:
     * "<outcome>:<milliseconds>", outcome one of the CLIENT_* constants below.
     */
    public const string DIAG_PARAM = 'g-recaptcha-diag';

    /** The browser got a token. */
    public const string CLIENT_OK = 'ok';

    /** Google's script never became available: blocked by the browser, an extension or the network. */
    public const string CLIENT_NO_SCRIPT = 'no-script';

    /** The script was there, but no token arrived in time. */
    public const string CLIENT_TIMEOUT = 'timeout';

    /** Asking for a token failed. */
    public const string CLIENT_ERROR = 'error';

    public const string MODE_LOG = 'log';

    public const string MODE_ENFORCE = 'enforce';

    public const string DEFAULT_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public const string DEFAULT_ACTION = 'login';

    public const float DEFAULT_MIN_SCORE = 0.5;

    /**
     * Default "connectTimeout": this call runs inline before every login
     * attempt, so it must never make the login page noticeably slower.
     */
    public const int DEFAULT_CONNECT_TIMEOUT = 2;

    /**
     * Default "timeout" (total, connect included). See DEFAULT_CONNECT_TIMEOUT.
     */
    public const int DEFAULT_TIMEOUT = 3;

    /**
     * siteverify error codes that point at our own configuration rather than
     * at the token the user sent.
     */
    private const array CONFIG_ERRORS = ['missing-input-secret', 'invalid-input-secret'];

    /** The attempt passed. */
    private const string RESULT_OK = 'ok';

    /** The attempt failed verification. */
    private const string RESULT_REJECTED = 'rejected';

    /** Verification could not be done (Google unreachable, bad secret, ...). */
    private const string RESULT_UNAVAILABLE = 'unavailable';


    /**
     * @var callable(string $url, array<string, string> $params, array<string, mixed> $config): array<mixed>
     */
    private $transport;

    /**
     * The browser-side outcome (a CLIENT_* constant) of the last attempt rejected by
     * isAllowed(), when the browser reported one; see getClientProblem().
     */
    private ?string $clientProblem = null;


    /**
     * @param array<string, mixed> $config The "recaptcha" authsource config option:
     *      - siteKey (string, required to enable)
     *      - secretKey (string, required to enable)
     *      - mode (string, optional, self::MODE_LOG or self::MODE_ENFORCE, default self::MODE_LOG)
     *      - minScore (float, optional, 0.0-1.0, default self::DEFAULT_MIN_SCORE)
     *      - action (string, optional, default self::DEFAULT_ACTION)
     *      - hostnames (string[], optional, accepted hostnames; default: any)
     *      - failOpen (bool, optional, default true)
     *      - verifyUrl (string, optional, default self::DEFAULT_VERIFY_URL)
     *      - connectTimeout (int, optional, seconds, default self::DEFAULT_CONNECT_TIMEOUT)
     *      - timeout (int, optional, seconds, default self::DEFAULT_TIMEOUT)
     * @param (callable(string, array<string, string>, array<string, mixed>): array<mixed>)|null $transport
     *      How to actually perform the HTTP request and return the decoded JSON
     *      response; defaults to a cURL POST. Must throw on any transport error.
     *      Overridable for tests.
     */
    public function __construct(
        private readonly array $config,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curlPost(...);
    }


    /**
     * Whether both keys were configured; when they were not, this class is a
     * no-op and every attempt is allowed.
     */
    public function isEnabled(): bool
    {
        return !empty($this->config['siteKey']) && !empty($this->config['secretKey']);
    }


    /**
     * The public site key the login page loads reCAPTCHA with.
     */
    public function getSiteKey(): string
    {
        return (string) ($this->config['siteKey'] ?? '');
    }


    /**
     * The action name the login page must request the token for.
     */
    public function getAction(): string
    {
        $action = $this->config['action'] ?? '';
        return is_string($action) && $action !== '' ? $action : self::DEFAULT_ACTION;
    }


    /**
     * Whether attempts that fail verification are actually rejected.
     */
    public function isEnforcing(): bool
    {
        return ($this->config['mode'] ?? self::MODE_LOG) === self::MODE_ENFORCE;
    }


    /**
     * Whether a login attempt carrying $token may proceed.
     *
     * Always true when disabled or in "log" mode; the outcome is logged either way.
     *
     * @param string|null $token The token the login page posted, if any.
     * @param string|null $username The username entered by the user, for the log.
     * @param string|null $clientDiag What js/recaptcha.js reported (DIAG_PARAM), if anything.
     */
    public function isAllowed(?string $token, ?string $username, ?string $clientDiag = null): bool
    {
        $this->clientProblem = null;
        if (!$this->isEnabled()) {
            return true;
        }

        [$result, $reason, $response] = $this->verify($token);
        $clientDiag = self::sanitizeClientDiag($clientDiag);

        $allowed = match ($result) {
            self::RESULT_OK => true,
            self::RESULT_UNAVAILABLE => ($this->config['failOpen'] ?? true) !== false,
            default => false,
        };
        if (!$this->isEnforcing()) {
            $allowed = true;
        }

        $this->log($username, $result, $reason, $response, $allowed, $clientDiag);
        if (!$allowed && $clientDiag !== null) {
            $this->clientProblem = explode(':', $clientDiag, 2)[0];
        }

        return $allowed;
    }


    /**
     * For the last attempt isAllowed() rejected, what the browser reported (one of
     * the CLIENT_* constants), so the login page can tell the user something more
     * useful (e.g. CLIENT_NO_SCRIPT: something is blocking Google). Null when the
     * attempt was allowed or the browser reported nothing usable.
     */
    public function getClientProblem(): ?string
    {
        return $this->clientProblem;
    }


    /**
     * The browser-side diagnostic is untrusted input that ends up in the log: only
     * "<known outcome>[:<milliseconds>]" is kept, anything else becomes "invalid".
     */
    private static function sanitizeClientDiag(?string $diag): ?string
    {
        if ($diag === null || $diag === '') {
            return null;
        }
        $outcomes = implode('|', [self::CLIENT_OK, self::CLIENT_NO_SCRIPT, self::CLIENT_TIMEOUT, self::CLIENT_ERROR]);
        return preg_match('/^(' . $outcomes . ')(:\d{1,7})?$/', $diag) === 1 ? $diag : 'invalid';
    }


    /**
     * @return array{0: string, 1: string, 2: array<mixed>} [result, reason, siteverify response]
     */
    private function verify(?string $token): array
    {
        if ($token === null || $token === '') {
            return [self::RESULT_REJECTED, 'missing-token', []];
        }

        $params = [
            'secret' => (string) $this->config['secretKey'],
            'response' => $token,
        ];
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $params['remoteip'] = (string) $_SERVER['REMOTE_ADDR'];
        }

        try {
            $verifyUrl = (string) ($this->config['verifyUrl'] ?? self::DEFAULT_VERIFY_URL);
            $response = ($this->transport)($verifyUrl, $params, $this->config);
        } catch (\Throwable $e) {
            return [self::RESULT_UNAVAILABLE, 'transport: ' . $e->getMessage(), []];
        }

        if (($response['success'] ?? false) !== true) {
            $errors = array_values(array_filter(
                (array) ($response['error-codes'] ?? []),
                'is_string',
            ));
            if (array_intersect($errors, self::CONFIG_ERRORS) !== []) {
                return [self::RESULT_UNAVAILABLE, 'config: ' . implode(',', $errors), $response];
            }
            $reason = 'invalid: ' . ($errors === [] ? 'unknown' : implode(',', $errors));
            return [self::RESULT_REJECTED, $reason, $response];
        }

        if (($response['action'] ?? null) !== $this->getAction()) {
            return [self::RESULT_REJECTED, 'action', $response];
        }

        $hostnames = (array) ($this->config['hostnames'] ?? []);
        if ($hostnames !== [] && !in_array($response['hostname'] ?? null, $hostnames, true)) {
            return [self::RESULT_REJECTED, 'hostname', $response];
        }

        $score = $response['score'] ?? null;
        if (!is_int($score) && !is_float($score)) {
            return [self::RESULT_REJECTED, 'no-score', $response];
        }
        $minScore = (float) ($this->config['minScore'] ?? self::DEFAULT_MIN_SCORE);
        if ($score < $minScore) {
            return [self::RESULT_REJECTED, 'score', $response];
        }

        return [self::RESULT_OK, 'ok', $response];
    }


    /**
     * One log line per verification, in a key=value format easy to filter and
     * aggregate on (e.g. in OpenSearch) while tuning "minScore".
     *
     * @param array<mixed> $response
     */
    private function log(
        ?string $username,
        string $result,
        string $reason,
        array $response,
        bool $allowed,
        ?string $clientDiag,
    ): void {
        $score = $response['score'] ?? null;
        $message = sprintf(
            'Multiauthsinglepage - recaptcha user=%s result=%s reason=%s score=%s action=%s hostname=%s'
            . ' mode=%s allowed=%s client=%s ip=%s',
            $username ?? 'desconocido',
            $result,
            $reason,
            is_int($score) || is_float($score) ? (string) $score : '-',
            is_string($response['action'] ?? null) ? $response['action'] : '-',
            is_string($response['hostname'] ?? null) ? $response['hostname'] : '-',
            $this->isEnforcing() ? self::MODE_ENFORCE : self::MODE_LOG,
            $allowed ? 'yes' : 'no',
            $clientDiag ?? '-',
            (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
        );

        match ($result) {
            self::RESULT_OK => Logger::info($message),
            self::RESULT_UNAVAILABLE => Logger::error($message),
            default => Logger::warning($message),
        };
    }


    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $config
     * @return array<mixed>
     */
    private static function curlPost(string $url, array $params, array $config): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init() failed');
        }

        try {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $connectTimeout = (int) ($config['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT));

            $body = curl_exec($ch);

            if (curl_errno($ch) !== 0 || !is_string($body)) {
                throw new \RuntimeException('curl error: ' . curl_error($ch));
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('unexpected HTTP status ' . $status);
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('response is not a JSON object');
            }

            return $decoded;
        } finally {
            curl_close($ch);
        }
    }
}
