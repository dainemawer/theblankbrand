# Setup: Zoho Inventory Integration

**Status:** Code complete, pending Zoho credentials
**Owner:** Daine Mawer
**Last updated:** 2026-07-01
**Affected area:** `themes/the-blank-brand/src/Zoho/`

---

## 1. What's already built

Zoho Inventory becomes the source of truth for stock. On payment completion,
WooCommerce pushes a Sales Order to Zoho and emails the warehouse the
pick/pack details; stock changes in Zoho flow back to WooCommerce via
webhook (with a polling reconciliation job as a backstop).

| File | Purpose |
|---|---|
| `src/Zoho/Settings.php` | Settings → Zoho Inventory admin page (credentials, toggle, test-connection button). |
| `src/Zoho/Client.php` | OAuth token handling + thin API wrapper (contacts, sales orders, items). |
| `src/Zoho/OrderSync.php` | On `woocommerce_payment_complete`: creates the Zoho Sales Order and emails the warehouse, as two independent, retrying Action Scheduler jobs. |
| `src/Zoho/StockWebhook.php` | `POST /wp-json/tbb/v1/zoho-stock` — applies an incoming stock change to the matching product by SKU. |
| `src/Zoho/StockSync.php` | Recurring 30-minute job that re-pulls all Zoho stock as a safety net. |

All of it is inert until the settings page is filled in and "Enable sync" is
checked — nothing runs against production until you switch it on.

## 2. Get Zoho API access

1. Go to the [Zoho API Console](https://api-console.zoho.com) (log in as the
   account that owns the Zoho Inventory organisation).
2. Create a **Self Client**.
3. Generate a grant token with these scopes:
   - `ZohoInventory.salesorders.CREATE`
   - `ZohoInventory.contacts.CREATE`
   - `ZohoInventory.contacts.READ`
   - `ZohoInventory.items.READ`
   - `ZohoInventory.organizations.READ` (used by the "Test connection" button)
4. Exchange the grant token for a **refresh token** (one-time call):
   ```
   curl -X POST https://accounts.zoho.<dc>/oauth/v2/token \
     -d grant_type=authorization_code \
     -d client_id=<CLIENT_ID> \
     -d client_secret=<CLIENT_SECRET> \
     -d code=<GRANT_TOKEN>
   ```
   Save the `refresh_token` from the response — this is the long-lived
   credential; access tokens are minted from it automatically by
   `Client.php` and cached for the rest of their lifetime.
5. Note your **data centre** (`<dc>` above — `com`, `eu`, `in`, `com.au`, or
   `ca`; Canada's *accounts* domain is `accounts.zohocloud.ca`, not
   `accounts.zoho.ca` — the settings page's dropdown already accounts for
   this).

## 3. Set up a test organisation

Zoho Inventory has no dedicated sandbox. Create a second **organisation**
under the same Zoho account instead — Settings screen inside Zoho Inventory
→ "Add Organization" — and use its Organization ID for testing. This keeps
test sales orders and stock changes fully isolated from production.

- Note the test org's **Organization ID** (Inventory → Settings →
  Organization Profile).
- Re-create a handful of real SKUs in the test org so line items resolve
  during testing (contacts/items don't carry over from production).

## 4. Configure the site

1. In wp-admin, go to **Settings → Zoho Inventory**.
2. Enter: data centre, **test** Organization ID, Client ID, Client secret,
   Refresh token, and a warehouse notification email (use your own inbox for
   now, swap to the real warehouse address before going live).
3. Save, then click **Test connection** — it should report the test
   organisation's name back. If it fails, check **WooCommerce → Status →
   Logs** (source: `zoho`) for the underlying error.
4. Copy the **webhook URL** and **shared secret** shown under the settings
   fields — you'll need both for the next step.

> Note: the webhook needs a publicly reachable HTTPS URL. This site's Local
> dev environment can't receive it — webhook testing has to happen on
> staging or production. Order sync and the "Test connection" button work
> fine locally since they're outbound calls.

## 5. Point Zoho at the stock webhook

1. In Zoho Inventory: **Automation → Workflow Rules** → create a rule on the
   Item module, triggered on stock changes.
2. Action: **Send webhook** (or "Custom function" invoking a webhook), method
   `POST`, URL = the webhook URL from step 4.
3. Set a custom JSON payload so the field names match what
   `StockWebhook::handle()` expects:
   ```json
   { "sku": "${item.sku}", "stock_on_hand": "${item.stock_on_hand}" }
   ```
4. Add the shared secret as a header: `X-Zoho-Webhook-Secret: <secret>`
   (or append `?secret=<secret>` to the URL if the workflow rule only
   supports plain URLs).

If Zoho's actual field placeholders differ from the above (worth confirming
once you're in the workflow rule builder — the exact token names vary by
Zoho product version), adjust either the JSON template in Zoho or the field
names read in `StockWebhook::handle()` to match — whichever is less friction
at the time.

## 6. End-to-end test (on staging/production, against the test org)

Payment is handled by the **Paystack** gateway (`woo-paystack` plugin). Its
`payment_complete()` call is what fires `woocommerce_payment_complete` —
the hook `OrderSync` listens on — so a real (test-mode) payment is the only
way to trigger the Zoho sync and warehouse email; changing an order's status
by hand in wp-admin does **not** fire it.

### 6.1 Put Paystack in test mode

1. **WooCommerce → Settings → Payments → Paystack**.
2. Confirm **Test mode** is checked (it defaults to on).
3. Enter your **Test Secret Key** and **Test Public Key** (from the Paystack
   dashboard — top-right mode toggle should read "Test" in red).
4. Note the **Payment Option**: `Popup` opens an inline Paystack modal at
   checkout; `Redirect` sends the customer to Paystack's hosted page and
   back. Either works for this test — the steps below note where it differs.
5. Leave **Autocomplete Order** unchecked for the first pass, so you can see
   the natural `processing` status a paid order lands in (see §6.4).

> The Paystack settings page shows its own webhook URL
> (`.../?wc-api=tbz_wc_paystack_webhook`) with a prompt to add it under
> [Paystack Dashboard → Settings → API Keys & Webhooks](https://dashboard.paystack.com/#/settings/developers).
> This is unrelated to the Zoho webhook in §5 — it's Paystack's own
> fallback so a payment still gets confirmed if the customer's browser
> never makes it back to the redirect/popup callback. Worth setting once,
> same reasoning as the Zoho reconciliation job in §1: don't rely solely on
> the synchronous callback.

### 6.2 Add to cart → checkout

1. On the storefront, add a product to the cart whose SKU exists in the
   **test** Zoho org (§3) — the sync will fail to find it in Zoho otherwise.
2. Go to cart → checkout.
3. Fill in billing/shipping with a real-looking email you can check (the
   warehouse email goes to the address configured in §4, not the customer's,
   but WooCommerce's own customer confirmation email will hit whatever you
   enter here).
4. Select **Paystack** as the payment method and place the order.

### 6.3 Pay with a Paystack test card

Use one of Paystack's published test cards ([full list in their docs](https://paystack.com/docs/payments/test-payments/) — card numbers are occasionally
added to, so check there if these stop working):

| Scenario | Card number | Expiry | CVV | PIN / OTP |
|---|---|---|---|---|
| Straight success, no extra verification | `4084 0840 8408 4081` | Any future date | `408` | none |
| Success via PIN + OTP (Verve) | `5060 6666 6666 6666 666` | Any future date | `123` | PIN `1234`, OTP `123456` |

Use the first row for the main happy-path test; use the second once to
confirm the PIN/OTP step doesn't break the popup/redirect flow.

- **Popup mode:** the modal opens without leaving checkout — enter the card
  details there, submit, and it closes back to the thank-you page on
  success.
- **Redirect mode:** you land on a Paystack-hosted page — enter the card
  details there and you're bounced back to the site's order-received page.

### 6.4 Confirm order status

1. You should land on WooCommerce's order-received ("Thank you") page.
2. In **WooCommerce → Orders**, the order should now be **Processing**
   (or **Completed**, if you enabled Autocomplete Order in §6.1) — not
   **Pending payment** or **On hold**.
3. Open the order and check the order notes for **"Payment via Paystack
   successful (Transaction Reference: …)"** — this confirms
   `payment_complete()` ran, which is what triggers everything in §6.5.
4. Two edge statuses to know, not bugs if you see them:
   - **On hold** — Paystack reports success but the amount or currency
     didn't match the order. `payment_complete()` is *not* called in this
     case, so Zoho sync/warehouse email won't fire until the order is
     manually resolved and completed.
   - **Failed** — the test card was declined. Retry with the success card
     from §6.3.

### 6.5 Confirm the Zoho + warehouse side effects

1. Within a minute or two of the order reaching **Processing**, check:
   - The order note **"Synced to Zoho Inventory as Sales Order #…"** appears.
   - A matching Sales Order exists in the test Zoho org.
   - The warehouse inbox (test address from §4) received the pick/pack
     email.
2. If either is missing, check **WooCommerce → Status → Logs** (source
   `zoho`) and **Tools → Scheduled Actions** (group `zoho`) — see §8.
3. In the test Zoho org, manually adjust that item's stock and confirm the
   change lands on the WooCommerce product within a minute (webhook) — or
   within 30 minutes even if the webhook is misconfigured (reconciliation
   job backstop).

## 7. Go live

1. Repeat step 2's grant-token exchange scoped to the **production** Zoho
   Inventory organisation (or reuse the same Self Client if it already
   covers it) to get a production refresh token.
2. In **Settings → Zoho Inventory**, swap the Organization ID and refresh
   token for the production values, and set the warehouse email to the real
   address.
3. Re-run **Test connection** to confirm it now resolves to the production
   organisation name (not the test one) before leaving the page.
4. Re-point the Zoho workflow rule at the same webhook URL/secret — no
   change needed there, since the site side didn't move.

## 8. Where to look when something breaks

- **WooCommerce → Status → Logs**, source `zoho` — every API/email failure
  is logged here with the order id and stage.
- **Tools → Scheduled Actions** — filter by group `zoho` to see pending,
  running, and failed sync/notification/reconciliation jobs. Failed order
  jobs auto-retry up to 5 times with backoff before giving up (the order
  gets a note when that happens).
- An order stuck unsynced with no log entry usually means "Enable sync" is
  off, or a credential is missing — `OrderSync` won't even register hooks
  in that case.
