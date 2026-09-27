# Haval Laravel API

Laravel backend for the Masdar Al-Adawat storefront. It reads the product catalog from the local `02- dolibar-erp` Dolibarr API and serves it to the `03- frontend` React app. Dolibarr is the source of truth: nothing about a product is stored here. The Dolibarr API key stays on this server and is never sent to the browser.

## Local setup

1. Start `02- dolibar-erp` at `http://127.0.0.1:8000` (`php -S 127.0.0.1:8000 -t .` from that directory).
2. Run `composer install` in this directory.
3. Copy `.env.example` to `.env`, run `php artisan key:generate`, and set `DOLIBARR_API_KEY` to a Dolibarr API token.
4. Run `php artisan serve --host=127.0.0.1 --port=8001`.

The local `.env` is already configured for this workspace. Keep it private.

## API

| Route | Purpose |
| --- | --- |
| `GET /api/erp/health` | Check the Dolibarr connection |
| `GET /api/brands` | List Dolibarr product categories used as brands |
| `GET /api/products?limit=20&page=1&brand=11&q=BS012` | Search sellable products by brand, name or reference |
| `GET /api/products/{id}` | Fetch one product by Dolibarr ID |
| `GET /api/products/ref/{ref}` | Fetch one product by reference |
| `GET /api/products/ref/{ref}/image` | Product photo from the ERP document store |
| `GET /api/payment-methods` | Payment methods currently offered |
| `POST /api/checkout` | Place an order |
| `GET /api/orders/{reference}?key=` | Read an order, or its payment status |

Products come back with a selling price already in IQD, a stock status, and a display name — never a raw ERP cost. Only local React development origins (ports 5173 and 5174) are allowed by CORS.

## Pricing

`App\Services\PricingEngine` ports the pricing rules of the WooCommerce store it replaces:

```
ERP cost -> apply profit % -> convert to IQD -> round UP to the increment
```

Defaults (all overridable in `.env`, see `config/shop.php`): 25% profit, 1320 IQD/USD, 250-IQD increments. A product with no cost cannot be priced and is never published.

**`SHOP_COST_CURRENCY` must stay `USD`.** Dolibarr records costs in USD (parts run from $0.01 to about $67.50). The WooCommerce store had this set to `IQD`, which divided every cost by the exchange rate and collapsed the entire catalog onto the 250-IQD floor — a $4.81 part was priced at 250 IQD, about 19 cents. `PricingEngineTest` guards against reintroducing this.

If the ERP ever switches to IQD costs, flip this setting at the same time or every price on the site will be wrong by the exchange rate.

## Stock rules

Out-of-stock parts are hidden from the storefront and stock under 5 is shown as "low stock" rather than an exact count, matching the WooCommerce behaviour. Both thresholds are configurable.

These rules are pushed into the Dolibarr query as `sqlfilters` (`t.stock:>:0`, `t.cost_price:>:0`) rather than applied after fetching. Filtering at the ERP keeps Dolibarr's own paging aligned with what the storefront shows — otherwise a page of 50 could arrive nearly empty and `has_more` would be meaningless.

## Tests

```bash
php artisan test
```

92 tests covering the pricing maths, the catalog endpoints, checkout, and the payment decision. Dolibarr and FIB are both faked at the HTTP layer, so neither needs to be running — but the order tests need the `haval_shop_test` database (see below).

## Checkout and payments

Orders, bank-card payments (FIB ACS24) and cash on delivery are implemented. Prices are re-derived from the ERP at checkout and stock re-checked, so nothing the browser claims about price is trusted. A payment is confirmed only by the gateway's status check — never by the browser's return or the callback body.

Order and invoice write-back to Dolibarr is built but **off by default**; it needs `DOLIBARR_PUSH_ORDERS=true` and `DOLIBARR_CUSTOMER_ID`.

Full detail, including the state rules and the retry semantics, is in `docs/checkout-and-payments.md`.

## Database

Orders are persisted in MySQL (`haval_shop`), because FIB's server-to-server callback has to find the order it refers to. The **catalog** still has no local table — it is read live from Dolibarr on every request.

Tests use `haval_shop_test`. The local PHP has no SQLite driver, which is why `phpunit.xml` points at MySQL rather than an in-memory database.

## Not mapped yet

Customer and dealer accounts, per-dealer discounts, Cash at Store, and the FIB QR and NASS gateways are not implemented — the last two are blocked on merchant credentials that do not exist. See `docs/gaps.md`.
