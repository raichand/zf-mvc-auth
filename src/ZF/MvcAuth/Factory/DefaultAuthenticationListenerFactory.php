<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2013 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\MvcAuth\Factory;

use Zend\Authentication\Adapter\Http as HttpAuth;
use Zend\Http\Request;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use ZF\MvcAuth\Authentication\DefaultAuthenticationListener;
use ZF\OAuth2\Adapter\Pdo as OAuth2Storage;
use OAuth2\Server as OAuth2Server;
use OAuth2\GrantType\ClientCredentials;
use OAuth2\GrantType\AuthorizationCode;

/**
 * Factory for creating the DefaultAuthenticationListener from configuration
 */
class DefaultAuthenticationListenerFactory implements FactoryInterface
{
    /**
     * @param ServiceLocatorInterface $services
     * @return DefaultAuthenticationListener
     */
    public function createService(ServiceLocatorInterface $services)
    {
        $listener = new DefaultAuthenticationListener();

        $httpAdapter  = false;
        $oauth2Server = false;
        if ($services->has('config')) {
            $httpAdapter  = $this->createHttpAdapterFromConfig($services->get('config'));
            $oauth2Server = $this->createOauth2ServerFromConfig($services->get('config'));
        }

        if ($httpAdapter) {
            $listener->setHttpAdapter($httpAdapter);
        }

        if ($oauth2Server) {
            $listener->setOauth2Server($oauth2Server);
        }

        return $listener;
    }

    /**
     * @param array $config
     * @return false|HttpAuth
     */
    protected function createHttpAdapterFromConfig(array $config)
    {
        if (!isset($config['zf-mvc-auth']['authentication'])) {
            return false;
        }
        $authConfig = $config['zf-mvc-auth']['authentication'];

        if (!isset($authConfig['http'])) {
            return false;
        }

        $httpConfig = $authConfig['http'];

        if (!isset($httpConfig['accept_schemes']) || !is_array($httpConfig['accept_schemes'])) {
            throw new ServiceNotCreatedException('accept_schemes is required when configuring an HTTP authentication adapter');
        }

        if (!isset($httpConfig['realm'])) {
            throw new ServiceNotCreatedException('realm is required when configuring an HTTP authentication adapter');
        }

        if (in_array('digest', $httpConfig['accept_schemes'])) {
            if (!isset($httpConfig['digest_domains'])
                || !isset($httpConfig['nonce_timeout'])
            ) {
                throw new ServiceNotCreatedException('Both digest_domains and nonce_timeout are required when configuring an HTTP digest authentication adapter');
            }
        }

        $httpAdapter = new HttpAuth(array_merge($httpConfig, array('accept_schemes' => implode(' ', $httpConfig['accept_schemes']))));

        $hasFileResolver = false;

        // basic && htpasswd
        if (in_array('basic', $httpConfig['accept_schemes']) && isset($httpConfig['htpasswd'])) {
            $httpAdapter->setBasicResolver(new HttpAuth\ApacheResolver($httpConfig['htpasswd']));
            $hasFileResolver = true;
        }
        if (in_array('digest', $httpConfig['accept_schemes']) && isset($httpConfig['htdigest'])) {
            $httpAdapter->setDigestResolver(new HttpAuth\FileResolver($httpConfig['htdigest']));
            $hasFileResolver = true;
        }

        if ($hasFileResolver === false) {
            return false;
        }

        return $httpAdapter;
    }

    /**
     * Create an OAuth2 server from configuration
     *
     * @param  array $config
     * @return OAuth2Server
     */
    protected function createOauth2ServerFromConfig(array $config)
    {
        if (!isset($config['zf-mvc-auth']['authentication'])) {
            return false;
        }
        $authConfig = $config['zf-mvc-auth']['authentication'];

        if (!isset($authConfig['oauth2'])) {
            return false;
        }

        if (!isset($authConfig['oauth2']['db'])) {
            throw new ServiceNotCreatedException('db is required when configuring an OAuth2 authentication adapter');
        }
        $dbConfig = $authConfig['oauth2']['db'];

        if (!isset($dbConfig['dsn'])) {
            throw new ServiceNotCreatedException('dsn is required when configuring the db for OAuth2 authentication');
        }
        $username = isset($dbConfig['username']) ? $dbConfig['username'] : null;
        $password = isset($dbConfig['password']) ? $dbConfig['password'] : null;

        $storage = new OAuth2Storage(array(
            'dsn'      => $config['oauth2']['db']['dsn'],
            'username' => $username,
            'password' => $password,
        ));

        // Pass a storage object or array of storage objects to the OAuth2 server class
        $oauth2Server = new OAuth2Server($storage);
        // Add the "Client Credentials" grant type (it is the simplest of the grant types)
        $oauth2Server->addGrantType(new ClientCredentials($storage));
        // Add the "Authorization Code" grant type
        $oauth2Server->addGrantType(new AuthorizationCode($storage));

        return $oauth2Server;
    }
}
