<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage\Controller;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Auth;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Module\multiauthsinglepage\Controller;
use SimpleSAML\Session;
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

    private const URI_LOGIN = '/module.php/multiauthsinglepage/login';
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
            'simplesaml'
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
                'multiauthsinglepage:DummyAuthSource'
            ],
            'success-as' => [
                'multiauthsinglepage:SuccessAuthSource'
            ]
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
            'GET'
        );

        $c = new Controller\SinglepageController($this->config, $this->session);

        $this->expectException(Error\BadRequest::class);
        $this->expectExceptionMessage("BADREQUEST('%REASON%' => 'Missing AuthState parameter.')");

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
            $stateParams
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
        $this->assertEmpty($response->data['errorTitle']);
        $this->assertEquals($stateParams, $response->data['stateParams']);
    }

    /**
     * Test authsource selected.
     *
     * @return void
     */
    public function testAuthSourceSelected(): void
    {
        $_SERVER['REQUEST_URI'] = self::URI_LOGIN;
        $request = Request::create(
            self::URI_LOGIN,
            'GET',
            ['AuthState' => 'abc123', 'authsource' => 'singlepage-as']
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
        $this->assertEmpty($response->data['errorTitle']);
    }
}
