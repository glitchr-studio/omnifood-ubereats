<?php

namespace Omnifood\UberEats;

use Omnifood\Auth\OAuthInterface;
use Omnifood\Auth\RefreshableInterface;
use Omnifood\Channel;
use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidMenuException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\MenuInterface;
use Omnifood\Model\Capabilities;
use Omnifood\Model\Courier;
use Omnifood\Model\Customer;
use Omnifood\Model\DenyReason;
use Omnifood\Model\Hours;
use Omnifood\Model\Line;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Menu\Modifier as MenuModifier;
use Omnifood\Model\Menu\ModifierGroup;
use Omnifood\Model\Modifier;
use Omnifood\Model\Money;
use Omnifood\Model\Notification;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\Order;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use Omnifood\Model\StoreState;
use Omnifood\Model\StoreStatus;
use Omnifood\Model\Token;
use Omnifood\NotifiableInterface;
use Omnifood\OrdersInterface;
use Omnifood\StoreInterface;
use Omnifood\Validator;

/**
 * A store on Uber Eats, through the Marketplace APIs
 * (https://developer.uber.com/docs/eats/introduction): the Order
 * Fulfillment API Suite (/v1/delivery/order, for stores on webhooks
 * version 1.0.0), the Store API Suite (status), the Menu API (upload,
 * items suspended), the holiday hours (Store API, previous version), the
 * webhooks signed with the client secret, and the merchant's consent to
 * link the store (OAuth, scope eats.pos_provisioning).
 *
 * A new order (orders.notification) must be accepted or denied within
 * 11.5 minutes, or Uber cancels it. Only the application that is the
 * store's order manager may accept, deny or cancel.
 */
final class UberEatsPlatform implements OrdersInterface, MenuInterface, StoreInterface, NotifiableInterface, RefreshableInterface, OAuthInterface
{
    public const AUTHORIZE_URL = 'https://auth.uber.com/oauth/v2/authorize';
    public const PROVISIONING_SCOPES = ['eats.pos_provisioning'];

    private const EXPAND = 'carts,deliveries,payment';
    private const DAYS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
    private const DIETS = ['vegan' => 'VEGAN', 'vegetarian' => 'VEGETARIAN', 'gluten_free' => 'GLUTEN_FREE'];

    public function __construct(
        private readonly Api $api,
        private readonly ?string $storeId = null,
        private readonly ?string $clientSecret = null,
        private readonly string $language = 'en_us',
        private readonly bool $taxInclusive = true,
        private readonly Validator $validator = new Validator(),
    ) {
    }

    public function getName(): string
    {
        return 'ubereats';
    }

    public function getChannel(): Channel
    {
        return Channel::UBEREATS;
    }

    /**
     * A modifier group's options are items of the menu; the allergens are
     * free strings at Uber (the EU-14 names are sent), the dietary labels
     * VEGAN, VEGETARIAN, GLUTEN_FREE.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            orderTypes: [OrderType::DELIVERY, OrderType::PICKUP, OrderType::DINE_IN],
            acceptanceDelay: 690,
            readyAt: true,
            modifierDepth: 2,
            allergens: true,
            photos: true,
            vatRates: true,
            pauseUntil: true,
            modifiersAreItems: true,
        );
    }

    public function order(string $ref): Order
    {
        $data = $this->api->request('GET', '/v1/delivery/order/'.rawurlencode($ref), ['expand' => self::EXPAND]);

        return $this->toOrder((array) ($data['order'] ?? $data));
    }

    /** The store's orders created since then (the last 24 hours by default; Uber keeps 60 days), newest first. */
    public function orders(?\DateTimeImmutable $since = null): array
    {
        $since ??= new \DateTimeImmutable('-1 day');
        $orders = [];
        $next = null;
        do {
            $page = $this->api->request('GET', '/v1/delivery/store/'.rawurlencode($this->store()).'/orders', [
                'expand' => self::EXPAND,
                'start_time' => $since->format(\DateTimeInterface::RFC3339),
                'page_size' => 50,
                'next_page_token' => $next,
            ]);
            foreach ((array) ($page['data'] ?? []) as $entry) {
                $orders[] = $this->toOrder((array) ($entry['order'] ?? $entry));
            }
            $next = ($page['pagination_data']['next_page_token'] ?? '') ?: null;
        } while (null !== $next);
        usort($orders, static fn (Order $a, Order $b) => $b->placedAt <=> $a->placedAt);

        return $orders;
    }

    /** Accepted; $readyAt tells Uber when it will be ready for the courier. */
    public function accept(string $ref, ?\DateTimeImmutable $readyAt = null): void
    {
        $this->api->request('POST', '/v1/delivery/order/'.rawurlencode($ref).'/accept', [], null !== $readyAt
            ? ['ready_for_pickup_time' => $readyAt->format(\DateTimeInterface::RFC3339)]
            : new \ArrayObject());
    }

    public function deny(string $ref, DenyReason $reason, ?string $note = null): void
    {
        $this->api->request('POST', '/v1/delivery/order/'.rawurlencode($ref).'/deny', [], ['deny_reason' => self::reason($reason, $note)]);
    }

    public function ready(string $ref): void
    {
        $this->api->request('POST', '/v1/delivery/order/'.rawurlencode($ref).'/ready', [], new \ArrayObject());
    }

    public function cancel(string $ref, DenyReason $reason, ?string $note = null): void
    {
        $this->api->request('POST', '/v1/delivery/order/'.rawurlencode($ref).'/cancel', [], ['cancellation_reason' => self::reason($reason, $note)]);
    }

    /** A new time the order will be ready at (accepted, not yet ready, the courier not on the way). */
    public function updateReadyTime(string $ref, \DateTimeImmutable $readyAt): void
    {
        $this->api->request('POST', '/v1/delivery/order/'.rawurlencode($ref).'/update-ready-time', [], ['ready_for_pickup_time' => $readyAt->format(\DateTimeInterface::RFC3339)]);
    }

    /**
     * The store's menu replaced whole (Menu::$hours are its service hours:
     * the store's are the union of its menus'). Items marked unavailable
     * are suspended afterwards with setAvailability().
     */
    public function pushMenu(Menu $menu): void
    {
        if ($violations = $this->validator->validate($menu, $this->capabilities())) {
            throw new InvalidMenuException($this->getName(), $violations);
        }
        $this->api->request('PUT', '/v2/eats/stores/'.rawurlencode($this->store()).'/menus', [], $this->menuPayload($menu));
    }

    /** @return array<string, mixed> the JSON the upload takes for $menu */
    public function menuPayload(Menu $menu): array
    {
        $items = [];
        $groups = [];
        foreach ($menu->items() as $item) {
            $this->collect($item, $item->vatRate, $items, $groups);
        }
        $availability = [];
        if (null !== $menu->hours) {
            foreach (self::DAYS as $n => $day) {
                if ($ranges = $menu->hours->day($n)) {
                    $availability[] = ['day_of_week' => $day, 'time_periods' => array_map(static fn (array $r) => ['start_time' => $r[0], 'end_time' => $r[1]], $ranges)];
                }
            }
        }

        return [
            'menus' => [array_filter([
                'id' => $menu->ref ?? 'menu',
                'title' => $this->text($menu->name),
                'subtitle' => null !== $menu->description ? $this->text($menu->description) : null,
                'service_availability' => $availability ?: null,
                'category_ids' => array_map(static fn ($c) => $c->ref, $menu->categories),
            ], static fn ($v) => null !== $v)],
            'categories' => array_map(fn ($c) => array_filter([
                'id' => $c->ref,
                'title' => $this->text($c->name),
                'subtitle' => null !== $c->description ? $this->text($c->description) : null,
                'entities' => array_values(array_map(static fn (string $id) => ['id' => $id, 'type' => 'ITEM'], array_unique(array_map(static fn (Item $i) => $i->ref, $c->items)))),
            ], static fn ($v) => null !== $v), $menu->categories),
            'items' => array_values($items),
            'modifier_groups' => array_values($groups),
        ];
    }

    /**
     * Suspended until $until - Uber needs an end: a year from now when none
     * is given -, or available again (a suspension in the past).
     */
    public function setAvailability(string $itemRef, bool $available, ?\DateTimeImmutable $until = null): void
    {
        $this->api->request('POST', '/v2/eats/stores/'.rawurlencode($this->store()).'/menus/items/'.rawurlencode($itemRef), [], [
            'suspension_info' => ['suspension' => ['suspend_until' => $available ? 0 : ($until ?? new \DateTimeImmutable('+1 year'))->getTimestamp()]],
        ]);
    }

    public function status(): StoreStatus
    {
        $data = $this->api->request('GET', '/v1/delivery/store/'.rawurlencode($this->store()).'/status');

        return new StoreStatus(
            match ($data['status'] ?? null) {
                'ONLINE' => StoreState::OPEN,
                'OFFLINE' => StoreState::PAUSED,
                default => StoreState::UNKNOWN,
            },
            self::date($data['is_offline_until'] ?? null),
            isset($data['offline_reason']) ? (string) $data['offline_reason'] : null,
            $data,
        );
    }

    public function pause(?\DateTimeImmutable $until = null, ?string $reason = null): void
    {
        $this->api->request('POST', '/v1/delivery/store/'.rawurlencode($this->store()).'/update-store-status', [], array_filter([
            'status' => 'OFFLINE',
            'is_offline_until' => $until?->format(\DateTimeInterface::RFC3339),
            'reason' => $reason,
        ], static fn ($v) => null !== $v));
    }

    public function resume(): void
    {
        $this->api->request('POST', '/v1/delivery/store/'.rawurlencode($this->store()).'/update-store-status', [], ['status' => 'ONLINE']);
    }

    /**
     * The dates apart (Hours::$exceptions) as Uber's holiday hours, all of
     * them at once (each call replaces the previous ones); a closed date is
     * 00:00 - 00:00. The week is not set here: at Uber it is the menu's
     * service hours (Menu::$hours, pushMenu()).
     */
    public function setHours(Hours $hours): void
    {
        $holidays = [];
        foreach ($hours->exceptions as $date => $ranges) {
            $holidays[$date] = ['open_time_periods' => $ranges
                ? array_map(static fn (array $r) => ['start_time' => $r[0], 'end_time' => $r[1]], $ranges)
                : [['start_time' => '00:00', 'end_time' => '00:00']]];
        }
        $this->api->request('POST', '/v1/eats/stores/'.rawurlencode($this->store()).'/holiday-hours', [], ['holiday_hours' => $holidays ?: new \ArrayObject()]);
    }

    /**
     * A webhook from Uber, checked: X-Uber-Signature is the lowercase hex
     * HMAC-SHA256 of the raw body with the client secret. It carries the
     * event and the resource's id, not the order: order($notification->reference)
     * reads it. Notification::$id is Uber's event_id (events are retried,
     * and come out of order). The endpoint answers 200, empty.
     */
    public function notify(string $body, array $headers): Notification
    {
        $headers = array_change_key_case(array_map(static fn ($v) => \is_array($v) ? (string) reset($v) : (string) $v, $headers));
        $secret = $this->clientSecret ?: throw new InvalidConfigException('The "ubereats" platform needs: client_secret, to check its webhooks.');
        if (!hash_equals(hash_hmac('sha256', $body, $secret), strtolower($headers['x-uber-signature'] ?? ''))) {
            throw new InvalidSignatureException($this->getName(), 'The X-Uber-Signature signature does not match the body.');
        }
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new InvalidSignatureException($this->getName(), 'The body is not JSON.');
        }
        $event = (string) ($data['event_type'] ?? 'unknown');
        $meta = (array) ($data['meta'] ?? []);
        $time = $data['event_time'] ?? null;

        return new Notification(
            Channel::UBEREATS,
            $event,
            match (true) {
                str_starts_with($event, 'orders.') => NotificationSubject::ORDER,
                str_starts_with($event, 'delivery.') => NotificationSubject::COURIER,
                str_starts_with($event, 'store.menu') => NotificationSubject::MENU,
                str_starts_with($event, 'store.') => NotificationSubject::STORE,
                default => NotificationSubject::OTHER,
            },
            isset($meta['resource_id']) ? (string) $meta['resource_id'] : (isset($meta['order_id']) ? (string) $meta['order_id'] : null),
            isset($data['event_id']) ? (string) $data['event_id'] : null,
            status: match ($event) {
                'orders.notification', 'orders.scheduled.notification' => OrderStatus::NEW,
                'orders.cancel', 'orders.failure' => OrderStatus::CANCELLED,
                default => null,
            },
            // Seconds in one place of Uber's documentation, milliseconds in another.
            occurredAt: is_numeric($time) ? new \DateTimeImmutable('@'.(int) ($time > 1e11 ? intdiv((int) $time, 1000) : $time)) : null,
            store: isset($meta['user_id']) ? (string) $meta['user_id'] : (isset($meta['store_id']) ? (string) $meta['store_id'] : null),
            raw: $data,
        );
    }

    public function token(): ?Token
    {
        return $this->api->heldToken();
    }

    /** A new client-credentials token (30 days), for the site to keep and give back as access_token / token_expires_at. */
    public function refresh(): Token
    {
        return $this->api->refresh();
    }

    /**
     * Where to send the merchant to link their store to the application
     * (scope eats.pos_provisioning); the token exchange() gives is for the
     * provisioning calls only, not for the orders.
     */
    public function authorizationUrl(string $redirectUri, string $state, array $scopes = []): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->api->clientId() ?? throw new InvalidConfigException('The "ubereats" platform needs: client_id.'),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes ?: self::PROVISIONING_SCOPES),
            'state' => $state,
        ]);
    }

    public function exchange(string $code, string $redirectUri): Token
    {
        return $this->api->grant(['grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri, 'code' => $code]);
    }

    /**
     * An order as the Order Fulfillment API gives it, as an Order: the
     * prices are in payment.payment_detail (tax included), by cart item.
     *
     * @param array<string, mixed> $o
     */
    public function toOrder(array $o): Order
    {
        $detail = (array) ($o['payment']['payment_detail'] ?? []);
        $prices = [];
        foreach ((array) ($detail['item_charges']['price_breakdown'] ?? []) as $price) {
            if (isset($price['cart_item_id'])) {
                $prices[(string) $price['cart_item_id']] = $price;
            }
        }
        $lines = [];
        $notes = [];
        foreach ((array) ($o['carts'] ?? []) as $cart) {
            foreach ((array) ($cart['items'] ?? []) as $item) {
                $lines[] = $this->line($item, $prices);
            }
            if ('' !== ($cart['special_instructions'] ?? '')) {
                $notes[] = (string) $cart['special_instructions'];
            }
        }
        $customers = (array) ($o['customers'] ?? []);
        $customer = current(array_filter($customers, static fn ($c) => $c['is_primary_customer'] ?? false)) ?: ($customers[0] ?? null);
        $delivery = (array) (($o['deliveries'] ?? [])[0] ?? []);
        $partner = (array) ($delivery['delivery_partner'] ?? []);
        $state = (string) ($o['state'] ?? '');

        return new Order(
            Channel::UBEREATS,
            (string) $o['id'],
            isset($o['display_id']) ? (string) $o['display_id'] : null,
            match ($o['fulfillment_type'] ?? null) {
                'PICKUP' => OrderType::PICKUP,
                'DINE_IN' => OrderType::DINE_IN,
                default => OrderType::DELIVERY,
            },
            match ($state) {
                'CREATED', 'OFFERED' => OrderStatus::NEW,
                'ACCEPTED' => 'READY_FOR_HANDOFF' === ($o['preparation_status'] ?? null) ? OrderStatus::READY : OrderStatus::ACCEPTED,
                'HANDED_OFF' => OrderStatus::PICKED_UP,
                'SUCCEEDED' => OrderStatus::DELIVERED,
                'FAILED' => 'POS_DENIED' === ($o['failure_info']['reason'] ?? null) ? OrderStatus::DENIED : OrderStatus::CANCELLED,
                default => OrderStatus::UNKNOWN,
            },
            $lines,
            \is_array($customer) ? new Customer(
                ($customer['name']['display_name'] ?? trim(($customer['name']['first_name'] ?? '').' '.($customer['name']['last_name'] ?? ''))) ?: null,
                ($customer['contact']['phone']['number'] ?? '') ?: null,
                ($customer['contact']['phone']['pin_code'] ?? '') ?: null,
            ) : null,
            self::money($detail['order_total'] ?? null),
            self::money($detail['item_charges']['total'] ?? null),
            self::money($detail['promotions']['total'] ?? null, true),
            self::money($detail['fees']['total'] ?? null),
            self::money($detail['order_total'] ?? null, false, 'tax'),
            self::date($o['created_time'] ?? null),
            self::date($o['preparation_time']['ready_for_pickup_time'] ?? null),
            self::date($delivery['estimated_dropoff_time'] ?? null),
            $partner ? new Courier(
                ($partner['name']['display_name'] ?? '') ?: null,
                ($partner['contact']['phone']['number'] ?? '') ?: null,
                isset($delivery['status']) ? (string) $delivery['status'] : null,
                self::date($delivery['estimated_pick_up_time'] ?? null),
                isset($partner['vehicle']['type']) ? (string) $partner['vehicle']['type'] : null,
            ) : null,
            $notes ? implode("\n", $notes) : null,
            isset($o['store']['id']) ? (string) $o['store']['id'] : null,
            null,
            $o,
        );
    }

    /**
     * @param array<string, mixed>                $i
     * @param array<string, array<string, mixed>> $prices
     */
    private function line(array $i, array $prices): Line
    {
        $price = $prices[(string) ($i['cart_item_id'] ?? '')] ?? [];
        $request = (array) ($i['customer_request'] ?? []);
        $note = array_filter([$request['special_instructions'] ?? null, \is_array($request['allergy'] ?? null) ? ($request['allergy']['instructions'] ?? null) : null], static fn ($v) => null !== $v && '' !== $v);

        return new Line(
            (string) ($i['title'] ?? ''),
            (int) ($i['quantity']['amount'] ?? 1),
            self::money($price['unit'] ?? null),
            self::money($price['total'] ?? null),
            isset($i['id']) ? (string) $i['id'] : null,
            isset($i['cart_item_id']) ? (string) $i['cart_item_id'] : null,
            $this->modifiers($i, $prices),
            $note ? implode("\n", $note) : null,
        );
    }

    /**
     * @param array<string, mixed>                $i
     * @param array<string, array<string, mixed>> $prices
     *
     * @return list<Modifier>
     */
    private function modifiers(array $i, array $prices): array
    {
        $modifiers = [];
        foreach ((array) ($i['selected_modifier_groups'] ?? []) as $group) {
            foreach ((array) ($group['selected_items'] ?? []) as $selected) {
                $price = $prices[(string) ($selected['cart_item_id'] ?? '')] ?? [];
                $modifiers[] = new Modifier(
                    (string) ($selected['title'] ?? ''),
                    (int) ($selected['quantity']['amount'] ?? 1),
                    self::money($price['unit'] ?? null),
                    isset($selected['id']) ? (string) $selected['id'] : null,
                    isset($group['title']) ? (string) $group['title'] : null,
                    $this->modifiers($selected, $prices),
                );
            }
        }

        return $modifiers;
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $groups
     */
    private function collect(Item|MenuModifier $item, ?float $vat, array &$items, array &$groups): void
    {
        $vat = $item->vatRate ?? $vat;
        $labels = $item instanceof Item ? array_map('strtolower', $item->labels) : [];
        $diets = array_values(array_intersect_key(self::DIETS, array_flip($labels)));
        $items[$item->ref] ??= array_filter([
            'id' => $item->ref,
            'external_data' => $item->ref,
            'title' => $this->text($item->name),
            'description' => $item instanceof Item && null !== $item->description ? $this->text($item->description) : null,
            'image_url' => $item instanceof Item ? $item->photo : null,
            'price_info' => ['price' => $item->price?->amount ?? 0],
            'tax_info' => null === $vat ? null : [$this->taxInclusive ? 'vat_rate_percentage' : 'tax_rate' => $vat],
            'nutritional_info' => $item->allergens ? ['allergens' => array_map(static fn ($a) => $a->value, $item->allergens)] : null,
            'dish_info' => $diets ? ['classifications' => ['dietary_label_info' => ['labels' => $diets]]] : null,
            'modifier_group_ids' => $item->modifierGroups ? ['ids' => array_map(static fn (ModifierGroup $g) => $g->ref, $item->modifierGroups)] : null,
        ], static fn ($v) => null !== $v);
        foreach ($item->modifierGroups as $group) {
            $groups[$group->ref] ??= [
                'id' => $group->ref,
                'external_data' => $group->ref,
                'title' => $this->text($group->name),
                'quantity_info' => ['quantity' => array_filter(['min_permitted' => $group->min, 'max_permitted' => $group->max], static fn ($v) => null !== $v)],
                'modifier_options' => array_map(static fn (MenuModifier $m) => ['id' => $m->ref, 'type' => 'ITEM'], $group->modifiers),
            ];
            foreach ($group->modifiers as $option) {
                $this->collect($option, $vat, $items, $groups);
            }
        }
    }

    /** @return array{translations: array<string, string>} */
    private function text(string $text): array
    {
        return ['translations' => [$this->language => $text]];
    }

    /** @return array{type: string, info?: string} */
    private static function reason(DenyReason $reason, ?string $note): array
    {
        return array_filter([
            'type' => match ($reason) {
                DenyReason::ITEM_UNAVAILABLE => 'ITEM_ISSUE',
                DenyReason::CLOSED => 'STORE_CLOSED',
                DenyReason::TOO_BUSY => 'RESTAURANT_TOO_BUSY',
                DenyReason::CUSTOMER_REQUEST => 'CUSTOMER_CALLED_TO_CANCEL',
                DenyReason::ADDRESS => 'ADDRESS',
                DenyReason::PRICING => 'PRICING',
                DenyReason::SPECIAL_INSTRUCTIONS => 'SPECIAL_INSTRUCTIONS',
                DenyReason::TECHNICAL => 'TECHNICAL_FAILURE',
                DenyReason::OTHER => 'OTHER',
            },
            'info' => $note,
        ], static fn ($v) => null !== $v);
    }

    private function store(): string
    {
        return $this->storeId ?: throw new InvalidConfigException('The "ubereats" platform needs: store_id.');
    }

    /**
     * Uber's money ({gross, net, tax: {amount_e5, currency_code}}) in minor
     * units: the gross amount (tax included), or $part.
     */
    private static function money(mixed $money, bool $absolute = false, string $part = 'gross'): ?Money
    {
        $amount = \is_array($money) ? ($money[$part] ?? ('gross' === $part ? ($money['net'] ?? null) : null)) : null;
        if (!\is_array($amount) || !isset($amount['amount_e5'], $amount['currency_code'])) {
            return null;
        }
        $value = Money::fromDecimal(((float) $amount['amount_e5']) / 100000, (string) $amount['currency_code']);

        return $absolute ? Money::of(abs($value->amount), $value->currency) : $value;
    }

    private static function date(mixed $date): ?\DateTimeImmutable
    {
        try {
            return \is_string($date) && '' !== $date ? new \DateTimeImmutable($date) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
