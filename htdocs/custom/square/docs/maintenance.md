# Square module: maintenance guide

For maintainers. See [../README.md](../README.md) for what the module does.

## Where the code lives

The module is kept in our Dolibarr fork at `htdocs/custom/square/`, alongside Infomatical, and is tracked the
same way (see `htdocs/custom/infomatical/docs/maintenance.md`, *Where the code lives*):

- `htdocs/custom/.gitignore` re-includes it with `!/square/`.
- `dev/setup/codesniffer/ruleset.xml` checks it like core code: the `htdocs/custom/` exclusion is
  `custom/(?!infomatical/|square/)`.
- `.github/workflows/square-release.yml` packages it, exactly as `infomatical-release.yml` does for Infomatical:
  a pull request merged into `develop` that changes `htdocs/custom/square/` and bumps `$this->version` in
  `modSquare.class.php` publishes `module_square-<version>.zip` under the tag `square-v<version>`.

Deployment, installing from the release package and the *disable and re-enable after changing the descriptor*
rule are the same as for Infomatical.

## Module identity

| Item | Value | Notes |
|---|---|---|
| Module number | `591100` | Range 500000–599999 (modules not on DoliStore). Must not change after deployment. |
| Rights class / module key | `square` | `isModEnabled('square')`, `$user->hasRight('square', 'read' / 'terminal')`, langs `square@square` |
| Permissions | `59110001` read, `59110002` terminal | |
| Minimum Dolibarr | 24.0 | |
| Dependencies | `modFacture` | *Banks & Cash* and *Scheduled jobs* are optional but recommended |
| Square API version | `SquareApi::API_VERSION` | Sent as `Square-Version` on every request |

## How a payment flows

### Online (payment link)

All in `class/actions_square.class.php`, through hooks core provides for external payment modules (context
`newpayment`, used by `public/payment/newpayment.php` and `paymentok.php`):

1. `getValidPayment` adds `square` to the valid methods, once an access token and location are set (and a bank
   account when *Banks & Cash* is on). The invoice card and emails use the same list to decide whether to show
   the online payment link.
2. `doAddButton` prints *Pay with Square*. Submitting it posts `dopayment_square`, so core sets
   `paymentmethod=square` and adds `PM=square` to the `fulltag`.
3. `doPayment` (with `action=dopayment`) creates a quick pay payment link for `newamount`, with core's
   `$urlok` as the redirect URL, stores a row in `llx_square_transaction` (status `pending`), puts the amount
   and currency in the session as Stripe does, and redirects to Square.
4. Back on `paymentok.php`, `isPaymentOK` finds the row (session, else Square's `orderId` parameter, and the
   `fulltag` must match), then checks with Square: every tender of the order, each confirmed with GetPayment
   (`COMPLETED`, same currency, same order). Only if the total equals the amount asked does it **claim** the row
   (`pending` → `processing`), set `TRANSACTIONID` to the Square payment id, and return `ispaymentok`.
   Core then records the payment as for Stripe (`ext_payment_site = 'square'`). `getBankAccountPaymentMethod`
   gives core the bank account.
5. The cron job later finds the core payment by `ext_payment_id` and sets the row to `recorded`.

### In person (Square Terminal)

`terminal.php?facid=` (button added by `addMoreActionsButtons`, context `invoicecard`) creates a Terminal
checkout (tipping off, 5-minute deadline) and a row. The page polls `ajax/terminal.php` every 3 seconds, which
calls `SquareTransaction::refresh()`: once the checkout is `COMPLETED` and its payments check out, the row is
claimed and `recordInvoicePayment()` records the payment and the bank entry in one database transaction.

### The claim, and the cron job

`SquareTransaction::setStatus()` updates the row only if its status is still one of the expected ones, and
reports whether it did. `paymentok.php`, the Terminal page, the "Check on Square" actions and the cron job all
go through it, so whichever comes first records the payment and the others do nothing.

The cron job (`SquareTransaction::cronReconcile()`, every 15 minutes, rows older than 10 minutes):

| Row | Square says | Result |
|---|---|---|
| `pending`, invoice | paid | Recorded on the invoice |
| `pending`, anything else (order, member, donation, free) | paid | `attention`: the buyer never came back, so core never ran the post-payment actions (validate member, etc.), which the module does not duplicate |
| `pending` | paid a different amount | `attention` |
| `pending` Terminal | `CANCELED` | `cancelled` |
| `pending` link, older than `SQUARE_LINK_EXPIRY_DAYS` (default 7) | unpaid | Deleted on Square, checked once more, then `cancelled` |
| `processing` | — | `recorded` once a Dolibarr payment with its `ext_payment_id` exists; `attention` if none after 30 minutes |

A person resolves `attention` rows by recording the payment by hand, then *Mark resolved* on the list.

## Settings (constants)

| Constant | Meaning |
|---|---|
| `SQUARE_LIVE` | 1 production, 0 sandbox |
| `SQUARE_SANDBOX_ACCESS_TOKEN`, `SQUARE_LIVE_ACCESS_TOKEN` | Access tokens. Stored like Stripe's keys (clear in `llx_const`). |
| `SQUARE_SANDBOX_LOCATION_ID`, `SQUARE_LIVE_LOCATION_ID` | Location taking the payments |
| `SQUARE_SANDBOX_DEVICE_ID`, `SQUARE_LIVE_DEVICE_ID` | Terminal offered first (the last one used) |
| `SQUARE_BANK_ACCOUNT_FOR_PAYMENTS` | Bank account of the payments |
| `SQUARE_PAYMENT_MODE_FOR_PAYMENTS` | Payment mode id (`llx_c_paiement`); empty: `CB` |
| `SQUARE_LINK_EXPIRY_DAYS` | Days before an unpaid link is deleted; 0 never |
| `<SOURCE>_FORCE_DISABLE_SQUARE` | Hide Square for one source, e.g. `INVOICE_FORCE_DISABLE_SQUARE` |

## Known limits

- **Currency.** Only payments in the company currency are recorded automatically, as core does for Stripe.
  Square only accepts the location's currency, so the two must match (the setup page warns).
- **Webhooks are not used.** The redirect back to `paymentok.php` and the cron job cover every case without a
  public endpoint and a signature key to manage. If faster catch-up is ever needed, a `payment.updated` webhook
  could call `refresh()` on the matching row.
- **Refunds** are made in the Square Dashboard and recorded by hand in Dolibarr.
- **TakePOS** is not integrated: Terminal payments are taken from the invoice card.
- **Replay of the return URL** (e.g. reload) shows core's generic failure page with the message *This payment
  has already been recorded*: core's `paymentok.php` has no "already paid" success state for external modules.
- **Free-amount links** (`newpayment.php?amount=…&tag=…`) are refused by core with *Bad value for key* when
  `PAYMENT_SECURITY_TOKEN` is set, whatever the payment module: core's check needs a source and a ref.

## Upstream-merge checklist

| Dependency | Check | Where |
|---|---|---|
| Hooks `getValidPayment`, `doAddButton`, `doPayment`, `getBankAccountPaymentMethod` | Still called in context `newpayment`, same parameters; `doPayment` still runs after the `dopayment` block, with `$urlok`, `$FULLTAG`, `$source`, `$ref`, `$currency` as globals | `htdocs/public/payment/newpayment.php`, `htdocs/core/lib/payments.lib.php` |
| Hook `isPaymentOK` | Still called for methods other than paypal/paybox/stripe; core still reads `FinalPaymentAmt`, `currencyCodeType`, `TRANSACTIONID`, `paymentType`, `ipaddress` from the session after it | `htdocs/public/payment/paymentok.php` |
| Duplicate check | `paymentok.php` still refuses a `TRANSACTIONID` already in `llx_paiement.ext_payment_id` | same |
| Hook `addMoreActionsButtons` | Context `invoicecard` | `htdocs/compta/facture/card.php` |
| `Paiement::create()`, `addPaymentToBank()` | Same signatures | `htdocs/compta/paiement/class/paiement.class.php` |
| `getURLContent()` | Still accepts `POSTALREADYFORMATED`, `DELETE`, header strings, timeouts | `htdocs/core/lib/geturl.lib.php` |

On Square's side, read the [API changelog](https://developer.squareup.com/docs/changelog/connect) before raising
`SquareApi::API_VERSION`. Endpoints used: Locations, Checkout (payment links), Orders (retrieve), Payments
(get), Terminal (create, get, cancel checkout) and Devices (create, list device codes).

## Testing

In the sandbox, payment links open Square's sandbox checkout, which accepts Square's test card numbers.
Terminal checkouts go to one of Square's sandbox test device ids, which complete or fail on their own.
`php -l` and PHP CodeSniffer must pass:

```sh
phpcs --standard=dev/setup/codesniffer/ruleset.xml htdocs/custom/square
diff <(grep -oE '^[A-Za-z0-9_]+' htdocs/custom/square/langs/en_US/square.lang | sort) \
     <(grep -oE '^[A-Za-z0-9_]+' htdocs/custom/square/langs/en_GB/square.lang | sort)
```
