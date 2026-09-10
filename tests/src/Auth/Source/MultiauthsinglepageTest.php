<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\multiauthsinglepage\Auth\Source;

use PHPUnit\Framework\TestCase;
use SimpleSAML\Configuration;
use SimpleSAML\Error;
use SimpleSAML\Module\core\Auth\UserPassBase;
use SimpleSAML\Module\multiauthsinglepage\Auth\Source\Multiauthsinglepage;
use SimpleSAML\Session;

/**
 * Tests for the "multiauthsinglepage" authentication source.
 */
class MultiauthsinglepageTest extends TestCase
{
    private const string ENTITY_ID = 'urn:x-simplesamlphp:multiauthsinglepage-test-sp';


    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_URI'] = '/module.php/multiauthsinglepage/login';

        $config = Configuration::loadFromArray(
            ['module.enable' => ['multiauthsinglepage' => true]],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($config, 'config.php');
        Configuration::setPreLoadedConfig(Configuration::loadFromArray([]), 'authsources.php');
    }


    /**
     * @param array<mixed> $config
     */
    private function makeSource(array $config): Multiauthsinglepage
    {
        return new Multiauthsinglepage(['AuthId' => 'singlepage-as'], $config);
    }


    public function testConstructRequiresSourcesOption(): void
    {
        $this->expectException(Error\Exception::class);
        $this->expectExceptionMessage('The required "sources" config option was not found');

        $this->makeSource(['entityID' => self::ENTITY_ID]);
    }


    public function testConstructAcceptsValidConfig(): void
    {
        $source = $this->makeSource([
            'entityID' => self::ENTITY_ID,
            'sources' => ['ldap-as', 'other-as'],
        ]);

        $this->assertSame('singlepage-as', $source->getAuthId());
    }


    public function testHandleUserPassLoginRejectsMissingUsername(): void
    {
        try {
            Multiauthsinglepage::handleUserPassLogin($this->createStub(UserPassBase::class), [], null, 'secret');
            $this->fail('Expected an ' . Error\Error::class);
        } catch (Error\Error $e) {
            $this->assertSame(Error\ErrorCodes::WRONGUSERPASS, $e->getErrorCode());
        }
    }


    public function testHandleUserPassLoginRejectsMissingPassword(): void
    {
        try {
            Multiauthsinglepage::handleUserPassLogin($this->createStub(UserPassBase::class), [], 'alice', null);
            $this->fail('Expected an ' . Error\Error::class);
        } catch (Error\Error $e) {
            $this->assertSame(Error\ErrorCodes::WRONGUSERPASS, $e->getErrorCode());
        }
    }


    public function testSetSessionSourceStoresSelectedSource(): void
    {
        $session = Session::getSessionFromRequest();

        $source = $this->createStub(UserPassBase::class);
        $source->method('getAuthId')->willReturn('ldap-as');

        $state = [Multiauthsinglepage::AUTHID => 'singlepage-as'];
        Multiauthsinglepage::setSessionSource($source, $state);

        $this->assertSame(
            'ldap-as',
            $session->getData(Multiauthsinglepage::SESSION_SOURCE, 'singlepage-as'),
        );
    }


    public function testLogoutFailsWhenSelectedSourceIsUnknown(): void
    {
        $source = $this->makeSource([
            'entityID' => self::ENTITY_ID,
            'sources' => ['ldap-as'],
        ]);

        $state = [];

        $this->expectException(Error\Exception::class);
        $this->expectExceptionMessage('Invalid authentication source during logout');

        $source->logout($state);
    }
}
