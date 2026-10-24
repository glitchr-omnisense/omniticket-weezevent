<?php

namespace Omniticket\Weezevent\Tests;

use Omniticket\Weezevent\WeezeventProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Weezevent's API itself, on the one call its documentation marks as
 * public (GET /event/categories, no key): its host, its JSON and the
 * shape read. `docker compose run --rm omniticket live`; never in the
 * default run. Nothing else is reachable without a partner key.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    public function testThePublicCategories(): void
    {
        $categories = (new WeezeventProvider(HttpClient::create(['timeout' => 20]), 'no-key-needed-here', 'no-token-needed-here'))->eventCategories();

        self::assertGreaterThan(5, \count($categories));
        self::assertContains('Concerts', $categories);
    }
}
