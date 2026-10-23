# Monokulo for WHMCS

Take Monero for WHMCS invoices through your own
[Monokulo](https://docs.monokulo.com/), the self-hosted, non-custodial Monero
payment processor. Monokulo only ever holds a view key: it watches the
chain for payments to your wallet and tells WHMCS when an invoice is paid.
No spend key touches WHMCS or Monokulo.

- WHMCS **8.13** or **9.x**, on a PHP version your WHMCS supports, **8.1**
  or newer (WHMCS 8.13: 8.1 to 8.3; WHMCS 9: 8.2 and 8.3). Tested on PHP
  8.1, 8.2, 8.3 and 8.4.
- A store on [www.monokulo.com](https://www.monokulo.com/), or on your own Monokulo
  (its operator must have set its public address)
- GPL-3.0-or-later

## Install

1. Download `monokulo-whmcs.zip` from the
   [latest release](https://github.com/OceanEilonwy/monokulo-whmcs/releases/latest).
2. Unzip it into your WHMCS root. It adds three things, all under
   `modules/gateways/`: `monokulo.php`, the folder `monokulo/` and
   `callback/monokulo.php`.
3. In the WHMCS admin area: **Setup > Payments > Payment Gateways**,
   **All Payment Gateways**, then **Monokulo (Monero)**.

## Connect

On the gateway's settings (Setup > Payments > Payment Gateways, *Manage
Existing Gateways*):

1. Check the **Monokulo address**: `https://www.monokulo.com` (the default),
   or your own Monokulo's address as your browser opens it. **Save
   Changes**.
2. Press **Connect** under the settings.
3. Sign in to Monokulo (or sign up) in the page that opens. Monokulo finds
   your store by WHMCS's web address (its System URL):
   - one of your Monokulo stores has it: confirm;
   - none does: set one up (its name; the address is filled in), choose
     the wallet its money goes to, then **Back to WHMCS**.
4. You're back on the settings: *WHMCS is connected. Clients can now pay
   invoices with Monero.*

Monokulo only sends the connect flow back over **https** (plain http only
for `localhost` or a `.onion` address), so the System URL must be https.
The Connect button works for an hour after the settings page was opened,
and only in a browser signed in to the admin area.

A Monokulo store is one web address: WHMCS connects by its System URL's
host. If you change the System URL, connect again. **Connect again**
replaces the keys (Monokulo replaces its webhook too).

### What connecting keeps

Connecting hands WHMCS the store's keys and the entry of Monokulo's
[HTTP API](https://docs.monokulo.com/api/) (`/api/v1` on your Monokulo's
address, under its path if it is served under one), and, when your
Monokulo has an onion address, the same entry there. WHMCS's server calls
Monokulo with the store's secret key (`Authorization: Bearer sk_…`), which
picks the store. Every address it calls is a link or form from the entry:
it creates each order with the entry's `create-order` form and sends the
client to the order's checkout link. It keeps the entry for as long as
Monokulo says (an hour), so a client's Pay with Monero is usually one
request; it asks again when that is up (cheaply: Monokulo answers *not
modified* while it's the same), and at once when Monokulo refuses a form
from it. When **Send errors to Monokulo** is on, warnings and errors go to
the entry's `send-logs` form. Monokulo's refusals
(`application/problem+json`) are in the Activity Log with their reason and
a link to the problem's page.

A Monokulo served under a path (`https://example.com/monokulo`) works the
same: save that as the **Monokulo address**.

### Upgrading

From 0.1.0: nothing to do, the connection keeps working without
connecting again. The gateway adds its new columns the first time it runs.
Until the next client pays, the settings page says your Monokulo has no
onion address even if it has one: paying reads the API's entry, which says
where it is. Orders made before the upgrade have no *View in Monokulo*
link in the gateway log (WHMCS didn't keep it then); new ones do.

## Tor

If your WHMCS is also on a `.onion` address, clients who browse it stay on
Tor: the invoice's Pay with Monero button is on the onion address, and
it sends them to the order's checkout on your Monokulo's onion address,
never to its web address. Your Monokulo needs an onion address for that
(Tor inside it, or an external tor): the gateway's settings say whether it
has one. Without one, a client on Tor is told Monero payments can't be
made over Tor here yet (no Monokulo order is made: the API's entry,
asked again first, says whether there is an onion address), and the
Activity Log says why; clients on your web
address pay as usual.

WHMCS's own server always reaches Monokulo at its web address; only the
client's browser goes over Tor.

## What the client sees

On an unpaid invoice whose payment method is *Monokulo (Monero)*, a **Pay
with Monero** button (the same link is in the invoice email). Pressing it
sends the client to the Monokulo checkout for the invoice's balance: the
amount in XMR, the address, a QR code, and the payment's progress. Pressing
it again returns to the same Monokulo order while the balance is the same
and the order hasn't expired.

WHMCS asks Monokulo for the balance in the client's currency; Monokulo
converts it to XMR at its own exchange rate. The currency must be one your
Monokulo prices (or XMR itself). WHMCS's *Convert To For Processing*
setting has no effect: the invoice's own currency is always sent.

Until WHMCS is connected, the invoice says Monero payments aren't set up
yet instead of showing the button.

## Invoices

Monokulo tells WHMCS about each change with a signed webhook. The gateway
writes each one to WHMCS's gateway log (**Billing > Gateway Log**), and on
payment adds it to the invoice, so WHMCS does what it always does when an
invoice is paid (the payment confirmation email, provisioning, the client's
account).

| Monokulo event | What happened | Invoice | Gateway log result |
| --- | --- | --- | --- |
| (Pay with Monero pressed) | The client was sent to pay | Unpaid | Sent to pay, with the payment link |
| `order.unconfirmed`, `order.confirming` | A payment was seen; waiting for confirmations | Unpaid | Payment seen |
| `order.partial` | Less than the amount due arrived (underpaid) | Unpaid | Part paid |
| `order.paid` | Paid in full | Payment added (the Monokulo order id is its transaction id): **Paid** | Paid, with a link to the order in Monokulo (its `mk:dashboard-order`) |
| `order.overpaid` | Paid with more than was due | As paid: the amount due is added | Overpaid: the excess isn't refunded automatically |
| `order.expired` | The payment window closed without full payment | Unpaid; Pay with Monero starts a new Monokulo order | Expired: any funds received stay at the address |
| `order.double_spend_detected` | A payment was double-spent and no longer counts | unchanged | Double spend: fraud alert (the status change, if any, comes as its own event) |
| `order.double_spend_reversed` | That alert was wrong | unchanged | Double spend withdrawn |

An invoice can have more than one Monokulo order: Pay with Monero starts a
new one when the balance changed or the earlier one expired. The gateway
remembers each, and Monokulo keeps watching them all:

- A payment from **any** of them is added, under that order's id: the
  money arrived. If it was an older one, the gateway log asks you to check
  the amount against the balance.
- Any other event from an order that isn't the invoice's newest is a
  gateway log entry only (*Older order: …*); the invoice doesn't change.
- A paid invoice never goes back. A payment is added once per Monokulo
  order, whatever arrives after it; a payment to an invoice that is
  already paid is logged as *Paid again*, and more than was due after a
  payment was added is logged too, for you to refund from your wallet.

Amounts are sent in the currency's own decimals, without trailing zeros
(`12.5` EUR, `1500` JPY).

### Refunds

Monokulo is non-custodial: it never holds the money, so there are no
automatic refunds and the gateway has no refund function. Refund an
overpayment or the funds of an expired order from your wallet yourself. The
client may have left a refund address on the Monokulo checkout; it's on the
order in your Monokulo dashboard.

## Webhooks

Connecting registers WHMCS's webhook address with Monokulo:
`https://<your WHMCS>/modules/gateways/callback/monokulo.php`. Each
delivery is signed (`X-Monokulo-Signature`); the gateway refuses a missing
or wrong signature and one more than five minutes old. Monokulo retries a
delivery WHMCS didn't take; a delivery seen before changes nothing.
Deliveries and their answers are listed in Monokulo, in the store's
settings under *Webhooks*.

## Logs

- Warnings and errors (a refused webhook, an order Monokulo wouldn't
  create, a failed connect) go to **Utilities > Logs > Activity Log**,
  starting *Monokulo:*.
- Everything else goes to the **Module Log**, kept while module debug
  logging is on.
- Every line ends with the request's trace id, which matches Monokulo's own
  logs. **Send errors to Monokulo** also sends warnings and errors to your
  Monokulo, when the store there accepts client logs.

## Deactivate

Deactivating the gateway keeps its tables (`mod_monokulo_*`): payments
refer to them, and activating it again picks the connection back up. To
remove it completely, delete the three files and folders it added, then
the tables.

## Development

WHMCS is commercial and closed source, with no image to run in CI, so the
tests run the gateway in a **stand-in WHMCS** (`tests/whmcs`): the small
part of WHMCS a payment gateway touches, written from WHMCS's developer
documentation. That is its database through `WHMCS\Database\Capsule`
(Laravel's query builder, as in WHMCS, on MariaDB), `localAPI`
(`GetInvoice`, `GetClientsDetails`), `getGatewayVariables`,
`addInvoicePayment`, `logTransaction`, `logActivity`, `logModuleCall`, the
admin session, and pages that render `monokulo_config()` and
`monokulo_link()` the way WHMCS's do.

The gateway doesn't use `checkCbInvoiceID` or `checkCbTransID`: both end
the request with `die()` when the check fails, and a webhook must answer
with a status Monokulo can act on.

Everything runs in Docker on the host's network (Linux): the stand-in
WHMCS at `http://localhost:8091/` with MariaDB.

```sh
scripts/test up          # build and start it (PHP_VERSION=8.1|8.2|8.3|8.4, default 8.3), install the gateway
scripts/test all         # build the zip, unzip it into the stand-in, run every suite
scripts/test module      # one suite: unit, module or http (extra args go to PHPUnit)
COVERAGE=1 scripts/test all   # and coverage/summary.txt, coverage/html/
scripts/smoke            # against a real Monokulo (MONOKULO_BIN=path or MONOKULO_IMAGE=image; MONOKULO_OPENAPI=its OpenAPI with examples)
scripts/test down
```

- **unit**: the gateway's library (`src/modules/gateways/monokulo`),
  without WHMCS, against a stand-in Monokulo.
- **module**: the module's functions and the callback in-process, against
  the stand-in WHMCS and a stand-in Monokulo.
- **http**: real HTTP to the stand-in's web server, through the files the
  zip installs: webhooks, the settings page, connecting, paying.
- **smoke**: a fresh Monokulo (`docker/monokulo-smoke.toml`, with an
  onion address and a stand-in for its exchange rates): sign up, add a
  watch-only wallet, connect, then a client's Pay with Monero lands on the
  Monokulo checkout, a client on the shop's onion address on the onion
  checkout, the order's dashboard page opens, and the kept entry is used
  and then revalidated. Nothing is paid. `scripts/smoke` runs it twice:
  with Monokulo on its own address, then served under a path through a
  prefix-stripping proxy (`tests/Support/prefix-proxy.php`). It also
  checks the stand-in Monokulo's answers (`tests/Support/Api.php`) against
  the real API and its OpenAPI document (`/api/v1/openapi.json`), and,
  with `MONOKULO_OPENAPI` (written at the pinned commit with
  `cargo xtask api openapi --out FILE`), against its recorded examples.

Webhook tests use Monokulo's published test vectors, vendored in
`tests/vectors` and pinned to a monokulo commit (`tests/vectors/PIN`).
`scripts/vectors check` compares them with the pinned commit;
`scripts/vectors pin <commit>` moves the pin.

### What the stand-in can't tell you

It is not WHMCS. These are checked by hand on a real install before a
release:

- [ ] Unzip into WHMCS 8.13 (PHP 8.1 or 8.3) and WHMCS 9 (PHP 8.3);
      *Monokulo (Monero)* is listed under All Payment Gateways and
      activates.
- [ ] The settings show the address, *Send errors to Monokulo* and the
      connection note with its **Connect** button rendered as a link (the
      note is a `System` field's HTML).
- [ ] Saving `pay.example.com` (no scheme) is refused with the gateway's
      message; saving an https address is kept.
- [ ] Connect: the browser goes to Monokulo, *Back to WHMCS* returns to
      the gateway's settings (`<admin folder>/configgateways.php`) saying
      *WHMCS is connected*, also with a renamed admin folder.
- [ ] The Connect link opened in a private window (not signed in to the
      admin area) is refused.
- [ ] A client's unpaid invoice shows **Pay with Monero**; so does the
      invoice email's payment link; both reach the Monokulo checkout for
      the balance, in the client's currency.
- [ ] Paying on stagenet or testnet: the gateway log shows each step, the
      invoice turns Paid with the Monokulo order id as transaction id,
      the payment confirmation email is sent, and a product's provisioning
      runs.
- [ ] With WHMCS also on a `.onion` address (in Tor Browser): the
      invoice's Pay with Monero stays on it and opens the Monokulo
      checkout on its onion address.
- [ ] A webhook sent again by Monokulo adds nothing.
- [ ] Warnings appear in the Activity Log; with module debug logging on,
      the rest in the Module Log.
- [ ] Deactivating and activating again keeps the connection.

## Release

Bump `VERSION` and `Plugin::VERSION` together (a test checks), then push a
tag `v<version>`. The release workflow unzips the zip into the stand-in
WHMCS, runs the tests and publishes `monokulo-whmcs.zip` and its
`.sha256`. `scripts/build-zip` builds the same zip locally (byte for byte).
