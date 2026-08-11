# What this package presents, and what it refuses to

Written for somebody who has to change it, or who has to decide whether a bug is
a bug.

---

## 1. The scope, and why it is this small

**Most of payment operations is not shopper-facing, and choosing wrongly here is
the main way a package like this goes wrong.**

The domain publishes five actions. Four of them — `AuthorizePayment`,
`CapturePayment`, `VoidPayment`, `RefundPayment` — move money, and three of those
four move money that has already been reserved. `RecordProviderCallback` reads
hostile input from a provider. The queries include a reconciliation queue, a
stalled-payment queue and an unmatched-callback queue.

Almost none of that belongs on a page a shopper can reach:

| | Whose it is | Why |
| --- | --- | --- |
| Authorize | **Ours**, once | The shopper is the person deciding to pay. |
| Capture | **Ours only as a consequence** | Taking reserved money is a merchant's decision — usually "has it shipped?". A shopper's click can cause it, per configuration, but never choose an amount. |
| Void, refund | `-filament`, or a host job | These move somebody else's money. A shopper who could refund could refund at will. |
| Provider callbacks | The host's controller | Signature verification, out-of-order delivery, dedupe. Nothing about it is a page. |
| Reconciliation, stalled, unmatched queues | `-filament` | These are work queues for operators, and each row is somebody else's payment. |
| Multi-tender, partial capture, partial refund | `-filament` and the host's checkout | Each is a judgement about a specific order that no shopper-facing button should be able to make. |

What is left is three things, and this package is exactly those three.

### 1.1 Paying

`Components\PayForOrder`. Provider-neutral: no SDK, no provider name anywhere in
`src/`, no gateway-specific markup. The domain resolves an implementation of
`Contracts\PaymentGateway` by **configured class name** at call time; the host
binds the real one and owns the client-side widget.

### 1.2 Seeing what happened

`Components\PaymentReceipt`. A payment's derived state — `PaymentState::fold()`
via `PaymentQuery::byReference()` — and its entry history, for the person who
paid. Scoped so a shopper only ever sees their own. §5.

### 1.3 Managing saved instruments

`Components\SavedInstruments`. Listing and removing. **Never adding**, because
adding an instrument means holding an instrument for as long as it takes to send
it somewhere, and this package holds none. The way to add a card is to pay with
it: `AuthorizePayment` writes the row from the descriptor the *gateway* returns.

### 1.4 What was considered and left out

- **Paying with a saved instrument.** A saved card can be listed and removed but
  not yet chosen at the pay control. Every design for choosing one is either a
  writable property naming a row — which the locking rule forbids — or a second
  method argument re-scoped to the viewer on every request, which is the right
  shape and is not needed for 0.1.0. The domain stores everything required for it;
  this is a decision deferred, not a door closed.
- **A routable page component.** The checkout package ships one because a
  checkout *is* a page. A payment control is a fragment a host composes into a
  page it already owns, so every component here starts at `<h2>` and the host
  keeps its `<h1>`.
- **Polling.** The checkout package polls because its shopper is waiting on money
  arriving from somewhere else. Here the authorization is synchronous: the answer
  is in the response to the click.

---

## 2. The idempotency key: when it is minted, and where it is held

### 2.1 When

**`mount()`.** The moment the payment step is entered.

```php
$this->idempotencyKey = (string) Str::uuid();
```

Held on a `#[Locked] public string` for the rest of the component's life, and
sent on every press of the button.

A key generated inside `pay()` would be a fresh key per click. That is two keys
for one intent, and against `ecommerce_payment_payments.payment_key` — a unique
index — two keys are two payments. It is not a subtlety; it is the entire
mechanism, backwards, and `tests/Feature/IdempotencyTest.php` is mostly about
proving it is not what happens here.

### 2.2 The reload, which is the case minting-at-mount does not cover

A shopper who reloads gets a **new component**, and therefore a new key. Nothing
about `#[Locked]` helps: the property is gone with the page.

So `mount()` also reads the ledger. `PaymentQuery::forOrder()` folded — not a
status column, not a row count — and any payment carrying an authorization or a
capture sets `alreadyPaid`. That component renders **no pay button at all**, and
`pay()` refuses outright.

A `failed` or `voided` payment is not money, so it does not count: a shopper whose
card was refused must be able to try again.

This is also why the pay control is **single-tender**. The domain deliberately has
no unique key on `order_id` so multi-tender stays possible; splitting a total
across two instruments is a decision a host's checkout makes, not one made by
pressing this button twice.

### 2.3 The key goes to the provider too

`AuthorizePayment` passes it into `GatewayInstruction::$idempotencyKey`, so a
crash between the provider approving and the commit is recoverable: the retry
asks the same question and gets the same answer rather than a second reservation.
An adapter that mints its own key per attempt breaks that, and
`PaymentQuery::stalledSince()` is the queue where it shows up.

---

## 3. What the shopper sees, for every answer the domain can give

Told apart by `instanceof`. The domain ships two classes precisely so nobody has
to decode a message, and this package does not.

| Domain answer | Property set | Shopper sees |
| --- | --- | --- |
| First call authorizes | `paid` | "Payment approved", the reference, the state. |
| Same key, same facts | `paid`, `replayed` | The **same** approval plus "we already had this one". No second `PaymentAuthorized`. |
| `PaymentInFlight` | `stillProcessing` | "Still processing your first attempt — you have not been charged twice", and a *Check again* button. |
| `PaymentConflict` | `problem` | "The amount owed changed while you were paying." |
| `UnknownGateway` | `problem` | "This shop cannot take payments yet." |
| Declined (`approved === false`) | `declineCode`, `problem` | The short code, and a fresh key. |
| Instrument-shaped token | `problem` | "Your card details cannot be sent to this page." Key untouched. |
| `alreadyPaid` | `problem` | "This order has already been paid." No button was rendered. |

### 3.1 In-flight is never an error and never an empty success

`stillProcessing` sets **no** `problem`. An error would be a lie — the payment may
well be about to succeed — and an empty success would be a worse one. The page
says the true thing and offers a way to ask again.

The test for it is a real in-flight claim in the schema rather than a stubbed
exception: an entry row already holding `{key}:authorize` with no payment
committed under the key, which is exactly the shape a crash between the provider
approving and the commit leaves behind.

### 3.2 A throw releases the claim, so a refusal does not burn the key

The instrument-shaped-token guard runs **before** the gateway is called and before
anything is written. The key is untouched, so the shopper fixes their payment form
and presses the same button with the same key — which is exactly what they will
do. Tested: refuse, then succeed, and the payment carries the originally minted
key.

### 3.3 Why a conflict does not mint a fresh key

The checkout package drops its key on a conflict and mints another, and that is
right *there*: `IdempotencyConflict` in checkout means the key was spent on a
different payload, so **this** payload was never committed.

Here it is the opposite. `AuthorizePayment` raises `PaymentConflict` only when a
payment **already exists** under this key with different facts — so money may
already have been reserved for a different amount. Minting a fresh key would
authorize a second payment for the new total with nobody having decided to.

So it refuses, says the amount changed, and the shopper starts again from wherever
the host sends them. The same shape of situation, the opposite correct answer, and
the difference is which side of the key the commit happened on.

### 3.4 Why a decline does

A decline is not an exception. It is a `failed` entry of zero, committed, with
`approved: false` — the key is spent on the record that this attempt took nothing.
Replaying it forever would leave a shopper unable to try another card, and a new
key is safe *precisely because* the old one is on record as having moved no money.

### 3.5 The button is a courtesy

`wire:loading.attr="disabled"` covers one browser mid-request. It does nothing for
a shopper on a flaky connection who reloads and presses again, and nothing at all
for a client that is not a browser. Both are tested; only one is the guarantee.

---

## 4. What must never be held, rendered or accepted

### 4.1 No instrument

Three layers, because each alone leaves a door open:

1. **No property.** Every public property on every registered component is
   `#[Locked]`, with **no exceptions list at all** — the checkout package keeps
   one for the email and address a shopper types, and this package keeps none,
   because there is nothing on a payment surface a shopper types that this package
   may receive. A reflection test walks every component and would fail on the
   first property added without the attribute. A second test asserts no property
   is *named* for a card, a CVV, an IBAN or a sort code, on any visibility.
2. **No field.** These components render no `<input>`, `<select>` or `<textarea>`
   whatsoever, asserted across all three at once.
3. **Not in transit.** The token arrives as an **argument to `pay()`**, never as a
   property, so there is nothing for Livewire to hydrate. And a "token" that is a
   run of twelve to nineteen digits once spacing is stripped, or an IBAN, is
   refused before the gateway is called.

That last guard is the one worth explaining. The one place a card number could
reach this package is a host widget wired to post the field instead of the token
it was given — a mistake somebody makes once. It must fail *here*, rather than in
a provider request, a log line, or a stored `provider_token`.

It is a guard, not a validator. What makes an instrument unstorable is the schema
having no column for one; this makes it unsendable.

### 4.2 No signing secret, no callback body, no other shopper's payment

None of the three is reachable from this package. `Gateways::signingSecret()` is
never called here; there is no callback route, no `RecordProviderCallback` and no
`ProviderCallback` model import; and §5 is the scoping.

### 4.3 No amount is a client input

There is **no amount property on any component**, locked or otherwise. A
`#[Locked]` amount would still be one dehydration bug away from being a number a
shopper set.

What the pay control holds is an opaque `reference`, itself `#[Locked]` because a
swapped reference is a swapped amount. The class named in
`payment-operations-livewire.payable` turns it into a `Data\Payable` — order id,
`Money`, gateway — **on the server, on the request that charges**. A total that
changed between render and click is re-read, and a test asserts the newer number
is the one charged.

`Payable` is a type rather than an array so a host cannot return a float, and it
carries no idempotency key so a host cannot mint or ignore one. The key never
leaves the component.

### 4.4 Money

Integer minor units end to end, and no float or `number_format` anywhere in
`src/` — asserted with comments stripped first, because the docblocks quote the
`float $amount` of the interface this fleet replaced.

Formatting is `Money::decimal()`, which is string arithmetic: pad, split,
concatenate. `(int) (47.98 * 100)` is 4797, and 4798 is the figure the tests use
for that reason. A currency with exponent 0 renders `JPY 500` rather than `5.00`.

The currency code is shown rather than a symbol: a symbol table is a per-locale
problem this package would get wrong, and `GBP 19.99` is never ambiguous about
which of the four dollars it means.

No settlement currency or rate is shown. That is a merchant's reconciliation, and
putting a second currency next to the amount a customer was charged invites
exactly the wrong conclusion about what they paid.

---

## 5. Who sees what

`customer_id` on the payment, and the viewer, and nothing else.

**Not a policy.** The domain's `PaymentPolicy` and `PaymentInstrumentPolicy` gate
*staff* by team, through `ReadsWithinTeam`. A shopper is in no team, so asking
would be asking the wrong question — and would answer no for everybody.

The viewer is `auth()->id()`, or a class the deployment names. An id that is not a
whole number resolves to **nobody**, and nobody sees nothing. Not `(int) $ulid`,
which is `0`, and `0` is somebody's row on a database that starts its sequences
there. It fails closed and it is completely silent, which is the safe direction
and the thing to know about when a ULID-keyed host finds every list empty.

Three refusals, all answered identically with a 404:

- a reference that names no payment,
- a payment belonging to somebody else,
- **a payment belonging to nobody, viewed by anybody.**

The last is the one worth stating. A guest payment has `customer_id` null, so
there is no identity to check a viewer against, and a `PAY-…` reference is not a
credential — it is short, it is printed on things, and treating it as one would
make every guest receipt readable by whoever guessed a reference. **A guest sees
the outcome of their payment in the pay component that made it, on that request,
and nowhere else.** A host that wants more than that gives its guests an identity.

All three answers are the same 404 on purpose: telling a stranger that a reference
exists but is not theirs is telling them a reference exists. The instrument list
does the same by scoping in the query rather than checking after it, so somebody
else's reference and an invented one are indistinguishable.

### 5.1 What the pay control shows, and the boundary it does not police

Mounting the pay control is the **host's** decision, and the reference resolver is
where a host decides who may pay for what. This package renders what it renders to
whoever the host mounted it for. That is the right seam — an order's ownership is
a fact about orders — and it is stated here so nobody assumes otherwise.

---

## 6. Removing a saved instrument

`detached_at`, not a delete. A payment made last month points at the instrument it
was made with, and deleting the row would leave a ledger entry whose brand and
last four came from nowhere. Detached instruments stop being listed; nothing
forgets what was already paid.

The provider token never reaches the view. The component maps each model to a
plain array first, so the view is one that was never handed a token rather than
one with a convention about not printing it. A test asserts the token is absent
from the rendered HTML.

---

## 7. Accessibility

- Refusals go in a `role="alert"`, which interrupts. Outcomes and state changes go
  in a `role="status" aria-live="polite"`, which waits its turn. Both are on the
  page from the first render rather than appearing with their text — a live region
  inserted at the same moment as its content is not announced by every screen
  reader.
- Announcements last exactly one render, cleared on hydration. A live region
  announces *changes*; carrying the previous sentence forward would say it again
  at the wrong moment.
- Every control is a real `<button type="button">`. A `<div wire:click>` is
  unreachable by tab, unactivatable by space and announced as nothing; a test greps
  for one.
- Each *Remove* button is named after the card it removes. Four buttons all called
  "Remove" are one button to somebody tabbing through.
- Repeated rows are `wire:key`ed so focus survives a re-render.
- The receipt is a `<dl>` and its history an `<ol>` with `<time datetime>`: pairs
  read as pairs, and a list announces its length and position. A grid of `<div>`s
  reads as one run-on sentence.
- Every component starts at `<h2>`. These are fragments a host composes into a page
  it already owns, and a component that claimed the `<h1>` would give that page two
  outlines or none.
- Status vocabulary is translated rather than passed through. "Authorized" reads to
  a customer as "you have been charged"; they have not been, and the string says
  "Reserved, not yet taken".

---

## 8. Boundaries

No `use` of any commerce namespace but `Liberu\Ecommerce\PaymentOperations`,
asserted by a test. No `App\`. No route registered — routes belong to the
application, and a package that claimed `/payments/{reference}` would have decided
a host's URL structure.

The gateway is never imported, only resolved. This package names no provider and
ships no adapter; `tests/Feature/SecurityTest.php` greps `src/` for seven of them.

---

## 9. Things that will surprise somebody

- **The key is minted at `mount()`, so it exists before anything is owed.** That
  is deliberate: it is the earliest moment at which "this shopper's intent to pay"
  exists as a thing to name.
- **A conflict does not mint a fresh key, and a decline does.** §3.3 and §3.4. The
  two look like the same situation and take opposite answers.
- **A guest gets no receipt page.** §5.
- **`payable()` is a method, not a property**, and it costs a call to the host's
  resolver on every render. That is the price of the amount never being a stored
  number, and it is deliberate.
- **The pay control refuses a second payment for an order that already has one**,
  even though the domain supports multi-tender. §2.2.
- **The instrument list is empty and says nothing** when the viewer is not a whole
  number. §5.
