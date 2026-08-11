# Ecommerce: Payment Operations Livewire

> This optional Livewire 4 presentation package provides the shopper-facing
> surface for exactly one independent domain module. Components coordinate public
> actions and presentation state; they do not own persistence, authorization
> decisions, tenancy, business rules or theme identity. The package has no
> dependency on application `App\` classes.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-4-FB70A9)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-payment-operations-livewire?sort=semver)](https://github.com/liberusoftware/module-ecommerce-payment-operations-livewire/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-payment-operations-livewire/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-payment-operations-livewire/actions/workflows/tests.yml)

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

The domain module this package presents is not published on Packagist, so a
composition adds its repository before requiring either:

```jsonc
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-payment-operations" }
]
```

```bash
composer require liberusoftware/ecommerce-payment-operations-livewire
```

Installing is not enabling. Both this package and the domain module it presents
are enabled by the module registry, which reads:

```dotenv
MODULES_ENABLED=ecommerce-payment-operations,ecommerce-payment-operations-livewire
```

Then tell it how a reference becomes an amount, and which gateway to ask:

```dotenv
PAYMENT_OPERATIONS_LIVEWIRE_PAYABLE="App\Payments\PriceAnOrder"
PAYMENT_OPERATIONS_LIVEWIRE_INSTRUMENT_SLOT=app::card-widget
```

Full wiring, including the `Payable` your resolver returns, is in
[docs/adoption.md](docs/adoption.md).

## Scope: most of payment operations is not shopper-facing

Authorising, capturing, voiding and refunding somebody else's money are operator
and server-side concerns, and they live in `-filament` and in the host's own
jobs. So does anything that reads an unmatched provider callback or works a
reconciliation queue. **None of it is here**, and picking that scope wrongly is
the main way a package like this goes wrong.

What is left for a shopper is three things, and this package is exactly those
three:

| Alias | Props | What it is for |
| --- | --- | --- |
| `module-ecommerce-payment-operations::pay` | `reference` | Authorize — and capture, if the deployment says so — against the configured gateway. Holds the idempotency key. |
| `module-ecommerce-payment-operations::receipt` | `reference` | One payment's derived state and its history, for the person who paid it. |
| `module-ecommerce-payment-operations::instruments` | — | The customer's saved payment methods: list and remove. Never add. |

```blade
<livewire:module-ecommerce-payment-operations::pay :reference="$order->reference" />
```

The reasoning behind that scope, in full, is in
[docs/domain.md](docs/domain.md#1-the-scope-and-why-it-is-this-small).

## The double-submit problem, and here it charges a card

A shopper presses *Pay*, the connection stalls, and they press again. The domain
solved that with a caller-supplied key behind a unique index; this package's
entire contribution is to send **the same key** on the second press.

**The key is minted once, at `mount()`** — when the payment step is entered — and
held on a `#[Locked]` property for the rest of the component's life. A key minted
at click time is a fresh key per click, which is two keys for one intent, which
is two reservations of somebody's money. That is the mechanism run backwards, and
`tests/Feature/IdempotencyTest.php` is mostly about proving it is not what
happens here.

The domain ships **two** exception classes and they are told apart by
`instanceof`, never by reading a message:

| Situation | What the shopper sees |
| --- | --- |
| Same key, same facts | Their **own** approval, plus a line saying we already had it. No second `PaymentAuthorized`. |
| First attempt still working (`PaymentInFlight`, transient) | "We are still processing your first attempt — you have not been charged twice", and a *Check again* button. Never an error, never an empty success. |
| Same key, different facts (`PaymentConflict`, permanent) | "The amount owed changed while you were paying." **No fresh key is minted** — see [docs/domain.md](docs/domain.md#33-why-a-conflict-does-not-mint-a-fresh-key). |
| The gateway declines | A short failure code, and a **fresh** key, because a decline is a committed fact the old key can now only replay. |
| A refusal before the gateway | A sentence naming what to fix. Nothing touched the key, so the corrected retry uses the same one. |
| The ledger already holds money for this order | No *Pay* button at all. This is the reload case. |

The button also disables itself on submit. That is a courtesy for one browser;
the key is the guarantee, and both are tested.

## No instrument. Not a property, not a field, not in transit

The domain has no column that could hold a PAN, a CVV or an IBAN, and this
package will not accept one either:

- **No property.** Every public property on every component is `#[Locked]`, and a
  reflection test walks all three asserting there is no exceptions list at all.
- **No field.** These components render no `<input>`, `<select>` or `<textarea>`
  whatsoever, and a test asserts that across every one of them.
- **Not in transit.** The provider's own widget tokenises inside the provider's
  own iframe and hands back a token, which arrives as an **argument** to `pay()`
  rather than as a property. A "token" that is a run of twelve to nineteen digits
  or an IBAN is **refused before the gateway is called** — the one place a
  misconfigured widget could post a card number is here.

There is no signing secret here, no raw callback body, and no way to see somebody
else's payment.

## No amount is a client input

There is no amount property on any component, locked or otherwise. The pay
control holds an opaque **reference**; the class named in
`payment-operations-livewire.payable` turns it into a `Payable` — order id,
`Money`, gateway — on the server, on the request that charges. A total that
changed between the page rendering and the button being pressed is re-read, and a
test asserts exactly that.

Money is integer minor units end to end. Formatting is string arithmetic, because
`(int) (47.98 * 100)` is 4797.

## No payment provider, anywhere

Grep `src/` for Stripe, PayPal, Braintree, Adyen, Klarna, Square or Mollie and
you will find nothing — a test keeps it that way. The gateway is resolved by the
domain from a **configured class name**, the widget is the host's, and the only
provider-shaped thing this package ever touches is an opaque token it passes
straight through.

## Who sees what

The receipt and the saved-instrument list are scoped to one person: the
`customer_id` the domain stored, against `auth()->id()` or a resolver you name.
Not a policy — the domain's policies gate *staff* by team, and a shopper is in no
team.

A reference that names nothing, a payment belonging to somebody else, and a
**guest payment viewed by anybody** are all answered with the same 404. A guest
sees the outcome of their payment in the component that made it and nowhere else,
because a `PAY-…` reference is printed on things and is not a credential.

## Overriding a view

The package ships functional, unstyled markup; a theme owns the final
presentation.

```bash
php artisan vendor:publish --tag=module-ecommerce-payment-operations-views
php artisan vendor:publish --tag=module-ecommerce-payment-operations-translations
php artisan vendor:publish --tag=module-ecommerce-payment-operations-config
```

Keep the live regions, the `wire:key`s, the real `<button>`s and the named remove
buttons — they are behaviour, not decoration. And do not add an `<input>`.

## Accessibility

Refusals go in a `role="alert"` that interrupts; outcomes and state changes go in
a `role="status" aria-live="polite"` that waits its turn, and both are on the page
from the first render rather than appearing with their text. Every outcome is
announced in words. Every control is a real `<button type="button">`, so the
keyboard works for nothing. Repeated rows are `wire:key`ed. Each *Remove* button
is named after the card it removes, because four buttons all called "Remove" are
one button to somebody tabbing through. Every component starts at `<h2>`, so the
host page keeps its own `<h1>`.

## Development note

`liberusoftware/ecommerce-payment-operations` appears in both `require` and
`require-dev`. It is a runtime dependency, and the shared test bootstrap boots a
sibling module's service provider only when it is dev-required — a runtime
requirement deliberately never boots anything, because installing a module must
not enable it. `composer validate` warns about the duplicate; removing either
entry breaks something. The long version is in
[docs/adoption.md](docs/adoption.md#why-the-domain-package-is-in-both-require-and-require-dev).

## Documentation

- [Domain documentation](docs/domain.md) — the scope and why it is this small,
  how and when the idempotency key is minted, what a shopper sees for replay,
  in-flight, conflict and decline, how instruments and amounts are kept out of
  client control, and what is deliberately absent.
- [Runbook](docs/runbook.md) — what this package looks like when it goes wrong in
  production, and what the operator does about it.
- [Adoption and upgrade guidance](docs/adoption.md) — installing, enabling,
  pricing a reference, wiring a gateway and its widget, authorize versus capture,
  identifying a viewer, and dependency pinning.
- [Changelog](CHANGELOG.md) — release notes.
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-payment-operations-livewire/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-payment-operations-livewire" alt="Contributors to liberusoftware/module-ecommerce-payment-operations-livewire">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-payment-operations-livewire/graphs/contributors).
