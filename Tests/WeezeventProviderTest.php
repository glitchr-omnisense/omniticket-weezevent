<?php

namespace Omniticket\Weezevent\Tests;

use Omniticket\Exception\InvalidConfigException;
use Omniticket\Exception\InvalidKeyException;
use Omniticket\Model\AttendeeStatus;
use Omniticket\Model\CheckResult;
use Omniticket\Model\Event;
use Omniticket\Model\EventQuery;
use Omniticket\Model\EventStatus;
use Omniticket\Model\Rate;
use Omniticket\Registry;
use Omniticket\Testing\RecordedHttpClient;
use Omniticket\Weezevent\WeezeventFactory;
use Omniticket\Weezevent\WeezeventProvider;
use PHPUnit\Framework\TestCase;

/**
 * Weezevent's answers are the example responses of its documentation
 * (https://api.weezevent.com, read on 2026-10-24), as published. No
 * account was used: Weezevent gives API keys to registered partners.
 */
final class WeezeventProviderTest extends TestCase
{
    public function testEvents(): void
    {
        $http = self::http();
        $events = [...self::provider($http)->events(new EventQuery(past: true))];

        self::assertSame([['13824', 'Event 1', 'on_sale', 0], ['11435', 'Event 2', 'upcoming', 24]], array_map(static fn (Event $e) => [$e->id, $e->title, $e->status->value, $e->availability->sold], $events));
        self::assertSame('2011-10-10T00:00:00+02:00', $events[0]->start?->format(\DATE_ATOM));
        self::assertSame(['api_key' => 'example-api-key', 'access_token' => 'example-token', 'include_closed' => 'true', 'include_without_sales' => 'true'], $http->requests[0]['options']['query']);
    }

    public function testAnEventWhole(): void
    {
        $event = self::provider(self::http())->event('11435');

        self::assertSame(['API API API', '2013-06-27T09:00:00+02:00', 'ended', 'http://weezevent.dev/api-api-api', 'Bureau, PAris', '142.00'], [$event->title, $event->start?->format(\DATE_ATOM), $event->status->value, $event->url, $event->venue, $event->priceFrom?->major()]);
        self::assertSame([
            ['75632', 'my default price', '10.00', 290, null],
            ['ABCDE125632', 'Tarif 2', '12.00', 400, null],
            ['44272', 'b2', '9.99', null, 'Categorie 1'],
            ['65632', 'b3', '0.00', null, 'Categorie 1 > Sous Categorie 1'],
            ['65638', 'b4', '10.00', null, 'Categorie 1 > Sous Categorie 1'],
        ], array_map(static fn (Rate $r) => [$r->id, $r->label, $r->price?->major(), $r->availability->remaining, $r->category], $event->rates), 'categories flattened; a quota of 0 is no quota');
        self::assertSame(['138', '139'], array_map(static fn ($s) => $s->id, $event->sessions));
        self::assertSame('0.00', $event->lowestPrice()?->major());
    }

    public function testParticipants(): void
    {
        $http = self::http();
        $attendees = [...self::provider($http)->attendees('49015', new \DateTimeImmutable('2014-01-23 11:00:00 UTC'))];

        $query = $http->requests[1]['options']['query'];
        self::assertIsArray($query);
        self::assertSame(['49015'], $query['id_event']);
        self::assertSame('2014-01-23 12:00:00', $query['last_update'], 'in Weezevent\'s zone');
        self::assertSame([['7759645', '7448370', 'valid', '198251'], ['7759646', '7448371', 'refunded', '198251']], array_map(static fn ($a) => [$a->id, $a->code, $a->status->value, $a->rate], $attendees));
        self::assertSame('C15E265226O63029', $attendees[0]->orderId);
    }

    public function testCheckAmongTheParticipants(): void
    {
        $provider = self::provider(self::http());

        self::assertSame(CheckResult::Valid, $provider->check('49015', '7448370')->result);
        self::assertSame(CheckResult::Void, $provider->check('49015', '7448371')->result);
        self::assertSame(CheckResult::Unknown, $provider->check('49015', '1')->result);
        $scanned = str_replace('"status": "0",
                "scan_date": "0000-00-00 00:00:00"', '"status": "1",
                "scan_date": "2014-01-24 20:05:00"', self::fixture('participants'));
        $check = self::provider(RecordedHttpClient::routes(['GET /tickets' => self::fixture('tickets'), 'GET /participant/list' => $scanned]))->check('49015', '7448370');
        self::assertSame([CheckResult::AlreadyUsed, AttendeeStatus::CheckedIn, '2014-01-24T20:05:00+01:00'], [$check->result, $check->attendee?->status, $check->attendee?->checkedInAt?->format(\DATE_ATOM)]);
    }

    public function testATokenFromTheLogin(): void
    {
        $http = self::http();
        [...(new WeezeventProvider($http, 'example-api-key', null, 'organizer@example.org', 'not-a-real-password'))->events(new EventQuery(past: true))];

        self::assertSame(['POST', '/auth/access_token'], [$http->requests[0]['method'], parse_url($http->requests[0]['url'], \PHP_URL_PATH)]);
        self::assertSame(['username' => 'organizer@example.org', 'password' => 'not-a-real-password', 'api_key' => 'example-api-key'], $http->requests[0]['options']['body']);
        $query = $http->requests[1]['options']['query'];
        self::assertIsArray($query);
        self::assertSame('f4b6b917cee00f13d57e3c84f56656b9', $query['access_token'], 'the documentation\'s example token');
    }

    public function testARefusedKey(): void
    {
        $this->expectException(InvalidKeyException::class);
        [...self::provider(RecordedHttpClient::routes(['GET /events' => [403, '{"error":{"message":"Invalid api key"}}']]))->events()];
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new WeezeventFactory(self::http())], [
            'w' => ['factory' => 'weezevent', 'options' => ['api_key' => 'example-api-key', 'access_token' => 'example-token']],
            'nothing' => ['factory' => 'weezevent', 'options' => ['api_key' => 'example-api-key']],
        ]);

        self::assertSame(EventStatus::OnSale, [...$registry->catalog('w')->events(new EventQuery(past: true))][0]->status);
        $this->expectException(InvalidConfigException::class);
        $registry->get('nothing');
    }

    private static function provider(RecordedHttpClient $http): WeezeventProvider
    {
        return new WeezeventProvider($http, 'example-api-key', 'example-token');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            'POST /auth/access_token' => self::fixture('access-token'),
            'GET /events' => self::fixture('events'),
            'GET /details' => self::fixture('details'),
            'GET /tickets' => self::fixture('tickets'),
            'GET /dates' => self::fixture('dates'),
            'GET /participant/list' => self::fixture('participants'),
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json');
    }
}
