<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Module\multiauthsinglepage\AccessChecker;
use SimpleSAML\Module\multiauthsinglepage\Auth\Source\Multiauthsinglepage;
use SimpleSAML\Module\multiauthsinglepage\Controller;
use SimpleSAML\Module\multiauthsinglepage\LoginThrottle;
use SimpleSAML\Session;
use SimpleSAML\Test\Module\multiauthsinglepage\fixtures\Source\FailAuthSource;
use SimpleSAML\Test\Module\multiauthsinglepage\fixtures\Source\SuccessAuthSource;
use SimpleSAML\XHTML\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Set of tests for the controllers in the "multiauthsinglepage" module.
 */
class SinglepageControllerTest extends TestCase
{
    /** @var \SimpleSAML\Configuration */
    protected Configuration $config;

    /** @var \SimpleSAML\Session */
    protected Session $session;


    private const string URI_LOGIN = '/module.php/multiauthsinglepage/login';


    /**
     * Set up for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Configuration::loadFromArray(
            [
                'module.enable' => ['multiauthsinglepage' => true],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $this->session = Session::getSessionFromRequest();
        Configuration::setPreLoadedConfig($this->config, 'config.php');

        $sourceConfig = Configuration::loadFromArray([
            'singlepage-as' => [
                'multiauthsinglepage:Multiauthsinglepage',
                'sources' => ['success-as'],
            ],
            'dummy-as' => [
                'multiauthsinglepage:DummyAuthSource',
            ],
            'success-as' => [
                SuccessAuthSource::class,
            ],
            'fail-as' => [
                FailAuthSource::class,
            ],
        ]);

        Configuration::setPreLoadedConfig($sourceConfig, 'authsources.php');
    }


    /**
     * Test no state.
     *
     * @return void
     */
    public function testNoState(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(
            self::URI_LOGIN,
            'GET',
        );

        $c = new Controller\SinglepageController($this->config, $this->session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage('Missing AuthState parameter.');

        $c->main($request);
    }


    /**
     * Test no authsource selected.
     *
     * @return void
     */
    public function testNoAuthSource(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $stateParams = ['AuthState' => 'abc123'];
        $request = Request::create(
            self::URI_LOGIN,
            'GET',
            $stateParams,
        );

        $c = new Controller\SinglepageController($this->config, $this->session);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [];
            }
        });
        $response = $c->main($request);

        $this->assertTrue($response->isSuccessful());
        $this->assertInstanceOf(Template::class, $response);
        $this->assertNull($response->data['errorcode']);
        $this->assertEquals($stateParams, $response->data['stateParams']);
    }


    /**
     * Test that an unknown authsource is reported as a BADREQUEST error code.
     *
     * @return void
     */
    public function testWrongAuthSource(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(
            self::URI_LOGIN,
            'GET',
            ['AuthState' => 'abc123', 'authsource' => 'does-not-exist'],
        );

        $c = new Controller\SinglepageController($this->config, $this->session);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [];
            }
        });
        $response = $c->main($request);

        $this->assertInstanceOf(Template::class, $response);
        $this->assertSame('BADREQUEST', $response->data['errorcode']);
    }


    /**
     * The configured sources from the state are described and passed to the template.
     *
     * @return void
     */
    public function testConfiguredSourcesReachTheTemplate(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(self::URI_LOGIN, 'GET', ['AuthState' => 'abc123']);

        $c = new Controller\SinglepageController($this->config, $this->session);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [Multiauthsinglepage::SOURCESID => ['success-as']];
            }
        });
        $response = $c->main($request);

        $this->assertSame(
            [['id' => 'success-as', 'label' => 'success-as', 'userpass' => true]],
            $response->data['sources'],
        );
    }


    /**
     * An authsource that is not in the configured "sources" list is rejected.
     *
     * @return void
     */
    public function testAuthSourceOutsideConfiguredListIsRejected(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(
            self::URI_LOGIN,
            'GET',
            ['AuthState' => 'abc123', 'authsource' => 'dummy-as'],
        );

        $c = new Controller\SinglepageController($this->config, $this->session);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [Multiauthsinglepage::SOURCESID => ['success-as']];
            }
        });
        $response = $c->main($request);

        $this->assertSame('BADREQUEST', $response->data['errorcode']);
    }


    /**
     * A non-LDAP username/password source is authenticated inline, not by redirect:
     * posting it without credentials yields WRONGUSERPASS.
     *
     * @return void
     */
    public function testUserPassSourceIsAuthenticatedInline(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(
            self::URI_LOGIN,
            'POST',
            ['AuthState' => 'abc123', 'authsource' => 'success-as'],
        );

        $c = new Controller\SinglepageController($this->config, $this->session);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [Multiauthsinglepage::SOURCESID => ['success-as']];
            }
        });
        $response = $c->main($request);

        $this->assertSame('WRONGUSERPASS', $response->data['errorcode']);
    }


    /**
     * After more than three consecutive failed attempts for the same username (in
     * the same browser session), LoginThrottle blocks further attempts locally
     * with an exponential backoff wait -- entirely independent of "accessControl".
     * The blocked attempt never even reaches the authentication source, and (unlike
     * an accessControl-triggered block) the login page shows a distinct "please
     * wait" message instead of WRONGUSERPASS: this local throttle does not depend
     * on a real username existing, so showing it does not enable enumeration.
     *
     * @return void
     */
    public function testRepeatedFailuresAreThrottledLocallyAfterThreeAttempts(): void
    {
        FailAuthSource::$callCount = 0;
        $username = 'alice-' . uniqid();
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;

        $makeRequest = fn (): Request => Request::create(
            self::URI_LOGIN,
            'POST',
            [
                'AuthState' => 'abc123',
                'authsource' => 'fail-as',
                'username' => $username,
                'password' => 'wrong',
            ],
        );
        $c = new Controller\SinglepageController($this->config, $this->session);
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [
                    Multiauthsinglepage::SOURCESID => ['fail-as'],
                    Multiauthsinglepage::AUTHID => 'singlepage-as',
                ];
            }
        });

        // The first four attempts are genuinely tried against the source (three
        // free, the fourth is the one that pushes the count past the threshold).
        for ($i = 0; $i < 4; $i++) {
            $response = $c->main($makeRequest());
            $this->assertSame('WRONGUSERPASS', $response->data['errorcode']);
        }
        $this->assertSame(4, FailAuthSource::$callCount);

        // The fifth attempt is blocked by LoginThrottle before it ever reaches the
        // source: the call count does not increase, and the user sees the distinct
        // "please wait" error code instead of WRONGUSERPASS.
        $response = $c->main($makeRequest());
        $this->assertSame(LoginThrottle::ERROR_CODE, $response->data['errorcode']);
        $this->assertSame(4, FailAuthSource::$callCount);
    }


    /**
     * When the access-control webservice reports "too many requests" (429), the
     * login attempt is not even tried against the authentication source, and the
     * user sees the same generic wrong-password error as any other rejected
     * attempt: the block itself is never surfaced to them, only written to the
     * (server-side) log by AccessBackoff::registerBlock().
     *
     * @return void
     */
    public function testAccessControlBlocksLoginAttemptWithoutRevealingIt(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(
            self::URI_LOGIN,
            'POST',
            [
                'AuthState' => 'abc123',
                'authsource' => 'success-as',
                'username' => 'alice',
                'password' => 'secret',
            ],
        );

        $c = new class ($this->config, $this->session) extends Controller\SinglepageController {
            protected function makeAccessChecker(array $config): AccessChecker
            {
                return new AccessChecker(['url' => 'https://webservice.example.org/endpoint'], fn (): int => 429);
            }
        };
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [Multiauthsinglepage::SOURCESID => ['success-as']];
            }
        });
        $response = $c->main($request);

        $this->assertSame('WRONGUSERPASS', $response->data['errorcode']);
    }


    /**
     * When the access-control webservice allows the attempt, the login proceeds
     * as usual (here failing on the missing credentials, as in
     * testUserPassSourceIsAuthenticatedInline).
     *
     * @return void
     */
    public function testAccessControlAllowsLoginWhenNotBlocked(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(
            self::URI_LOGIN,
            'POST',
            ['AuthState' => 'abc123', 'authsource' => 'success-as'],
        );

        $c = new class ($this->config, $this->session) extends Controller\SinglepageController {
            protected function makeAccessChecker(array $config): AccessChecker
            {
                return new AccessChecker(['url' => 'https://webservice.example.org/endpoint'], fn (): int => 200);
            }
        };
        $c->setAuthState(new class () extends Auth\State {
            public static function loadState(string $id, string $stage, bool $allowMissing = false): ?array
            {
                return [Multiauthsinglepage::SOURCESID => ['success-as']];
            }
        });
        $response = $c->main($request);

        $this->assertSame('WRONGUSERPASS', $response->data['errorcode']);
    }
}
