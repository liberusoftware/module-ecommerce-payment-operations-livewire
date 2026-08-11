# Runbook

What this package looks like when something goes wrong, and what to do about it.

Every symptom here is one somebody has actually reported about a payment page.
The first question in each case is the same: **has money moved?** The domain's
ledger is the answer, and it is append-only, so nothing in this runbook involves
editing a row.

---

## "A customer says they were charged twice"

Almost certainly they were not, and the ledger can prove it in one query.

```php
app(PaymentQuery::class)->forOrder($orderId)
    ->map(fn ($p) => [$p->reference, $p->state->status()->value, $p->state->authorizedMinor, $p->state->capturedMinor]);
```

**One payment, one authorization.** The mechanism worked. What they saw twice was
either the *replayed* confirmation — which says so in words — or a card issuer's
pending-authorization notification alongside the capture.

**Two payments, both with money.** Something bypassed the pay control's
single-tender guard. Check `payment_key` on both: two different keys means two
components, which means two page loads. The guard is a ledger read at `mount()`,
so the window is two tabs opened before either paid. Void the later one through
the Filament panel — never delete a row.

**Two payments, one `failed`.** Expected. A decline mints a fresh key precisely so
the shopper can try another card, and a `failed` entry of zero moved nothing.

---

## "The page says *still processing* and never moves on"

`stillProcessing` means `AuthorizePayment` raised `PaymentInFlight`: something
holds the claim on `{key}:authorize` and no payment is committed under the key.

```php
app(PaymentQuery::class)->stalledSince(now()->subMinutes(15));
```

- **The claim clears and the shopper's *Check again* succeeds.** Normal. A slow
  provider call, and this is the mechanism working.
- **It never clears.** A worker died between the provider approving and the commit.
  The shopper's retry with the same key is what fixes it, because the provider
  holds the same key and answers the same question with the same answer. If the
  shopper has gone, replay it server-side with the same `payment_key`.
- **A payment exists but the entry does not.** That is not this shape and should
  be impossible: they are written in one transaction.

**Do not** tell the shopper to pay again on a new page. That mints a new key and
is the one action that can produce a second charge.

---

## "A shopper is stuck on 'the amount owed changed'"

`PaymentConflict`. A payment exists under this component's key with different
facts, so the total moved between the key being minted and the button being
pressed.

The component deliberately does **not** mint a fresh key here — see
`docs/domain.md` §3.3. Find what already exists:

```php
Payment::query()->where('payment_key', $key)->first();
```

If that payment is the shopper's own and carries money, they have paid; send them
to their receipt. If it is somebody else's, the reference resolver is handing two
orders the same identity, which is a host bug and the more serious finding.

Sending them back through the host's own flow gives them a fresh component and a
fresh key, which is the correct recovery and the only one.

---

## "Every payment is declining"

A decline is a fact, not an outage, so nothing here throws and nothing alerts.

```php
PaymentEntry::query()->where('kind', 'failed')
    ->where('created_at', '>', now()->subHour())
    ->groupBy('failure_code')->selectRaw('failure_code, count(*) as n')->get();
```

One code dominating an hour is a gateway or account problem, not a shopper
problem. The code is the provider's short one — this package never renders the
provider's prose, deliberately, because free text next to a shopper's page is
where somebody's email address ends up.

---

## "The page says this shop cannot take payments yet"

Two causes, both configuration:

1. `payment-operations-livewire.payable` is unset. The component sets
   `unconfigured` at mount and renders no button.
2. `Gateways::for()` threw `UnknownGateway` — the `gateway` your resolver named
   is not a key in `payment-operations.gateways`, or the class it names is not a
   `PaymentGateway`.

Both are said to the shopper as something they can act on rather than as a stack
trace, because neither is their fault. The distinction is in your log.

---

## "Everybody is getting a 404 on the pay page"

The reference resolver is returning null. It is also the place that decides who
may pay for what, so check it before assuming a lookup bug: an order that has just
been marked paid, or scoped to a customer who is not signed in, is a correct null.

---

## "The receipt page 404s for a customer who definitely paid"

Three causes, in order of likelihood:

1. **`customer_id` is null on the payment.** The reference resolver returned a
   `Payable` with no `customerId`, so the payment is a guest payment and has no
   durable receipt by design. Fix the resolver.
2. **The viewer is not a whole number.** A ULID-keyed host resolves to nobody, and
   nobody sees nothing. Configure
   `payment-operations-livewire.viewer` to return the integer the domain stored.
3. **It is genuinely somebody else's.** The 404 is correct and deliberate — a
   distinct "not yours" message would tell a stranger the reference exists.

---

## "A saved card list is empty"

Same cause 2 as above, or every row is detached. `detached_at` is set rather than
deleted, so nothing is lost:

```php
PaymentInstrument::query()->where('customer_id', $id)->get(['reference', 'brand', 'last_four', 'detached_at']);
```

Re-attaching is not a supported operation and there is no control for it. The way
to add a card is to pay with it.

---

## "A shopper says the payment form rejected their card number"

They pasted it into the wrong box, or your widget is posting the field instead of
the token it was given. The pay control refuses a token that is twelve to nineteen
digits or an IBAN **before** calling the gateway and before writing anything, so
the number is in no row, no log line and no provider request.

Check the slot component. A widget wired that way fails on its first real use, and
that is the design.

---

## "The page renders but nothing is announced"

Somebody published the views and dropped the live regions.

```bash
php artisan vendor:publish --tag=module-ecommerce-payment-operations-views --force
```

`tests/Feature/AccessibilityTest.php` covers this package's own views and stops
covering yours the moment you publish them. What must survive: the
`role="alert"` block, the `role="status" aria-live="polite"` block, the
`wire:key`s, real `<button type="button">`s, and remove buttons named after the
card they remove.

---

## Health checks worth having

| Check | Why |
| --- | --- |
| `PaymentQuery::stalledSince(now()->subMinutes(30))` non-empty | Payments raised and never authorized. The in-flight shape, aged. |
| `PaymentQuery::needingReconciliation()` non-empty | A provider row says something arithmetically impossible. |
| `failed` entries per hour, by `failure_code` | One code dominating is a gateway problem. |
| Orders with two payments carrying money | The single-tender guard was raced, or a host's checkout is splitting tenders. |

---

## What this package will never be the cause of

- **A second charge from a second click.** The key is minted at `mount()` and the
  unique index does the rest.
- **A stored card number.** There is no field, no property, no column, and a guard
  before the gateway.
- **A shopper seeing somebody else's payment.** Scoped by `customer_id`, answered
  with a 404, and guest payments have no receipt page at all.
- **An amount a browser chose.** There is no amount property, and the host's
  resolver is asked again on the request that charges.
- **A leaked signing secret.** This package never reads one.
