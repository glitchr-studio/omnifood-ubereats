<?php

namespace Omnifood\UberEats\Tests;

use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidMenuException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Omnifood\Model\Allergen;
use Omnifood\Model\DenyReason;
use Omnifood\Model\Hours;
use Omnifood\Model\Menu\Category;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Menu\Modifier;
use Omnifood\Model\Menu\ModifierGroup;
use Omnifood\Model\Money;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use Omnifood\Model\StoreState;
use Omnifood\UberEats\UberEatsPlatform;
use Omnifood\UberEats\UberEatsPlatformFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UberEatsPlatformTest extends TestCase
{
    /** @var list<array{string, string, array<string, string>, array<string, string>, mixed}> method, host+path, query, headers, body */
    private array $calls = [];

    private int $tokens = 0;

    private function platform(array $options = [], ?\Closure $answer = null): UberEatsPlatform
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $path = parse_url($url, \PHP_URL_HOST).rawurldecode((string) parse_url($url, \PHP_URL_PATH));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $headers = [];
            foreach ($options['headers'] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            if (str_ends_with($path, '/oauth/v2/token')) {
                parse_str($body, $form);
                $this->calls[] = [$method, $path, [], $headers, $form];

                return self::json(['access_token' => 'KA.'.++$this->tokens, 'expires_in' => 2592000, 'token_type' => 'Bearer', 'scope' => $form['scope'] ?? 'eats.pos_provisioning', 'refresh_token' => 'authorization_code' === ($form['grant_type'] ?? null) ? 'MA.refresh' : '']);
            }
            $this->calls[] = [$method, $path, $query, $headers, '' !== $body ? json_decode($body, true) : null];
            if ($answer && null !== ($response = $answer($method, $path, $query))) {
                return $response;
            }

            return match (true) {
                'GET' === $method && str_ends_with($path, '/v1/delivery/order/o-1') => self::json(['order' => self::order()]),
                'GET' === $method && str_ends_with($path, '/v1/delivery/store/store-1/orders') => self::json(null === ($query['next_page_token'] ?? null)
                    ? ['data' => [['order' => self::order('o-1', '2026-10-04T11:00:00Z')]], 'pagination_data' => ['next_page_token' => 'p2', 'page_size' => 50]]
                    : ['data' => [['order' => self::order('o-2', '2026-10-04T12:00:00Z')]], 'pagination_data' => ['page_size' => 50]]),
                'GET' === $method && str_ends_with($path, '/status') => self::json(['status' => 'OFFLINE', 'is_offline_until' => '2026-10-04T13:00:00+00:00', 'offline_reason' => 'PAUSED_BY_RESTAURANT']),
                'POST' === $method && str_ends_with($path, '/cancel') => new MockResponse('', ['http_code' => 204]),
                default => self::json([]),
            };
        });

        return (new UberEatsPlatformFactory($http))->create($options + ['client_id' => 'cid', 'client_secret' => 'csecret', 'store_id' => 'store-1']);
    }

    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status]);
    }

    /** Euros as Uber writes them: gross amounts times 10^5. */
    private static function money(float $euros, float $tax = 0.0): array
    {
        return ['gross' => ['amount_e5' => (int) round($euros * 100000), 'currency_code' => 'EUR', 'formatted' => '€'], 'tax' => ['amount_e5' => (int) round($tax * 100000), 'currency_code' => 'EUR'], 'is_tax_inclusive' => true];
    }

    private static function order(string $id = 'o-1', string $created = '2026-10-04T12:00:00Z', string $state = 'OFFERED'): array
    {
        return [
            'id' => $id, 'display_id' => 'A1B2C', 'state' => $state, 'status' => 'ACTIVE', 'fulfillment_type' => 'DELIVERY_BY_UBER',
            'store' => ['id' => 'store-1', 'name' => 'Nakaya'], 'created_time' => $created,
            'preparation_time' => ['ready_for_pickup_time_secs' => 900, 'source' => 'PREDICTED_BY_UBER', 'ready_for_pickup_time' => '2026-10-04T12:15:00Z'],
            'customers' => [['id' => 'c', 'name' => ['display_name' => 'Aiko T.'], 'contact' => ['phone' => ['number' => '+33100000000', 'pin_code' => '123 45 678']], 'is_primary_customer' => true]],
            'carts' => [['id' => 'cart', 'special_instructions' => 'Ring twice', 'items' => [
                ['id' => 'ramen', 'cart_item_id' => 'ci-1', 'title' => 'Shoyu ramen', 'quantity' => ['amount' => 2], 'customer_request' => ['special_instructions' => 'Extra hot', 'allergy' => ['instructions' => 'No sesame']],
                    'selected_modifier_groups' => [['id' => 'extras', 'title' => 'Extras', 'selected_items' => [['id' => 'egg', 'cart_item_id' => 'ci-2', 'title' => 'Egg', 'quantity' => ['amount' => 1]]]]]],
            ]]],
            'payment' => ['payment_detail' => [
                'order_total' => self::money(31.90, 2.90), 'item_charges' => ['total' => self::money(29.50), 'price_breakdown' => [
                    ['cart_item_id' => 'ci-1', 'quantity' => ['amount' => 2], 'unit' => self::money(14.00), 'total' => self::money(29.50)],
                    ['cart_item_id' => 'ci-2', 'quantity' => ['amount' => 1], 'unit' => self::money(1.50), 'total' => self::money(1.50)],
                ]],
                'fees' => ['total' => self::money(3.40)], 'promotions' => ['total' => self::money(-1.00)],
            ]],
            'deliveries' => [['id' => 'd', 'status' => 'EN_ROUTE_TO_PICKUP', 'estimated_pick_up_time' => '2026-10-04T12:16:00Z', 'estimated_dropoff_time' => '2026-10-04T12:35:00Z',
                'delivery_partner' => ['name' => ['display_name' => 'Jason'], 'vehicle' => ['type' => 'BICYCLE'], 'contact' => ['phone' => ['number' => '+13127666835', 'pin_code' => '999']]]]],
        ];
    }

    public function testAnOrderReadWhole(): void
    {
        $order = $this->platform()->order('o-1');

        self::assertSame(['o-1', 'A1B2C', OrderType::DELIVERY, OrderStatus::NEW, 'store-1'], [$order->reference, $order->displayId, $order->type, $order->status, $order->store]);
        [$line] = $order->lines;
        self::assertSame(['Shoyu ramen', 2, 'ramen', 'ci-1', "Extra hot\nNo sesame"], [$line->name, $line->quantity, $line->posRef, $line->platformRef, $line->note]);
        self::assertTrue(Money::of(1400, 'EUR')->equals($line->unitPrice));
        self::assertSame(2950, $line->total->amount);
        self::assertSame(['Egg', 'egg', 'Extras', 150], [$line->modifiers[0]->name, $line->modifiers[0]->posRef, $line->modifiers[0]->group, $line->modifiers[0]->price->amount]);
        self::assertSame([3190, 2950, 100, 340, 290], [$order->total->amount, $order->subtotal->amount, $order->discount->amount, $order->fees->amount, $order->tax->amount]);
        self::assertSame(['Aiko T.', '+33100000000', '123 45 678'], [$order->customer->name, $order->customer->phone, $order->customer->phoneCode]);
        self::assertSame(['Jason', 'EN_ROUTE_TO_PICKUP', 'BICYCLE'], [$order->courier->name, $order->courier->status, $order->courier->vehicle]);
        self::assertEquals(new \DateTimeImmutable('2026-10-04T12:15:00Z'), $order->pickupAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-04T12:35:00Z'), $order->deliverAt);
        self::assertSame('Ring twice', $order->note);

        [$token, $get] = $this->calls;
        self::assertSame(['client_id' => 'cid', 'client_secret' => 'csecret', 'grant_type' => 'client_credentials', 'scope' => 'eats.store eats.order eats.store.orders.read eats.store.status.write'], $token[4]);
        self::assertSame(['GET', 'api.uber.com/v1/delivery/order/o-1', ['expand' => 'carts,deliveries,payment'], 'Bearer KA.1'], [$get[0], $get[1], $get[2], $get[3]['authorization']]);
    }

    public function testTheStatesAsOmnifoodsStatuses(): void
    {
        $platform = $this->platform();
        $ready = self::order(state: 'ACCEPTED') + ['preparation_status' => 'READY_FOR_HANDOFF'];
        self::assertSame(OrderStatus::READY, $platform->toOrder($ready)->status);
        self::assertSame(OrderStatus::ACCEPTED, $platform->toOrder(self::order(state: 'ACCEPTED'))->status);
        self::assertSame(OrderStatus::PICKED_UP, $platform->toOrder(self::order(state: 'HANDED_OFF'))->status);
        self::assertSame(OrderStatus::DELIVERED, $platform->toOrder(self::order(state: 'SUCCEEDED'))->status);
        self::assertSame(OrderStatus::DENIED, $platform->toOrder(self::order(state: 'FAILED') + ['failure_info' => ['reason' => 'POS_DENIED']])->status);
        self::assertSame(OrderStatus::CANCELLED, $platform->toOrder(self::order(state: 'FAILED') + ['failure_info' => ['reason' => 'ACCEPT_TIMED_OUT']])->status);
        self::assertSame(OrderType::PICKUP, $platform->toOrder(['fulfillment_type' => 'PICKUP'] + self::order())->type);
    }

    public function testTheOrdersPageByPageNewestFirst(): void
    {
        $orders = $this->platform()->orders(new \DateTimeImmutable('2026-10-04T10:00:00+02:00'));

        self::assertSame(['o-2', 'o-1'], array_map(static fn ($o) => $o->reference, $orders));
        self::assertSame(['expand' => 'carts,deliveries,payment', 'start_time' => '2026-10-04T10:00:00+02:00', 'page_size' => '50'], $this->calls[1][2]);
        self::assertSame('p2', $this->calls[2][2]['next_page_token']);
    }

    public function testAcceptDenyReadyCancel(): void
    {
        $platform = $this->platform();
        $platform->accept('o-1', new \DateTimeImmutable('2026-10-04T12:20:00+00:00'));
        $platform->accept('o-2');
        $platform->deny('o-3', DenyReason::ITEM_UNAVAILABLE, 'No more gyoza');
        $platform->ready('o-1');
        $platform->cancel('o-1', DenyReason::TOO_BUSY);

        self::assertSame(['api.uber.com/v1/delivery/order/o-1/accept', ['ready_for_pickup_time' => '2026-10-04T12:20:00+00:00']], [$this->calls[1][1], $this->calls[1][4]]);
        self::assertSame([], $this->calls[2][4], 'an empty object');
        self::assertSame(['deny_reason' => ['type' => 'ITEM_ISSUE', 'info' => 'No more gyoza']], $this->calls[3][4]);
        self::assertSame('api.uber.com/v1/delivery/order/o-1/ready', $this->calls[4][1]);
        self::assertSame(['cancellation_reason' => ['type' => 'RESTAURANT_TOO_BUSY']], $this->calls[5][4]);
        self::assertSame(1, $this->tokens, 'the 30-day token kept');
    }

    public function testTheMenuInUbersShape(): void
    {
        $platform = $this->platform(['language' => 'fr_fr']);
        $sauce = new ModifierGroup('sauce', 'Sauce', [new Modifier('soy', 'Soy'), new Modifier('ponzu', 'Ponzu', Money::of(50, 'EUR'))], 1, 1);
        $menu = new Menu('Dîner', [new Category('mains', 'Plats', [
            new Item('ramen', 'Shoyu ramen', Money::of(1400, 'EUR'), 'Bouillon', 10.0, [Allergen::GLUTEN, Allergen::EGGS], 'https://site.example/ramen.jpg', [$sauce], labels: ['vegetarian']),
        ])], 'EUR', new Hours([1 => [['12:00', '14:30']]]), 'dinner');

        $platform->pushMenu($menu);

        [$method, $path, , , $body] = $this->calls[1];
        self::assertSame(['PUT', 'api.uber.com/v2/eats/stores/store-1/menus'], [$method, $path]);
        self::assertSame(['id' => 'dinner', 'title' => ['translations' => ['fr_fr' => 'Dîner']], 'service_availability' => [['day_of_week' => 'monday', 'time_periods' => [['start_time' => '12:00', 'end_time' => '14:30']]]], 'category_ids' => ['mains']], $body['menus'][0]);
        self::assertSame([['id' => 'ramen', 'type' => 'ITEM']], $body['categories'][0]['entities']);
        $items = array_column($body['items'], null, 'id');
        self::assertSame(['ramen', 'soy', 'ponzu'], array_keys($items));
        self::assertSame(['price' => 1400], $items['ramen']['price_info']);
        self::assertSame(['vat_rate_percentage' => 10.0], $items['ramen']['tax_info']);
        self::assertSame(['allergens' => ['gluten', 'eggs']], $items['ramen']['nutritional_info']);
        self::assertSame(['VEGETARIAN'], $items['ramen']['dish_info']['classifications']['dietary_label_info']['labels']);
        self::assertSame(['ids' => ['sauce']], $items['ramen']['modifier_group_ids']);
        self::assertSame('https://site.example/ramen.jpg', $items['ramen']['image_url']);
        self::assertSame(['price' => 50], $items['ponzu']['price_info']);
        self::assertSame([['id' => 'sauce', 'external_data' => 'sauce', 'title' => ['translations' => ['fr_fr' => 'Sauce']], 'quantity_info' => ['quantity' => ['min_permitted' => 1, 'max_permitted' => 1]], 'modifier_options' => [['id' => 'soy', 'type' => 'ITEM'], ['id' => 'ponzu', 'type' => 'ITEM']]]], $body['modifier_groups']);
    }

    public function testAMenuTheValidatorRefusesSendsNothing(): void
    {
        $this->expectException(InvalidMenuException::class);
        try {
            $this->platform()->pushMenu(new Menu('Dîner', [new Category('mains', 'Plats', [new Item('ramen', 'Ramen', Money::of(-1, 'EUR'))])]));
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testAnItemSuspendedThenBack(): void
    {
        $platform = $this->platform();
        $platform->setAvailability('ramen', false, new \DateTimeImmutable('@1790000000'));
        $platform->setAvailability('ramen', true);
        $platform->setAvailability('gyoza', false);

        self::assertSame(['api.uber.com/v2/eats/stores/store-1/menus/items/ramen', ['suspension_info' => ['suspension' => ['suspend_until' => 1790000000]]]], [$this->calls[1][1], $this->calls[1][4]]);
        self::assertSame(0, $this->calls[2][4]['suspension_info']['suspension']['suspend_until'], 'in the past: available');
        self::assertGreaterThan(time() + 300 * 86400, $this->calls[3][4]['suspension_info']['suspension']['suspend_until'], 'no end given: a year');
    }

    public function testTheStorePausedResumedAndItsHolidays(): void
    {
        $platform = $this->platform();
        $status = $platform->status();
        self::assertSame([StoreState::PAUSED, 'PAUSED_BY_RESTAURANT'], [$status->state, $status->reason]);
        self::assertEquals(new \DateTimeImmutable('2026-10-04T13:00:00+00:00'), $status->until);

        $platform->pause(new \DateTimeImmutable('2026-10-04T13:30:00+00:00'), 'Rush');
        $platform->resume();
        $platform->setHours(new Hours([1 => [['12:00', '14:00']]], ['2026-12-25' => [], '2026-12-31' => [['18:00', '23:30']]]));

        self::assertSame(['api.uber.com/v1/delivery/store/store-1/update-store-status', ['status' => 'OFFLINE', 'is_offline_until' => '2026-10-04T13:30:00+00:00', 'reason' => 'Rush']], [$this->calls[2][1], $this->calls[2][4]]);
        self::assertSame(['status' => 'ONLINE'], $this->calls[3][4]);
        self::assertSame(['api.uber.com/v1/eats/stores/store-1/holiday-hours', ['holiday_hours' => [
            '2026-12-25' => ['open_time_periods' => [['start_time' => '00:00', 'end_time' => '00:00']]],
            '2026-12-31' => ['open_time_periods' => [['start_time' => '18:00', 'end_time' => '23:30']]],
        ]]], [$this->calls[4][1], $this->calls[4][4]]);
    }

    public function testAWebhookCheckedAndRead(): void
    {
        $platform = $this->platform();
        $body = '{"event_id":"c4d2261e-2779-4eb6-beb0-cb41235c751e","event_time":1427343990,"event_type":"orders.notification","meta":{"resource_id":"153dd7f1-339d-4619-940c-418943c14636","status":"pos","user_id":"89dd9741-66b5-4bb4-b216-a813f3b21b4f"},"resource_href":"https://api.uber.com/v1/delivery/order/153dd7f1-339d-4619-940c-418943c14636"}';

        $notification = $platform->notify($body, ['X-Uber-Signature' => hash_hmac('sha256', $body, 'csecret'), 'X-Environment' => 'production']);

        self::assertSame(['orders.notification', NotificationSubject::ORDER, '153dd7f1-339d-4619-940c-418943c14636', 'c4d2261e-2779-4eb6-beb0-cb41235c751e', OrderStatus::NEW, '89dd9741-66b5-4bb4-b216-a813f3b21b4f'],
            [$notification->event, $notification->subject, $notification->reference, $notification->id, $notification->status, $notification->store]);
        self::assertEquals(new \DateTimeImmutable('@1427343990'), $notification->occurredAt);

        $ms = '{"event_id":"e","event_time":1729651095000,"event_type":"orders.failure","meta":{"resource_id":"o"}}';
        $failure = $platform->notify($ms, ['x-uber-signature' => strtoupper(hash_hmac('sha256', $ms, 'csecret'))]);
        self::assertSame(OrderStatus::CANCELLED, $failure->status);
        self::assertEquals(new \DateTimeImmutable('@1729651095'), $failure->occurredAt, 'milliseconds too');

        $store = '{"event_id":"s","event_type":"store.status.changed","meta":{"user_id":"store-1"}}';
        self::assertSame(NotificationSubject::STORE, $platform->notify($store, ['x-uber-signature' => hash_hmac('sha256', $store, 'csecret')])->subject);

        $this->expectException(InvalidSignatureException::class);
        $platform->notify($body, ['X-Uber-Signature' => hash_hmac('sha256', $body, 'another secret')]);
    }

    public function testTheMerchantLinksTheStoreThroughOAuth(): void
    {
        $platform = $this->platform();
        $url = $platform->authorizationUrl('https://site.example/admin/ubereats', 'xyz');
        self::assertStringStartsWith('https://auth.uber.com/oauth/v2/authorize?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(['client_id' => 'cid', 'response_type' => 'code', 'redirect_uri' => 'https://site.example/admin/ubereats', 'scope' => 'eats.pos_provisioning', 'state' => 'xyz'], $query);

        $token = $platform->exchange('code-1', 'https://site.example/admin/ubereats');
        self::assertSame(['KA.1', 'MA.refresh'], [$token->accessToken, $token->refreshToken]);
        self::assertSame(['client_id' => 'cid', 'client_secret' => 'csecret', 'grant_type' => 'authorization_code', 'redirect_uri' => 'https://site.example/admin/ubereats', 'code' => 'code-1'], $this->calls[0][4]);
        self::assertNull($platform->token(), 'not the token of the orders');
    }

    public function testATokenKeptAndRefreshed(): void
    {
        $platform = $this->platform(['access_token' => 'kept', 'token_expires_at' => (new \DateTimeImmutable('+10 days'))->format(\DATE_ATOM), 'scopes' => 'eats.order eats.store']);
        $platform->ready('o-1');
        self::assertSame([0, 'Bearer kept'], [$this->tokens, $this->calls[0][3]['authorization']]);

        $fresh = $platform->refresh();
        self::assertSame(['KA.1', ['eats.order', 'eats.store']], [$fresh->accessToken, $fresh->scopes]);
        self::assertTrue($fresh->isExpiring(31));
        self::assertFalse($fresh->isExpiring(29));
    }

    public function testTheSandbox(): void
    {
        $this->platform(['sandbox' => true])->ready('o-1');

        self::assertSame('sandbox-login.uber.com/oauth/v2/token', $this->calls[0][1]);
        self::assertSame('test-api.uber.com/v1/delivery/order/o-1/ready', $this->calls[1][1]);
    }

    public function testErrorsAreMapped(): void
    {
        try {
            $this->platform([], static fn () => self::json(['code' => 'resource_status_conflict', 'message' => 'Order already accepted'], 409))->accept('o-1');
            self::fail('Accepted twice.');
        } catch (ProviderException $e) {
            self::assertSame(['resource_status_conflict', 409], [$e->providerCode, $e->status]);
        }
        try {
            $this->platform([], static fn () => self::json(['code' => 'Unauthorized', 'message' => 'Invalid OAuth 2.0 credentials provided.'], 401))->ready('o-1');
            self::fail('Refused.');
        } catch (UnauthorizedException $e) {
            self::assertSame('[ubereats] Invalid OAuth 2.0 credentials provided.', $e->getMessage());
        }
    }

    public function testWithoutKeysOnlyWhatNeedsNoneWorks(): void
    {
        $platform = (new UberEatsPlatformFactory(new MockHttpClient()))->create();

        self::assertSame(690, $platform->capabilities()->acceptanceDelay, 'eleven minutes and a half');
        try {
            $platform->notify('{}', []);
            self::fail('No secret to check against.');
        } catch (InvalidConfigException) {
        }
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "ubereats" platform needs: client_id, client_secret.');
        $platform->order('o-1');
    }
}
