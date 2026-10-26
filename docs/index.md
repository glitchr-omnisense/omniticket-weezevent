# omniticket/weezevent

Weezevent for [omnisense/omniticket](https://github.com/glitchr-omnisense/omniticket): an
organizer's events, their rates (nested in categories), their sessions, their participants, and a
ticket code checked - through Weezevent's API (`https://api.weezevent.com`, documented on that
page), with the partner's `api_key` and the organizer's `access_token`. Reading only: nothing is
written, no ticket marked as scanned. No PHP client of Weezevent's API was found to reuse.

| Operation | Weezevent API |
|---|---|
| token | `POST /auth/access_token` (username, password, api_key), when no token is given |
| `events()` | `GET /events` (`include_closed`, `include_without_sales=true`) |
| `event($id)` | `GET /event/{id}/details`, `GET /tickets?id_event[]=`, `GET /dates?id_event[]=&display_passed=true` |
| `attendees($id, $since)` | `GET /participant/list` (`include_deleted`, `include_unpaid`, `last_update`), and `/tickets` once for the rates' names |
| `check($id, $code)` | the participants, read |
| `eventCategories()` | `GET /event/categories`, no key |

## Installation

```sh
composer require omniticket/weezevent symfony/http-client
```

Requires PHP 8.2, `omnisense/omniticket` (1.x) and `symfony/http-client-contracts` (^3.0).

## Credentials

- The **partner API key** Weezevent gives: access is limited to registered partners.
- The organizer's **access token**, persistent: `POST /auth/access_token` once and keep it - or
  the organizer's **username and password**, exchanged for a token at the first call.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the partner key |
| `access_token` | string | none | this, or `username` and `password` | the organizer's token |
| `username`, `password` | string | none | (see above) | the organizer's login |
| `timezone` | string | `Europe/Paris` | no | the zone Weezevent's dates are in: it gives local times without one |
| `currency` | string | `EUR` | no | the currency of its prices: its answers do not name it |
| `url` | string | `https://api.weezevent.com` | no | |

## Capabilities

| Contract | Supported |
|---|---|
| `EventCatalogInterface` | yes |
| `AttendeeListInterface` | yes, incremental (`last_update`) |
| `TicketCheckInterface` | yes, among the participants |

`capabilities()`: catalog, attendees, check and incremental true; no widget; terms
`https://api.weezevent.com`; limits: partners only; fair use - store what is read locally, filter
queries (`last_update`); an abusive key may be restricted.

## Behaviour

**Token.** The given `access_token`, or one asked for at the first call with the login (the
answer's `accessToken`) and kept for the provider's life. Every call sends `api_key` and
`access_token` as query parameters.

**Events.** `include_closed` true with a query's `past`. Each: `id`, `name`, `date.start` and
`date.end` in `timezone`, `participants` as sold. Status: sales status 1 on sale, 2 upcoming,
else ended when its end (else start) is past, else unknown.

**One event.** Three calls. The details: `title`, `period.start` and `.end`, the venue's name and
city, `extras.minisite_url` (else `site_url`) as the URL, `price.min` and `price.max` as the
range; an answer without an id is a `NotFoundException`. The tickets, flattened across their
categories (the category path, `Categorie 1 > Sous Categorie 1`, as the rate's category): `id`,
`name`, `price` in units of `currency`, the availability from `quota` (0 or none: no quota) and
`participants`, `start_sale`, `end_sale`. The dates as sessions (id, start). Status: ended by
date, upcoming before the sale period, sales closed after it, else on sale.

**Participants.** Deleted and unpaid ones included; `last_update` (in `timezone`, `Y-m-d H:i:s`)
with `$since`. Each: `id_participant`, `barcode`, the owner's names and e-mail, the rate's name
(by `id_ticket`, from the rates read once per event and kept), `transaction_reference` as the
order, `id_date` as the session (0: none). Status: deleted - cancelled; refund - refunded; not
paid - pending; `control_status.status` 1 - checked in (`scan_date` as when); else valid.

**Check.** No lookup by barcode: the event's participants read, the code looked for among them:
two calls the first time (the participants, the rates' names), one after. An application checking
many tickets keeps the list itself. Whether a very large participant list is cut without paging is
not known (`max` and `page` are documented without their base).

**Event categories.** `eventCategories()`, on the provider's class, gives Weezevent's categories
by id (`name_other`, else `name`): the one call the documentation marks as public.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omniticket\Registry;
use Omniticket\Weezevent\WeezeventFactory;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new WeezeventFactory(HttpClient::create(['timeout' => 20]))], [
    'festival' => ['factory' => 'weezevent', 'options' => ['api_key' => getenv('WEEZEVENT_API_KEY'), 'access_token' => getenv('WEEZEVENT_ACCESS_TOKEN')]],
]);

$event = $registry->catalog('festival')->event('123456');
foreach ($event->rates as $rate) {
    echo $rate->category, ' / ', $rate->label, ': ', $rate->price?->major(), ' ', $rate->price?->currency, "\n";
}
echo $registry->checker('festival')->check('123456', $scannedCode)->result->value, "\n";
```

Not run live (no partner key). Without a token or a login, on 2026-10-26: `The "weezevent"
provider needs: access_token, or username and password.`

## Errors

- 401, 403: `InvalidKeyException`; 404 or an event without details: `NotFoundException`; another
  status of 400 or more, or an answer that is not JSON: `ProviderException`, the status as its
  code; no token in the login's answer: `ProviderException` `Weezevent gave no access token.`
- Neither a token nor a login, or no HTTP client: `InvalidConfigException` when the provider is
  built.
- A network failure: `UnreachableException`.

## Verified, and not

`Tests/WeezeventProviderTest.php`, against the example responses of Weezevent's documentation, as
published (read on 2026-10-24): events and their sales status, one event whole (rates across
categories, quota 0 read as no quota, sessions, price range), participants with `last_update` in
Weezevent's zone, checks valid, refunded, scanned and unknown, the token from the login, a refused
key; `BareTest`. Green in the family's harness on 2026-10-26. Live (README, 2026-10-24): `GET
/event/categories`, no key. Not verified live: everything that needs a partner key.

## Responsibility

This package is an independent, unofficial client, affiliated with Weezevent in no way. Whoever
runs it keeps Weezevent's partner rules ([api.weezevent.com](https://api.weezevent.com)); see the
README.
