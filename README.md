# omniticket/weezevent

[omniscient/omniticket](https://github.com/glitchr-studio/omniticket)'s Weezevent, under any PHP
host: an organizer's events with their rates (categories flattened), sessions and sales period,
the participants of an event (incrementally), and a ticket's barcode checked among them. Reading
only.

```php
use Omniticket\Registry;
use Omniticket\Weezevent\WeezeventFactory;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new WeezeventFactory(HttpClient::create())], [
    'festival' => ['factory' => 'weezevent', 'options' => ['api_key' => '...', 'access_token' => '...']],
]);
```

Options: `api_key` (Weezevent gives API keys to registered partners), `access_token` (persistent:
obtained once) or `username` and `password` (exchanged for a token at the first call), `timezone`
(Europe/Paris), `currency` (EUR), `url`.

## The API, as read on 2026-10-24

Weezevent's API (<https://api.weezevent.com>): `POST /auth/access_token`, then `api_key` and
`access_token` on `GET /events`, `/event/{id}/details`, `/tickets` (rates, nested in categories),
`/dates` (sessions) and `/participant/list` (`last_update` for what changed since,
`include_deleted`, `include_unpaid`; `control_status.status` 1 is scanned). `GET /event/categories`
needs no key. There is no lookup by barcode: `check()` reads the event's participants and looks
the code up among them.

**Terms**: access is limited to registered partners; fair use - store what is read locally,
filter queries; an abusive key may be restricted. What this package does: `event()` makes three
calls, `attendees()` two (the participants and the rates' names, kept for the provider's life),
`check()` the same two - an application checking many tickets keeps the list itself
(`attendees()` with `updatedSince`, then `Check::among()`). Not verified: whether a very large
participant list is cut without paging (`max` and `page` are documented without their base).

No PHP client of Weezevent's API was found to reuse.

## Verified, and not

- Verified (the core's harness, PHP 8.4): events and their sales status, one event whole (rates
  across categories, quota 0 read as no quota, sessions, price range), participants with
  `last_update` in Weezevent's zone, checks valid, refunded, scanned and unknown, the token from
  the login, a refused key - against the example responses of Weezevent's documentation, as
  published (non-breaking spaces of the page turned back into spaces); every class loading with the
  package's requirements alone (`BareTest`).
- Verified live (`docker compose run --rm omniticket live`, 2026-10-24): `GET /event/categories`,
  no key - the host and the JSON as read.
- Not verified live: everything that needs a partner key.

## Licence

MIT. An unofficial client, affiliated with Weezevent in no way.
