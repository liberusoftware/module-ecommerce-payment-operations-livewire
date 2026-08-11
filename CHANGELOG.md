# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-08-11

First release. The shopper-facing surface of
`liberusoftware/ecommerce-payment-operations`, scoped to the three things a
shopper legitimately touches.

### Added

- **Three components**, under one bounded Livewire namespace,
  `module-ecommerce-payment-operations::`. The aliases are the public interface
  and changing one is a breaking release.
  - `pay` — authorize, and capture when the deployment says so.
  - `receipt` — a payment's derived state and history, for the person who paid.
  - `instruments` — saved payment methods: list and remove.
- Registered with `Livewire::resolveMissingComponent()` as well as
  `Livewire::component()`. Livewire 4's `Finder` returns null for a
  `namespace::name` before consulting the explicit registry, so the second is
  what makes a namespaced alias resolvable at all.
- `Data\Payable`: the host's answer to "what is this reference worth", resolved
  server-side by a configured class. It carries no idempotency key, so a host
  cannot mint or ignore one.
- Configuration for the pricing resolver, authorize-versus-capture, the viewer
  identity and the host's tokenising widget slot. No provider name among them.

### Idempotency

- The key is minted **once, at `mount()`**, held on a `#[Locked]` property, and
  sent on every press. A key minted at click time is two keys for one intent.
- `PaymentInFlight` and `PaymentConflict` are told apart by `instanceof`, never by
  decoding a message. In-flight shows "still processing" and a *Check again* — never
  an error, never an empty success, never a second attempt.
- A conflict does **not** mint a fresh key; a decline does. The two look alike and
  take opposite answers, and `docs/domain.md` §3.3–3.4 says why.
- A refusal before the gateway does not burn the key, so the corrected retry uses
  the same one.
- The submit button disables itself as well. That is the courtesy; the key is the
  guarantee, and both are tested.
- The reload case — a fresh component with a fresh key — is stopped by a ledger
  read at `mount()`, folded rather than counted.
- Capture, when enabled, runs under `{key}:capture` so a second press captures
  nothing twice.

### Security

- **Every public property on every component is `#[Locked]`, with no exceptions
  list**, enforced by reflection over every registered component.
- **No instrument, three ways**: no property (asserted by name and by attribute),
  no `<input>`/`<select>`/`<textarea>` rendered anywhere, and a token that is a
  twelve-to-nineteen-digit run or an IBAN refused before the gateway is called.
  The token arrives as a method argument, never as a property.
- **No amount is a client input.** There is no amount property at all; the host's
  resolver is asked again on the request that charges.
- Provider tokens never reach a view: the instrument list is handed plain arrays.
- No signing secret, no callback body, no route, and no way to reach another
  shopper's payment.
- Receipts and instrument lists are scoped by `customer_id` against the viewer.
  A missing payment, somebody else's, and a guest payment are all one 404.
- A viewer id that is not a whole number resolves to nobody. Not `(int) $ulid`.
- No provider name anywhere in `src/`, no `App\`, and no sibling commerce
  namespace but the one this package presents — each asserted by a test.
- Integer minor units throughout, formatted by string arithmetic. No float and no
  `number_format` in `src/`, checked with comments stripped.

### Accessibility

- A `role="alert"` for refusals and a `role="status" aria-live="polite"` for
  outcomes, both present from the first render; announcements last one render.
- Every control a real `<button type="button">`; no `<div wire:click>`.
- Remove buttons named after the card they remove.
- `wire:key` on every repeated row.
- The receipt as a `<dl>`, its history as an `<ol>` with `<time datetime>`.
- Every component starts at `<h2>`, so a host page keeps its own `<h1>`.
- Domain vocabulary translated: "authorized" is shown as "Reserved, not yet
  taken", because the first reads to a customer as "you have been charged".

### Deliberately not here

Void, refund, partial capture, provider callbacks and every reconciliation queue.
They move somebody else's money or read hostile input, and they belong in
`-filament` and in the host's own jobs. Paying with a saved instrument is deferred
rather than closed off — `docs/domain.md` §1.4.
