<?php

namespace Omniticket\Weezevent;

use Omniticket\AttendeeListInterface;
use Omniticket\Capabilities;
use Omniticket\EventCatalogInterface;
use Omniticket\Exception\NotFoundException;
use Omniticket\Exception\ProviderException;
use Omniticket\Http\JsonApi;
use Omniticket\Model\Attendee;
use Omniticket\Model\AttendeeStatus;
use Omniticket\Model\Availability;
use Omniticket\Model\Check;
use Omniticket\Model\Event;
use Omniticket\Model\EventQuery;
use Omniticket\Model\EventStatus;
use Omniticket\Model\Money;
use Omniticket\Model\Rate;
use Omniticket\Model\Session;
use Omniticket\Support\Data;
use Omniticket\TicketCheckInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Weezevent's API (https://api.weezevent.com, documented on that page):
 * GET /events, /event/{id}/details, /tickets, /dates and /participant/list
 * with the partner's api_key and the organizer's access_token. Reading
 * only: nothing is written, no ticket marked as scanned.
 */
final class WeezeventProvider implements EventCatalogInterface, AttendeeListInterface, TicketCheckInterface
{
    private readonly JsonApi $api;

    /** @var array<string, array<string, string>> ticket names by event */
    private array $rateNames = [];

    public function __construct(
        HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $apiKey,
        #[\SensitiveParameter] private ?string $accessToken = null,
        private readonly ?string $username = null,
        #[\SensitiveParameter] private readonly ?string $password = null,
        private readonly \DateTimeZone $timezone = new \DateTimeZone('Europe/Paris'),
        private readonly string $currency = 'EUR',
        private readonly string $url = 'https://api.weezevent.com',
    ) {
        $this->api = new JsonApi($http, 'Weezevent');
    }

    public function getName(): string
    {
        return 'weezevent';
    }

    public function getTitle(): string
    {
        return 'Weezevent';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            catalog: true,
            attendees: true,
            check: true,
            incremental: true,
            terms: ['https://api.weezevent.com'],
            limits: ['partners only: the api_key is given to registered Weezevent partners', 'fair use: store what is read locally, filter queries (last_update); an abusive key may be restricted'],
        );
    }

    public function events(EventQuery $query = new EventQuery()): iterable
    {
        $answer = $this->get('/events', ['include_closed' => $query->past ? 'true' : 'false', 'include_without_sales' => 'true'], 'the events');

        return $query->filter(array_map(fn (Data $line) => new Event(
            (string) $line->string('id'),
            $line->string('name') ?? '',
            $line->date('date.start', $this->timezone),
            $line->date('date.end', $this->timezone),
            match ($line->int('sales_status.id_status')) {
                1 => EventStatus::OnSale,
                2 => EventStatus::Upcoming,
                default => self::ended($line->date('date.end', $this->timezone) ?? $line->date('date.start', $this->timezone)) ? EventStatus::Ended : EventStatus::Unknown,
            },
            availability: new Availability(sold: $line->int('participants')),
            provider: 'weezevent',
        ), $answer->list('events')));
    }

    public function event(string $id): Event
    {
        $details = $this->get('/event/'.rawurlencode($id).'/details', [], 'event "'.$id.'"')->data('events');
        if (null === $details->string('id')) {
            throw NotFoundException::of('Weezevent', 'event "'.$id.'"');
        }
        $tickets = [];
        foreach ($this->get('/tickets', ['id_event' => [$id]], 'the rates of event "'.$id.'"')->list('events') as $event) {
            if ($event->string('id') === $id) {
                $tickets = self::flatten($event, null);
            }
        }
        $rates = array_map(fn (array $ticket) => new Rate(
            (string) $ticket[0]->string('id'),
            $ticket[0]->string('name') ?? '',
            null !== ($price = $ticket[0]->string('price')) ? Money::fromMajor($price, $this->currency) : null,
            $ticket[0]->int('quota') > 0 ? Availability::of(null, $ticket[0]->int('quota'), $ticket[0]->int('participants') ?? 0) : new Availability(sold: $ticket[0]->int('participants')),
            $ticket[0]->date('start_sale', $this->timezone),
            $ticket[0]->date('end_sale', $this->timezone),
            category: $ticket[1],
        ), $tickets);
        $sessions = [];
        foreach ($this->get('/dates', ['id_event' => [$id], 'display_passed' => 'true'], 'the dates of event "'.$id.'"')->list('dates') as $date) {
            if ($date->string('id_event') === $id) {
                $sessions[] = new Session((string) $date->string('id'), $date->date('date', $this->timezone));
            }
        }
        $start = $details->date('period.start', $this->timezone);
        $end = $details->date('period.end', $this->timezone);
        $salesStart = $details->date('sale_period.start', $this->timezone);
        $salesEnd = $details->date('sale_period.end', $this->timezone);
        $now = new \DateTimeImmutable();
        $venue = implode(', ', array_filter([$details->string('venue.name'), $details->string('venue.city')]));

        return new Event(
            $id,
            $details->string('title') ?? '',
            $start,
            $end,
            match (true) {
                self::ended($end ?? $start) => EventStatus::Ended,
                null !== $salesStart && $now < $salesStart => EventStatus::Upcoming,
                null !== $salesEnd && $now > $salesEnd => EventStatus::SalesClosed,
                default => EventStatus::OnSale,
            },
            $details->string('extras.minisite_url') ?? $details->string('site_url'),
            '' !== $venue ? $venue : null,
            rates: $rates,
            sessions: $sessions,
            priceFrom: null !== ($min = $details->string('price.min')) ? Money::fromMajor($min, $this->currency) : null,
            priceTo: null !== ($max = $details->string('price.max')) ? Money::fromMajor($max, $this->currency) : null,
            provider: 'weezevent',
        );
    }

    public function attendees(string $eventId, ?\DateTimeImmutable $updatedSince = null): iterable
    {
        $query = ['id_event' => [$eventId], 'include_deleted' => 'true', 'include_unpaid' => 'true']
            + (null !== $updatedSince ? ['last_update' => $updatedSince->setTimezone($this->timezone)->format('Y-m-d H:i:s')] : []);
        $names = $this->rateNames($eventId);

        return array_map(function (Data $line) use ($eventId, $names): Attendee {
            $scanned = '1' === $line->string('control_status.status');

            return new Attendee(
                (string) $line->string('id_participant'),
                $eventId,
                $line->string('barcode'),
                $line->string('owner.first_name'),
                $line->string('owner.last_name'),
                $line->string('owner.email'),
                $names[(string) $line->string('id_ticket')] ?? $line->string('id_ticket'),
                match (true) {
                    $line->bool('deleted') => AttendeeStatus::Cancelled,
                    $line->bool('refund') => AttendeeStatus::Refunded,
                    $line->has('paid') && !$line->bool('paid') => AttendeeStatus::Pending,
                    $scanned => AttendeeStatus::CheckedIn,
                    default => AttendeeStatus::Valid,
                },
                $scanned ? $line->date('control_status.scan_date', $this->timezone) : null,
                $line->string('transaction_reference'),
                0 !== $line->int('id_date') ? $line->string('id_date') : null,
            );
        }, $this->get('/participant/list', $query, 'the participants of event "'.$eventId.'"')->list('participants'));
    }

    /** Weezevent has no lookup by barcode: the event's participants are read, and the code looked for among them. */
    public function check(string $eventId, string $code): Check
    {
        return Check::among($this->attendees($eventId), $code);
    }

    /**
     * Weezevent's event categories ("Concerts", "Festivals"...), by id: the
     * one call its documentation marks as public, no key needed.
     *
     * @return array<string, string>
     */
    public function eventCategories(): array
    {
        $categories = [];
        foreach ($this->api->request('GET', $this->url.'/event/categories', [], 'the event categories')->list('event_categories') as $category) {
            $categories[(string) $category->string('id')] = $category->string('name_other') ?? (string) $category->string('name');
        }

        return $categories;
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query, string $what): Data
    {
        return $this->api->request('GET', $this->url.$path, ['query' => ['api_key' => $this->apiKey, 'access_token' => $this->token()] + $query], $what);
    }

    private function token(): string
    {
        return $this->accessToken ??= (string) ($this->api->request('POST', $this->url.'/auth/access_token', ['body' => ['username' => (string) $this->username, 'password' => (string) $this->password, 'api_key' => $this->apiKey]], 'an access token')->string('accessToken')
            ?? throw new ProviderException('Weezevent gave no access token.'));
    }

    /** @return array<string, string> */
    private function rateNames(string $eventId): array
    {
        if (!isset($this->rateNames[$eventId])) {
            $this->rateNames[$eventId] = [];
            foreach ($this->get('/tickets', ['id_event' => [$eventId]], 'the rates of event "'.$eventId.'"')->list('events') as $event) {
                foreach (self::flatten($event, null) as [$ticket]) {
                    $this->rateNames[$eventId][(string) $ticket->string('id')] = $ticket->string('name') ?? '';
                }
            }
        }

        return $this->rateNames[$eventId];
    }

    /**
     * Tickets with the path of the categories holding them: "Categorie 1 > Sous Categorie 1".
     *
     * @return list<array{0: Data, 1: ?string}>
     */
    private static function flatten(Data $node, ?string $path): array
    {
        $tickets = array_map(static fn (Data $ticket) => [$ticket, $path], $node->list('tickets'));
        foreach ($node->list('categories') as $category) {
            $name = $category->string('name') ?? '';
            $tickets = [...$tickets, ...self::flatten($category, null !== $path ? $path.' > '.$name : $name)];
        }

        return $tickets;
    }

    private static function ended(?\DateTimeImmutable $last): bool
    {
        return null !== $last && $last < new \DateTimeImmutable();
    }
}
