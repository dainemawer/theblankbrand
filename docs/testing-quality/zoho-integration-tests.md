# Zoho Integration and Storefront Tests

These are integration tests, run from the command line against a real site with
`wp eval-file`. They drive WooCommerce's own cart, orders and queue rather than
mocking them, because the risk they cover is that several systems agree on one
number or one state.

```bash
wp eval-file mu-plugins/tbb-zoho/tests/integrity-baseline.php
wp eval-file mu-plugins/tbb-zoho/tests/regression-test.php
wp eval-file themes/the-blank-brand/tests/bulk-pricing-test.php
wp eval-file themes/the-blank-brand/tests/theme-regression-test.php
wp eval-file mu-plugins/tbb-zoho/tests/dry-run-order.php
bash themes/the-blank-brand/tests/missing-vendor-test.sh
```

## What each one covers

| Script | Covers |
|---|---|
| `integrity-baseline.php` | Invariants that must hold whatever the data: Zoho reachable, no negative stock, every colour has a swatch, every product has price tiers, headline price equals charged price, writes blocked outside the allow-list. It also diffs the catalogue against a saved snapshot, so a change that breaks no assertion but moves hundreds of products is still visible. Re-record deliberately with `BASELINE=save`. |
| `regression-test.php` | The order sync, status screen, stock webhook and Zoho client, against a **mocked Zoho**. |
| `bulk-pricing-test.php` | Cart tier pricing through a real cart, including that the page and the cart quote one price for a product on sale. |
| `theme-regression-test.php` | The header cart badge, menu labels, the swatch stock lookup (compared with the answers the old lookup gave, for every product), and the Bulk Discounts admin box. |
| `dry-run-order.php` | Builds a real order and prints every call the sync would have made, then deletes it. |
| `missing-vendor-test.sh` | The theme degrades instead of returning a 500 when `vendor/` is missing. |

## Why the regression tests mock Zoho

Dry run proves the *shape* of what the integration sends and the *order* of the
calls. It cannot prove that Zoho accepts them, because dry run sends nothing and
so nothing can be refused. Three defects found against the live Zoho were of that
kind, and each passed a clean dry run first:

- Sales order lines were sent by `sku`. Zoho identifies a line by `item_id` and
  rejected the order with 400.
- A customer's email was sent on the contact. Zoho only stores it on a contact
  person, so the contact was created with no email and could never be found
  again.
- A query value was not percent-encoded. A plus-addressed email such as
  `name+shop@gmail.com` reached Zoho with a bare `+`, which Zoho rejects, so the
  order never synced.

The mock answers every Zoho call, so the real write path (contact, sales order,
confirmation) runs without anything being sent. "Dry run off" is simulated with a
filter on the option and never saved, so a crash cannot leave the integration
live.

## Where they are safe to run

`integrity-baseline.php` is read-only and safe anywhere. The others create and
remove orders, products or menus, or briefly rewrite stock, and **refuse to run
unless `WP_ENVIRONMENT_TYPE` is `local`** or `TBB_ALLOW_TESTS` is defined. A live
shop reports `production`, and a test that changed live stock for a few seconds
would be selling it.

The regression test also refuses to run when dry run is off.

## Lint

These scripts print to a terminal and query the database directly, so the PHPCS
rules for web output and cached queries are switched off for them, with the
reason stated at the top of each file. Everything else, including formatting,
still applies.
