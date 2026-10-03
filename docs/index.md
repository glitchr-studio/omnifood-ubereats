# Uber Eats with Omnifood

## Installation

```sh
composer require glitchr/omnifood omnifood/ubereats
```

## Configuration

```yaml
# config/packages/omnifood.yaml
omnifood:
    platforms:
        ubereats:
            factory: ubereats
            options:
                client_id: '%env(default::UBEREATS_CLIENT_ID)%'
                client_secret: '%env(default::UBEREATS_CLIENT_SECRET)%'
                store_id: '%env(default::UBEREATS_STORE_ID)%'
```

The store must use webhooks version 1.0.0 (`PATCH /v1/eats/stores/{store_id}/pos_data` with
`"webhooks_version": "1.0.0"`), so that its orders are those of the Order Fulfillment API.

## Receiving an order

```php
#[Route('/webhooks/ubereats', methods: ['POST'])]
public function webhook(Request $request, NotifiableInterface $ubereats, MessageBusInterface $bus): Response
{
    try {
        $notification = $ubereats->notify($request->getContent(), $request->headers->all());
    } catch (InvalidSignatureException) {
        return new Response('', 401);
    }
    if ('orders.notification' === $notification->event) {
        $bus->dispatch(new NewOrder('ubereats', $notification->reference, $notification->id));
    }

    return new Response('', 200);
}
```

Then, in the handler (11.5 minutes at most):

```php
$order = $ubereats->order($reference);
$ubereats->accept($order->reference, new \DateTimeImmutable('+15 minutes'));
// ... later, when it is ready:
$ubereats->ready($order->reference);
```

## The menu

```php
$ubereats->pushMenu($menu);                          // the whole menu, Menu::$hours its service hours
$ubereats->setAvailability('gyoza', false, new \DateTimeImmutable('tomorrow 11:00'));
```

## The store

```php
$ubereats->pause(new \DateTimeImmutable('+30 minutes'), 'Rush');
$ubereats->resume();
$ubereats->setHours(new Hours([], ['2026-12-25' => []]));    // Christmas closed (holiday hours)
```

## Linking a merchant's store

```php
$url = $ubereats->authorizationUrl('https://site.example/admin/ubereats/callback', $state);
// back on the callback:
$token = $ubereats->exchange($request->query->get('code'), 'https://site.example/admin/ubereats/callback');
// $token is for the provisioning calls (GET /v1/eats/stores, POST .../pos_data), scope eats.pos_provisioning
```

Not verified against the live API: start in Uber's sandbox (`sandbox: true`).
