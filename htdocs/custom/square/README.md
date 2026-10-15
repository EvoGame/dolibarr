# Square

Dolibarr module for taking payments through [Square](https://squareup.com):

- **Online payment links.** Square is offered on Dolibarr's standard online payment page
  (`public/payment/newpayment.php`), next to Stripe and PayPal if they are enabled. Every link Dolibarr
  already produces works: the link on the invoice card, `__ONLINE_PAYMENT_URL__` in email templates,
  links for orders, memberships and donations. When the customer chooses *Pay with Square*, the module
  creates a Square payment link for the amount and sends the customer to Square's checkout page (card,
  Apple Pay, Google Pay). When they come back, the payment is checked with Square and recorded in Dolibarr
  exactly as a Stripe payment would be: payment on the invoice, bank entry, invoice closed.
- **In-person card payments on a Square Terminal.** A *Take payment on Square Terminal* button on any
  validated, unpaid customer invoice sends the amount to a paired Terminal. The page follows the payment
  live, and records it on the invoice as soon as the card is accepted. The request can be cancelled from
  Dolibarr until the card is read.
- **TakePOS.** The payment screen of TakePOS gets two more buttons:
  - **Square Terminal** sends the amount to the till's Square Terminal. Once the card is accepted, TakePOS
    validates the sale and records the payment itself, exactly as for its own card button (stock, cash
    control, the till's bank account), and the module links that payment to the Square payment.
  - **Payment link** validates the sale as awaiting payment and sends the customer a Square payment link by
    email or SMS, to pay on their phone. The payment is recorded on the sale when it is made.
- **Payment links by email or SMS** from any validated, unpaid customer invoice: *Send Square payment link*
  on the invoice card. Sending again reuses the same link while the amount is unchanged, and each sending is
  recorded in the invoice's events.
- **Nothing paid is lost.** If the customer closes the browser before coming back from Square, or the
  Terminal page is closed before the payment completes, a scheduled job finds the payment on Square and
  records it (for invoices), or lists it as *Needs attention* (for anything else). Payment links still
  unpaid after 7 days (configurable) are deleted on Square.

**Billing → Customer invoices → Payments → Square payments** lists every link and Terminal payment,
those needing attention first.

## Requirements

- Dolibarr 24.0 or later, PHP 7.4 or later with the curl extension.
- Modules *Invoices* (required), *Banks & Cash* (recommended: payments are recorded in a bank account) and
  *Scheduled jobs* (recommended: see above).
- A Square account and an application created in the
  [Square Developer Dashboard](https://developer.squareup.com/apps).
- The company currency must be the currency of the Square location (Square refuses any other).
- For Terminal payments: a Square Terminal, in a country where the Terminal API is available.
- For SMS: an SMS provider set up in **Home → Setup → SMS** (for example the OVH module). Email uses
  Dolibarr's email setup.

## Setup

1. Enable the module (**Home → Setup → Modules**, family *Interfaces with external systems*).
2. **Settings** tab: paste the sandbox and/or production access token (Developer Dashboard → your
   application → Credentials), save, then choose the location. Choose the bank account and, optionally,
   the payment mode used to record payments. Choose the environment: *Sandbox* to test, *Production*
   to take real money.
3. **Terminals** tab: get a pairing code, then on the Terminal sign out, choose *Use a device code* and
   type it. The Terminal appears as *Paired*.
4. Give users the permissions *See Square payment links and Terminal payments*, *Take in-person card
   payments on a Square Terminal* (with *Create/modify payments of customer invoices* on the invoice card, or
   *Use Point Of Sale* in TakePOS) and *Send Square payment links to customers by email or SMS*.
5. TakePOS: on the **Terminals** tab, choose the Square Terminal of each till (or leave the default one). In
   the TakePOS setup of each till, give the Square payment mode (card, unless you chose another one) a bank
   account: TakePOS records the payment there. The Terminals tab shows which tills are ready.

In the sandbox no real Terminal can be paired: type one of the test device ids Square documents
(Terminal API → *Test in the Sandbox*) as the default device on the **Terminals** tab.

To hide Square for one kind of payment only, set `<SOURCE>_FORCE_DISABLE_SQUARE` to 1, e.g.
`MEMBER_FORCE_DISABLE_SQUARE`, as for Stripe and PayPal.

Maintainers: see [docs/maintenance.md](docs/maintenance.md).
