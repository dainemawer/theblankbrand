# Setup: Zoho Inventory Integration

**Status:** Exercised end to end against the live Zoho organisation (October 2026). Dry run is on by default.
**Owner:** Daine Mawer
**Last updated:** 2026-10-13
**Affected area:** `mu-plugins/tbb-zoho/` (a must-use plugin; it no longer lives in the theme)

---

## 1. What's built

Zoho Inventory is the source of truth for **stock and price**. WooCommerce
reads both and never writes either back. A paid order goes the other way: it
becomes a **confirmed Sales Order** in Zoho, and the warehouse is emailed the
pick/pack details.

Three rules the code is built around:

- **WooCommerce drives.** The sync walks the WooCommerce catalogue and asks Zoho
  about each SKU. A Zoho item with no website SKU is ignored, because Zoho
  carries items the shop does not sell.
- **Stock means `available_for_sale`, never `stock_on_hand`.** Zoho reserves
  stock against confirmed sales orders. Stock on hand still counts units already
  committed to someone else, and publishing it is how the site came to offer
  units it could not supply.
- **Zoho is never written to for stock or price.** This is enforced in three
  places: an allow-list in the client, the absence of `items.UPDATE` from the
  token's grant, and the webhook treating its payload as a notification rather
  than data.

| File | Purpose |
|---|---|
| `src/Settings.php` | Settings → Zoho Inventory admin page (credentials, dry-run toggle, test-connection button). |
| `src/Client.php` | OAuth token handling (a rejected token is replaced and retried once; a failed refresh backs off for a minute), the write allow-list, and dry-run interception. |
| `src/StockSync.php` | Every 10 minutes: reconciles stock, price and the 50+ bulk tier for every SKU the shop sells. |
| `src/StockWebhook.php` | `POST /wp-json/tbb/v1/zoho-stock`. Validates the SKU, queues one de-duplicated job and answers `202`. See §5. |
| `src/OrderSync.php` | On `woocommerce_payment_complete`: two independent, retrying Action Scheduler jobs. One creates the contact and the confirmed Sales Order in Zoho, the other emails the warehouse. |
| `src/Mail.php` | One sender (`noreply@`) for everything the shop sends, with replies and the "contact us" line pointed at the support address. |
| `src/StatusReport.php` | WooCommerce → Status → Zoho Sync: last run, counts, unmatched SKUs, whether writes are live, and any orders that were only simulated. |

**Dry run is on until you turn it off.** While it is on, nothing is written to
Zoho: each write is recorded and answered with a made-up id so the whole
sequence still runs. An order that goes through a dry run gets the note
`SIMULATED … this order is not in Zoho` and is listed on the status screen. It is
never marked synced, because it is not in Zoho.

Everything is inert until the settings page is filled in and "Enable sync" is
checked.

## 2. Get Zoho API access

1. Go to the [Zoho API Console](https://api-console.zoho.com) (log in as the
   account that owns the Zoho Inventory organisation).
2. Create a **Self Client**.
3. Generate a grant token with these scopes:
   - `ZohoInventory.salesorders.CREATE` (creates the order and confirms it)
   - `ZohoInventory.contacts.CREATE`
   - `ZohoInventory.contacts.READ`
   - `ZohoInventory.items.READ`
   - `ZohoInventory.settings.READ` (covers organization info — used by the
     "Test connection" button; there is no separate `organizations.*` scope)

   **Do not** grant `ZohoInventory.items.UPDATE`. Its absence is what stops the
   site writing stock or price to Zoho even if the in-code guard were bypassed.
   Scopes beyond the five above are unused. The token also cannot delete, so
   any sales order or contact created during testing has to be removed by hand
   in Zoho.
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

## 5. Point Zoho at the stock webhook (optional)

The webhook is a speed-up, not a requirement. The ten-minute reconcile in §1
does the same job, so a missed notification costs a delay and nothing else.
Note also that a rule on the Item module fires when an *item* is edited, so it
will not fire when an order reserves stock, which is the case that matters most.
That case still waits for the reconcile.

1. In Zoho Inventory: **Settings → Automation → Webhooks** → create a webhook
   (`POST`, URL = the webhook URL from step 4), then **Workflow Rules** → a rule
   on the Item module, "Edited each time", with that webhook as its action.
2. Send only the SKU:
   ```json
   { "sku": "${item.sku}" }
   ```
   A quantity in the body is ignored. Zoho's payload offers `stock_on_hand`,
   which counts units already reserved against open orders, so the site fetches
   the figure itself and publishes `available_for_sale`.
3. Add the shared secret as a header: `X-Zoho-Webhook-Secret: <secret>`
   (or append `?secret=<secret>` to the URL if the workflow rule only supports
   plain URLs, bearing in mind the secret then appears in server logs).

The route answers `202` straight away and queues a job. Several notifications
for one SKU collapse into a single job, and a bulk edit in Zoho cannot tie up
the site's workers. Whether Zoho's workflow actually calls the route, and what
its custom headers allow, can only be tested against a publicly reachable URL.

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

1. Within a minute or two of the order reaching **Processing**, open the order
   and read the **Order notes**:
   - **`Synced to Zoho Inventory as Sales Order #…`** means a confirmed Sales
     Order exists in Zoho. A real id starts with your organisation's prefix; a
     simulated one starts `9000`. The note only appears after the confirm call
     succeeds, so it means a real sync and nothing else.
   - **`SIMULATED: dry run is on …`** means nothing was sent. That is correct
     while testing and wrong once the site is live.
   - **`Not sent to Zoho — <sku> is not in Zoho …`** means a line could not be
     matched to a Zoho item. The whole order is held back rather than sent with
     a line missing, and no retry is queued, since retrying cannot make the item
     exist.
2. In Zoho, the Sales Order carries the WooCommerce order number as its
   reference, with one line per item (sent by Zoho `item_id`, resolved from the
   SKU) and the status **Confirmed**, not Draft. The customer exists as a
   contact with their email on a contact person.
3. The warehouse inbox received the pick/pack email, in the shop's branded
   email layout and with no prices.
4. A failed attempt leaves no note at all, only a retry five minutes later, so
   if nothing appears check **WooCommerce → Status → Logs** (source `zoho`) and
   **Tools → Scheduled Actions** (group `zoho`) — see §8.
5. Confirm the reservation: the item's available quantity in Zoho drops by the
   amount ordered, and the website's figure follows on the next reconcile.

## 7. Go live

1. Repeat step 2's grant-token exchange scoped to the **production** Zoho
   Inventory organisation (or reuse the same Self Client if it already
   covers it) to get a production refresh token.
2. In **Settings → Zoho Inventory**, swap the Organization ID and refresh
   token for the production values, and set the warehouse email to the real
   address.
3. Re-run **Test connection** to confirm it now resolves to the production
   organisation name (not the test one) before leaving the page.
4. Switch Paystack to live keys, and allow search engines again.
5. **Turn dry run off.** Until you do, no order reaches Zoho.
6. Send any orders that were only simulated while testing:
   ```bash
   wp eval 'echo TBB\Zoho\OrderSync::requeue_simulated(), " order(s) queued\n";'
   wp action-scheduler run --group=zoho
   ```
7. Re-point the Zoho workflow rule at the same webhook URL/secret, if you use
   one. No change is needed there, since the site side didn't move.

Two decisions were made on 14 October, both by Megan:

- **VAT.** The website adds 15% at checkout, so the customer pays what Zoho
  shows. The order feed is unchanged: it sends ex-VAT unit prices and Zoho adds
  its own 15%. Tax is a database setting, so run this once on each site:

  ```bash
  wp eval-file wp-content/mu-plugins/tbb-zoho/tools/vat.php            # report only
  APPLY=1 wp eval-file wp-content/mu-plugins/tbb-zoho/tools/vat.php    # apply
  wp eval-file wp-content/mu-plugins/tbb-zoho/tests/vat-test.php       # prove it (local)
  ```

- **Invoices.** Raised by hand in Zoho for now. The site creates a confirmed
  sales order and stops there: it raises no invoice and records no payment.
  The sales order carries the customer's name and email, the lines with
  VAT-exclusive rates, and the order number as its reference. It does **not**
  carry the customer's address or phone: those live only on the WooCommerce
  order and in the warehouse email, so whoever invoices needs the order open
  beside Zoho. Sending the address to the Zoho contact is a small change if a
  tax invoice needs it. Reconsider automating invoices once volumes justify it.

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

- **WooCommerce → Status → Zoho Sync** — when the sync last ran, whether it
  failed (the last good run's figures stay below the failure, labelled as that
  run's), whether writes are live or simulated, unmatched SKUs, and any orders
  that never reached Zoho.
- **WooCommerce → Status → Logs**, source `zoho` — every API/email failure
  is logged here with the order id and stage.
- **Tools → Scheduled Actions** — filter by group `zoho` to see pending,
  running, and failed sync/notification/reconciliation jobs. Failed order
  jobs auto-retry up to 5 times with backoff before giving up (the order
  gets a note saying it needs manual follow-up when that happens).
- An order stuck unsynced with no log entry usually means "Enable sync" is
  off, or a credential is missing — `OrderSync` won't even register hooks
  in that case.
- The stock sync cannot run on a site that WordPress cron never reaches. If the
  reconcile has not run for hours, check the site is not behind an HTTP password
  that answers before WordPress does.
