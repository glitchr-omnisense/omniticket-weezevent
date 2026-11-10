<?php

namespace Omniticket\Weezevent\Tests;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omniticket\Weezevent\WeezeventFactory;
use Omniticket\Weezevent\WeezeventProvider;
use Omniticket\Exception\OmniticketException;
use Omniticket\Exception\UsageLimitReachedException;
use Omniticket\Registry;
use Omniticket\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * WeezeventProvider's usage policy (policy.json) through omniticket's registry:
 * as declared, a site's limit counted with the call over it never made,
 * a 429 and its Retry-After holding every next call, a tightening
 * accepted and a looser setting refused. No network: recorded answers only.
 */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['api_key' => 'example-key-not-real', 'access_token' => 'example-token-not-real'];

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = self::registry(RecordedHttpClient::routes([]))->policy('box');

        self::assertSame('weezevent', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertFileExists(WeezeventProvider::POLICY);
        self::assertSame(['600/minute/application/*', '10/minute/application/token'], array_map(static fn ($l) => $l->id(), $policy->rateLimits ?? []));
        self::assertTrue($policy->requirements->key);
        self::assertSame(Permission::Allowed, $policy->rights->storage);
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = RecordedHttpClient::routes([]);
        $registry = self::registry($http, ['rateLimits' => [['max' => 1, 'window' => 'day', 'operations' => ['event']]]]);

        try {
            $registry->catalog('box')->event('1');
        } catch (UsageLimitReachedException $e) {
            throw $e;
        } catch (OmniticketException) {
            // Nothing recorded: the provider's own answer to that, counted all the same.
        }
        $made = \count($http->requests);
        self::assertGreaterThan(0, $made);
        try {
            $registry->catalog('box')->event('1');
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmniticketException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/day/application/event', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['GET ' => [429, '{"error":"too many requests"}', ['retry-after' => ['120']]], 'POST ' => [429, '{"error":"too many requests"}', ['retry-after' => ['120']]]]);
        $registry = self::registry($http);

        try {
            $registry->catalog('box')->event('1');
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('weezevent answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        try {
            [...$registry->catalog('box')->events()];
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertCount(1, $http->requests, 'the wait held the next request back');
    }

    public function testASiteMayTightenThePolicyNotLoosenIt(): void
    {
        $tightened = self::registry(RecordedHttpClient::routes([]), ['rateLimits' => [['max' => 1, 'window' => 'minute']]])->policy('box');
        self::assertContains('1/minute/application/*', array_map(static fn ($l) => $l->id(), $tightened->rateLimits ?? []));

        $this->expectException(InvalidPolicyException::class);
        self::registry(RecordedHttpClient::routes([]), ['rateLimits' => [['max' => 6000, 'window' => 'minute']]])->catalog('box');
    }

    /**
     * The token request made inside the contract's calls is counted by its
     * own limit ("token"), in the limiter the registries share: one token per
     * provider built, the third refused before it is asked for.
     */
    public function testTheTokenRequestsAreCountedByTheirOwnLimit(): void
    {
        $http = RecordedHttpClient::routes(['POST /auth/access_token' => '{"accessToken":"t"}', 'GET /events' => '{"events":[]}']);
        $limiter = new \Omnisense\Contracts\Policy\InMemoryRateLimiter();
        $config = ['box' => ['factory' => 'weezevent', 'options' => ['api_key' => 'example-key-not-real', 'username' => 'u', 'password' => 'p'], 'policy' => ['rateLimits' => [['max' => 2, 'window' => 'minute', 'operations' => ['token']]]]]];

        [...(new Registry([new WeezeventFactory($http)], $config, $limiter))->catalog('box')->events()];
        [...(new Registry([new WeezeventFactory($http)], $config, $limiter))->catalog('box')->events()];
        try {
            [...(new Registry([new WeezeventFactory($http)], $config, $limiter))->catalog('box')->events()];
            self::fail('A third token was asked for over the limit.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('2/minute/application/token', $e->limit?->id());
        }
        self::assertCount(2, array_filter($http->requests, static fn (array $r) => 'POST' === $r['method']));
    }

    /** @param array<string, mixed> $policy */
    private static function registry(RecordedHttpClient $http, array $policy = []): Registry
    {
        return new Registry([new WeezeventFactory($http)], ['box' => ['factory' => 'weezevent', 'options' => self::OPTIONS, 'policy' => $policy]]);
    }
}
