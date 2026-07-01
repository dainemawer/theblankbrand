# Spec: Bulk (Quantity-Tiered) Pricing

**Status:** Draft / proposed
**Owner:** Daine Mawer
**Last updated:** 2026-06-19
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

## 4. Decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| **Tier scope** | **Per product** — sum all variations (sizes/colours) of one product | Matches how blanks are bought (a size run of one shirt should reach the discount). |
| **Pricing model** | **Percentage off base price**, stored as tiers | Bump the base price once and every tier follows; no re-keying absolute prices. |
| **Configuration** | **Global default ruleset + optional per-product override** | One ruleset covers the catalogue; products that differ override locally. |
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
