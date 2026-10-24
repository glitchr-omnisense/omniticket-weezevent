<?php

namespace Omniticket\Weezevent;

use Omniticket\Config;
use Omniticket\Exception\InvalidConfigException;
use Omniticket\ProviderFactory;
use Omniticket\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Weezevent, from its options:
 *
 *   api_key       the partner API key Weezevent gives (access is limited to registered partners)
 *   access_token  the organizer's token (persistent: POST /auth/access_token once and keep it) - or:
 *   username, password  the organizer's login, exchanged for a token at the first call
 *   timezone      the zone Weezevent's dates are in (Europe/Paris): it gives local times without one
 *   currency      the currency of its prices (EUR): its answers do not name it
 *   url           https://api.weezevent.com
 */
final class WeezeventFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omniticket.factory_name' => 'weezevent',
            'omniticket.factory_title' => 'Weezevent',
            'omniticket.required_options' => ['api_key'],
            'access_token' => '',
            'username' => '',
            'password' => '',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'url' => 'https://api.weezevent.com',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $token = $c->string('access_token');
        if (null === $token && (null === $c->string('username') || null === $c->string('password'))) {
            throw new InvalidConfigException('The "weezevent" provider needs: access_token, or username and password.');
        }

        return new WeezeventProvider(
            $this->http ?? throw new InvalidConfigException('The "weezevent" provider needs an HTTP client: give one to WeezeventFactory.'),
            (string) $c->string('api_key'),
            $token,
            $c->string('username'),
            $c->string('password'),
            new \DateTimeZone($c->string('timezone') ?? 'Europe/Paris'),
            $c->string('currency') ?? 'EUR',
            rtrim($c->string('url') ?? 'https://api.weezevent.com', '/'),
        );
    }
}
