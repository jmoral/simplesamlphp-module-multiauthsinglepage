<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage\Auth\Source;

use Exception;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Auth;
use SimpleSAML\Error;
use SimpleSAML\Module;
use SimpleSAML\Module\ldap\Auth\Source\Ldap;
use SimpleSAML\Utils\HTTP;

class Multiauthsinglepage extends Auth\Source
{
    /**
     * The key of the AuthId field in the state.
     */
    public const AUTHID = '\SimpleSAML\Module\multiauthsinglepage\Auth\Source\MultiAuth.AuthId';

    /**
     * The string used to identify our states.
     */
    public const STAGEID = '\SimpleSAML\Module\multiauthsinglepage\Auth\Source\MultiAuth.StageId';

    /**
     * The key where the sources is saved in the state.
     */
    public const SOURCESID = '\SimpleSAML\Module\multiauthsinglepage\Auth\Source\MultiAuth.SourceId';

    /**
     * The key where the selected source is saved in the session.
     */
    public const SESSION_SOURCE = 'multiauth:selectedSource';

    /**
     * @var string[] $sources
     */
    private array $sources;


    /**
     * @param array<mixed> $info
     * @param array<mixed> $config
     */
    public function __construct(array $info, array $config)
    {
        parent::__construct($info, $config);

        Assert::keyExists(
            $config,
            'sources',
            'The required "sources" config option was not found',
            Error\Exception::class,
        );

        $this->sources = $config['sources'];
    }

    /**
     * Prompt the user with a list of authentication sources.
     *
     * @param array &$state Information about the current authentication.
     */
    public function authenticate(array &$state): void
    {
        // We are going to need the authId in order to retrieve this authentication source later
        $state[self::AUTHID] = $this->authId;

        $id = Auth\State::saveState($state, self::STAGEID);
        $url = Module::getModuleURL('multiauthsinglepage/login');
        $httpUtils = new HTTP();
        $httpUtils->redirectTrustedURL($url, ['AuthState' => $id]);
    }

    /**
     * Handle login request.
     *
     * This function is used by the login form (core/www/loginuserpass.php) when the user
     * enters a username and password. On success, it will not return. On wrong
     * username/password failure, it will return the error code. Other failures will throw an
     * exception.
     *
     * @param string $authStateId  The identifier of the authentication state.
     * @param Auth/Source $source the authentication source.
     * @return string|void Error code in the case of an error.
     */
    public static function handleLogin(Auth\Source $source, array $state)
    {
        if (is_null($state)) {
            throw new Error\NoState();
        }

        $source->authenticate($state);
        Auth\Source::completeAuth($state);
        assert(false);
    }

    public static function handleLoginPass(Ldap $source, array $state, $username, $pass)
    {
        if (is_null($state)) {
            throw new Error\NoState();
        }
        $class = new \ReflectionClass('SimpleSAML\Module\ldap\Auth\Source\Ldap');
        $myProtectedMethod = $class->getMethod('login');
        $myProtectedMethod->setAccessible(true);
        $result = $myProtectedMethod->invokeArgs($source, [$username, $pass]);
        $state['Attributes'] = $result;
        //$source->login($username, $pass);
        Auth\Source::completeAuth($state);
        assert(false);
    }

    /**
     * @param \SimpleSAML\Auth\Source $as
     * @param array $state
     */
    public static function doAuthentication(Auth\Source $as, array $state, string $username, string $pass): void
    {
        try {
            if ($as instanceof Ldap) {
                $class = new \ReflectionClass('Ldap');
                $myProtectedMethod = $class->getMethod('login');
                $myProtectedMethod->setAccessible(true);
                $result = $myProtectedMethod->invokeArgs($as, [$username, $pass]);
            } else {
                $as->authenticate($state);
            }
            return;
        } catch (Error\Exception $e) {
            Auth\State::throwException($state, $e);
        } catch (Exception $e) {
            $e = new Error\UnserializableException($e);
            Auth\State::throwException($state, $e);
        }

        parent::completeAuth($state);
    }

    public static function loginCompleted(array $state): void
    {
        //empty
    }
}
