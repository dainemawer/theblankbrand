# Spec: Bulk (Quantity-Tiered) Pricing

**Status:** Implemented, with a different data model from this draft. See §15.
**Owner:** Daine Mawer
**Last updated:** 2026-10-13
**Affected area:** `themes/the-blank-brand` (WooCommerce module)

---

## 1. Problem

Every product carries its bulk-pricing table in the free-text description, e.g.

```
PRICES (excl VAT)

1-9 items     R180
10-49 items   R165
50+ items     R150
```

…or a variation of it. This has two failures:

1. **It is decorative, not functional.** The cart and checkout charge the single
   variation price regardless of quantity. The discount the copy promises is never
   actually applied.
2. **It is free text.** Formats differ between products, so there is no reliable
   structured value to read. The price effectively lives in prose.

We want quantity tiers to be **applied automatically** at cart/checkout, and
**managed** from one structured place instead of being typed into descriptions.

## 2. Goals

- Apply a per-product quantity discount automatically in the cart and at checkout.
- Manage tiers from structured data (a global default plus per-product overrides),
  not from description copy.
- Render the tier table on the single-product page from that same structured data,
  so the displayed prices and the charged prices can never drift.
- Keep the existing custom-module architecture (10up framework, `src/`); no new
  third-party plugin.

## 3. Non-goals

- Customer-group / wholesale-account pricing (logged-in B2B tiers). Out of scope.
- Coupon or cart-level promotional discounts.
- Cross-product cart-aggregate tiers (mixing unrelated products to reach a tier).
- Currency/VAT-rate logic — VAT is handled by WooCommerce tax settings (see §9).

## 4. Decisions (original draft)

> **Two of these four were reversed when the feature was built.** *Pricing model* and
> *Configuration* are marked below; §15 records what runs and why. *Tier scope* and
> *Source of truth* stand.

| Decision | Choice | Rationale |
|---|---|---|
| **Tier scope** | **Per product** — sum all variations (sizes/colours) of one product | Matches how blanks are bought (a size run of one shirt should reach the discount). |
| **Pricing model** | **Percentage off base price**, stored as tiers. **Superseded: absolute prices, see §15.** | Bump the base price once and every tier follows; no re-keying absolute prices. |
| **Configuration** | **Global default ruleset + optional per-product override**. **Superseded: per-product tiers only, see §15.** | One ruleset covers the catalogue; products that differ override locally. |
| **Source of truth** | **Structured data**, not description text | Single source; the on-page table renders from the same data the cart uses. |

## 5. Data model

A **ruleset** is an ordered list of tiers. Each tier is the *minimum quantity* at
which a *discount percentage* applies. The upper bound is implied by the next
tier's minimum; the last tier is open-ended.

```php
// Canonical shape (PHP array; stored serialized).
[
    [ 'min' => 1,  'pct' => 0.0  ], // base — no discount (R180)
    [ 'min' => 10, 'pct' => 8.33 ], // R165 ≈ 8.33% off R180
    [ 'min' => 50, 'pct' => 16.67 ], // R150 ≈ 16.67% off R180
]
```

- **Base price** = the variation's own *regular price* in WooCommerce. Tier 1 is
  always `min => 1, pct => 0` (the base). Validation enforces this.
- **Global default** → stored in option `tbb_bulk_tiers`.
- **Per-product override** → product meta `_tbb_bulk_tiers`. Empty/absent ⇒ use global.

### Invariants

- Tiers are sorted ascending by `min`; `min` values are unique and ≥ 1.
- `pct` is `0 ≤ pct < 100`.
- A ruleset always contains a `min => 1` tier (the base).
- An empty per-product override is *not* the same as a ruleset with no discounts —
  empty means "inherit global".

## 6. Pricing engine

The single hook that does the work is `woocommerce_before_calculate_totals`.

```
on woocommerce_before_calculate_totals( $cart ):
    # Pass 1 — total quantity per parent product across the whole cart
    qty_by_product = {}
    for item in cart.get_cart():
        qty_by_product[ item.product_id ] += item.quantity

    # Pass 2 — set each line's price from its product's tier
    for item in cart.get_cart():
        ruleset    = resolve_ruleset( item.product_id )   # override ?: global
        tier_pct   = tier_for( ruleset, qty_by_product[ item.product_id ] )
        base       = item.data.get_regular_price()        # canonical, never the modified price
        item.data.set_price( round( base * (1 - tier_pct/100), wc_decimals ) )
```

Key points:

- **`product_id`, not `variation_id`**, groups the quantity — that is what makes the
  tier *per product* across sizes/colours.
- Price is always derived from **`get_regular_price()`**, never from the possibly
  already-modified `get_price()`. `woocommerce_before_calculate_totals` can fire
  more than once per request, so the calculation must be **idempotent**.
- Set ex-VAT prices; WooCommerce applies tax on top (see §9).
- Rounding uses `wc_get_price_decimals()` to match store display.

### On-sale products

If a variation is on sale, its `get_regular_price()` is the pre-sale price. v1
applies tiers against **regular price** and ignores sale price (sale + bulk
stacking is out of scope). Documented as a known limitation (§11).

## 7. Front-end display

### Single product

Render the resolved ruleset as a real table from the structured data, hooked into
`woocommerce_single_product_summary`. Placement near the price (the theme already
relocates price to priority 25 and the full description to priority 15 in
`src/WooCommerce.php`).

- Columns: quantity range ("1–9", "10–49", "50+") and the resulting unit price,
  computed `base × (1 − pct)`, labelled **excl VAT**.
- Computed from the variation base price, so the table is always self-consistent
  with what the cart charges.
- Replaces the hand-typed `PRICES` block in descriptions (removed by the migration,
  §10).

### Cart nudge (optional, phase 3)

On each cart line, when the next tier is reachable, show a hint:
"Add 4 more to pay R165 each." Computed from the product's current cart quantity
and the next tier's `min`. High-conversion, low-risk; deferred to polish.

## 8. Admin / management

Mirrors the existing `ColourSwatchesAdmin` pattern.

- **Global tiers** → a WooCommerce settings tab (`woocommerce_get_settings_pages`
  or a section under **WooCommerce → Settings → Products**). Repeater of
  `{ min qty, discount % }` rows with validation against §5 invariants.
- **Per-product override** → a panel in the Product Data metabox. Empty by default
  (= inherit global); filling it stores `_tbb_bulk_tiers`. A visible "Inheriting
  global tiers" state when empty.

## 9. VAT

Prices in copy are **excl VAT**. This requires WooCommerce tax to be configured as
**"I will enter prices exclusive of tax."** The engine sets ex-VAT line prices and
WooCommerce adds VAT during totals. If the store is ever switched to VAT-inclusive
entry, the engine's `set_price()` semantics must be revisited. Flagged as a
precondition, not handled in code.

## 10. Migration (one-off)

A WP-CLI command (`wp tbb bulk-pricing migrate`) to move off description text:

1. Scan published products' `post_content` for a `PRICES`-style block.
2. Parse `qty range → price` lines into a tentative ruleset (prices → % off the
   lowest-qty price).
3. Where the parsed ruleset differs from the global default, write it as a
   per-product override (`_tbb_bulk_tiers`); otherwise leave the product on global.
4. With `--strip`, remove the parsed block from `post_content`.

Run modes: `--dry-run` (default, report only) → review → `--commit` → `--strip`.
Parsing is **migration-only and disposable** — it never runs at request time, so a
parse miss is a logged row to fix by hand, not a mispriced order.

## 11. Known limitations (v1)

- Sale price + bulk tier do not stack; tiers apply to regular price.
- No cross-product aggregate tiers.
- No per-customer / wholesale tiers.

## 12. Build phases

1. **Engine + single-product table** — functional core, driven by the global
   ruleset. Usable immediately. *(PHP only, build-free.)*
2. **Admin UI** — global settings tab + per-product override panel.
3. **Polish** — cart nudge; migration command + description strip.

## 13. Files

| File | Purpose |
|---|---|
| `themes/the-blank-brand/src/BulkPricing.php` | Engine (cart hook) + single-product table render. |
| `themes/the-blank-brand/src/BulkPricingAdmin.php` | Global settings tab + per-product override panel. |
| `themes/the-blank-brand/assets/css/...` | Tier-table + cart-nudge styles (needs a build). |
| WP-CLI command (location TBD) | One-off description migration. |

Modules in `src/` auto-register via the 10up framework
(`ModuleInitialization::init_classes`), gated on `class_exists( 'WooCommerce' )` as
`ColourSwatches` and `WooCommerce` already are.

## 14. Test plan

- **Tier boundaries:** qty 9 vs 10, 49 vs 50 resolve to the correct tier.
- **Per-product aggregate:** 6 of size S + 6 of size M (same product) ⇒ tier 2.
- **Isolation:** two different products in one cart are tiered independently.
- **Idempotency:** updating cart quantities repeatedly yields a stable price (no
  compounding discount).
- **Override vs global:** a product with `_tbb_bulk_tiers` ignores the global
  ruleset; one without inherits it.
- **VAT:** ex-VAT line price + correct VAT line at checkout.
- **Display parity:** single-product table prices equal the charged unit prices at
  each tier.

## 15. As built (October 2026)

The engine in §6 and the per-product aggregation in §4 were built as specified.
The data model and where the numbers come from were not, and this section records
the difference so the draft above is not mistaken for what runs.

| Draft | As built | Why |
|---|---|---|
| Tiers are a **percentage** off the regular price | Tiers are **absolute prices** | The numbers already exist in Zoho, which is the source of truth for price. Typing percentages into WooCommerce would have been a second copy. |
| A **global default** ruleset plus a per-product override (`tbb_bulk_tiers`, `_tbb_bulk_tiers`) | A per-product `_tbb_price_tiers`, no global default | Products in this catalogue differ in the 50+ price, and Zoho holds it per item. |
| Tier 1 is `min 1, pct 0` of the regular price | Tier 1 is read **live from the product's own price**, never from the stored tiers | Storing it twice is what let a page advertise one price while checkout charged another. |
| Admin: a settings tab and a per-product override panel | A **read-only** panel showing the tiers and where each figure comes from. A product with no structured tiers still gets the old free-text editor, since the storefront falls back to it. | Prices are managed in Zoho, so an editable copy in WooCommerce would be changed and then overwritten. |
| Tiers apply to the **regular price**; sale price is ignored (§6, §11) | Tiers apply against the product's **current price**, and a tier is applied only when it beats that price | A live sale must never be undone by a bulk tier, and a stale tier must never raise what someone is charged. |
| Migration command (§10) | A one-off parse of the description tables into `_tbb_price_tiers` for the existing products | Done once; the parser never runs at request time. |

Stored shape:

```php
[
	'tiers'      => [
		[ 'min' => 1,  'max' => 49,   'price' => 200, 'source' => 'migrated' ],        // price is ignored; read live
		[ 'min' => 50, 'max' => null, 'price' => 180, 'source' => 'zoho:item.rate' ],  // kept in step by the Zoho sync
	],
	'quote_from' => 100,    // no price at or above this quantity
	'version'    => 1,
]
```

**Where the figures come from.** The first tier is the product's price, which the
Zoho sync keeps equal to the Zoho *Retail* price book. The 50+ tier is Zoho's
`item.rate`, written by the sync. When variations of one product disagree the
lowest is advertised, and the sync counts how many products do.

**One rule for the page and the cart.** `BulkPricing::effective_tier_price()`
decides whether a tier gives a price at all. The cart uses it to charge, and the
tier table uses it to advertise, so they cannot disagree. A product on sale at
R150 with a stored 50+ tier of R180 shows no 50+ row and charges R150 throughout.
Before this, the table listed the R180 row that the cart would never charge.

**VAT (§9).** Decided 14 October: the website adds 15% VAT at checkout, so the
customer pays R207 for a R180 item and Zoho shows the same R207. This is the
design §9 always described: WooCommerce tax on, prices entered excluding VAT.
The order feed already sends the ex-VAT unit price (`get_item_total` without tax),
and Zoho adds its own 15% on top, so the two totals agree with no code change.
Until 14 October the tax setting was off, which is why the two disagreed.

Tax is a database setting and does not travel with a deploy. Run
`tools/vat.php` once per site (see `docs/setup/zoho-inventory-setup.md`), and
`tests/vat-test.php` proves the totals and the Zoho payload agree to the cent.

**Tests.** `themes/the-blank-brand/tests/bulk-pricing-test.php` drives a real cart
and covers the boundaries, per-product aggregation, isolation, idempotency, the
chain from cart to order to Zoho payload, and display parity on sale items.
