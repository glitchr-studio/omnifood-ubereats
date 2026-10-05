# omnifood/ubereats

Uber Eats for [glitchr/omnifood](https://github.com/glitchr-studio/omnifood): the store's orders
read, accepted, denied, marked ready and cancelled, its menu uploaded and its items suspended, the
store paused and its holiday hours, the webhooks checked, and the merchant's consent to link the
store - through the Uber Eats Marketplace APIs (Order Fulfillment API Suite, Store API Suite, Menu
API).

> **Not verified against the live API (non vérifié en réel).** Uber gives API access only to
> approved partners: this package is written from Uber's public documentation
> (https://developer.uber.com/docs/eats/introduction, read on 2026-10-04) and tested against
> recorded answers. Check it in Uber's sandbox before a real store.

```php
use Omnifood\UberEats\UberEatsPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$ubereats = (new UberEatsPlatformFactory(HttpClient::create()))->create([
    'client_id' => getenv('UBEREATS_CLIENT_ID') ?: null,
    'client_secret' => getenv('UBEREATS_CLIENT_SECRET') ?: null,   // also signs the webhooks
    'store_id' => getenv('UBEREATS_STORE_ID') ?: null,
]);
```

Plain PHP, no framework needed: the factory takes any `HttpClientInterface` - the application's, a
`MockHttpClient` in a test - and makes its own when given none. In a Symfony application, the same
options under `omnifood.platforms` ([the bundle](https://github.com/glitchr-studio/omnifood/blob/1.x/docs/symfony.md)):

```yaml
omnifood:
    platforms:
        ubereats:
            factory: ubereats
            options:
                client_id: '%env(default::UBEREATS_CLIENT_ID)%'
                client_secret: '%env(default::UBEREATS_CLIENT_SECRET)%'   # also signs the webhooks
                store_id: '%env(default::UBEREATS_STORE_ID)%'
                scopes: [eats.store, eats.order, eats.store.orders.read, eats.store.status.write]
                language: en_us              # the menu's translation key
                tax_inclusive: true          # vat_rate_percentage (prices include VAT); false: tax_rate
                sandbox: false               # test-api.uber.com, sandbox-login.uber.com
                # access_token / token_expires_at: a token the site keeps (30 days; 100 requests an hour at most)
```

## What it does

| Omnifood | Uber Eats |
|---|---|
| `order($id)` | `GET /v1/delivery/order/{id}?expand=carts,deliveries,payment` |
| `orders($since)` | `GET /v1/delivery/store/{store_id}/orders` (pages of 50, 60 days) |
| `accept($id, $readyAt)` | `POST /v1/delivery/order/{id}/accept` (`ready_for_pickup_time`) |
| `deny($id, $reason, $note)` | `POST /v1/delivery/order/{id}/deny` (`deny_reason.type`, `.info`) |
| `ready($id)` | `POST /v1/delivery/order/{id}/ready` |
| `cancel($id, $reason, $note)` | `POST /v1/delivery/order/{id}/cancel` |
| `updateReadyTime($id, $at)` | `POST /v1/delivery/order/{id}/update-ready-time` |
| `pushMenu($menu)` | `PUT /v2/eats/stores/{store_id}/menus` (menus, categories, items, modifier groups) |
| `setAvailability($ref, ...)` | `POST /v2/eats/stores/{store_id}/menus/items/{ref}` (`suspension_info`) |
| `status()`, `pause()`, `resume()` | `GET /v1/delivery/store/{store_id}/status`, `POST .../update-store-status` |
| `setHours($hours)` | `POST /v1/eats/stores/{store_id}/holiday-hours` (the dates apart) |
| `notify($body, $headers)` | `X-Uber-Signature`: hex HMAC-SHA256 of the body with the client secret |
| `token()`, `refresh()` | `POST https://auth.uber.com/oauth/v2/token`, client credentials |
| `authorizationUrl()`, `exchange()` | merchant OAuth, scope `eats.pos_provisioning` (store linking) |

- A new order must be accepted or denied within **11.5 minutes** (`capabilities()->acceptanceDelay`
  = 690), or Uber cancels it. Only the application that is the store's order manager may accept,
  deny or cancel.
- The orders are those of the Order Fulfillment API: the store must be on **webhooks version
  1.0.0** (`PATCH /v1/eats/stores/{store_id}/pos_data`, `webhooks_version`), so that the webhooks
  point at `/v1/delivery/order/{id}`.
- Prices: Uber's amounts (`amount_e5`, times 10^5) become `Money` in minor units, tax included.
- The webhooks carry the event and the order's id: `order($notification->reference)` reads it.
- The week's hours are the menu's (`Menu::$hours`, the store's hours being the union of its menus');
  `setHours()` sends the dates apart only, as holiday hours.
- An item suspended with no end is suspended for a year: Uber needs a date.

## Not done here

Nothing is left in `NotSupportedException`. Not covered, though documented: price adjustments and
fulfillment issues (mostly retail), the provisioning calls themselves (`pos_data`), the reporting
API, busy mode and preparation times.

Not public, so not used: the allergen values Uber expects (free strings: the EU-14 names are sent),
the webhook's response time limit, the general rate limits.

## What it takes

- A **Developer Dashboard** account and application at developer.uber.com, a **non-disclosure and
  API licence agreement** with Uber, and **Uber's written approval**: the scopes (`eats.store`,
  `eats.order`, `eats.store.orders.read`, `eats.store.status.write`) are granted to the application
  by Uber's team.
- **Test stores** from Uber's Integration Tech Support, a joint end-to-end check, then a production
  application and a pilot store.
- The **client id and secret** (the secret also checks the webhooks), the **store id**, the
  **webhook URL** set in the dashboard (Primary Webhook URL), and the store linked to the
  application (by Uber, or by the merchant through `authorizationUrl()`).

License: LGPL-3.0-or-later.
