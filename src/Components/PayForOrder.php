<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Liberu\Ecommerce\PaymentOperations\Actions\AuthorizePayment;
use Liberu\Ecommerce\PaymentOperations\Actions\CapturePayment;
use Liberu\Ecommerce\PaymentOperations\Data\AuthorizationInput;
use Liberu\Ecommerce\PaymentOperations\Data\MovementInput;
use Liberu\Ecommerce\PaymentOperations\Data\PaymentData;
use Liberu\Ecommerce\PaymentOperations\Exceptions\ExceedsAuthorization;
use Liberu\Ecommerce\PaymentOperations\Exceptions\PaymentConflict;
use Liberu\Ecommerce\PaymentOperations\Exceptions\PaymentInFlight;
use Liberu\Ecommerce\PaymentOperations\Exceptions\UnknownGateway;
use Liberu\Ecommerce\PaymentOperations\Livewire\Concerns\PresentsPayments;
use Liberu\Ecommerce\PaymentOperations\Livewire\Data\Payable;
use Liberu\Ecommerce\PaymentOperations\Livewire\PaymentOperationsLivewireServiceProvider;
use Liberu\Ecommerce\PaymentOperations\Queries\PaymentQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * **The pay button, and everything that has to be true for it to be one click.**
 *
 * ## The idempotency key, and when it is minted
 *
 * A shopper presses Pay, the connection stalls, and they press it again. The
 * domain solved that with a caller-supplied key and a unique index on
 * `payment_key`; what this component owes it is a key that is **the same key on
 * the second press**, and that is entirely a question of *when* it is generated.
 *
 * It is minted **once, in {@see mount()}** — the moment the payment step is
 * entered — and held on a `#[Locked]` property for the rest of the component's
 * life. A key generated inside {@see pay()} would be a fresh key per click,
 * which is two keys for one intent, which is two authorizations against one
 * card. That is not a subtlety; it is the entire mechanism, backwards.
 *
 * What the shopper sees for each answer the domain can give:
 *
 * | Domain answer | Shopper sees |
 * | --- | --- |
 * | First call authorizes | "Payment approved", the reference, and the amount. |
 * | Same key, same facts | The **same** approval, plus a line saying we already had it. No second charge, no second event. |
 * | `PaymentInFlight` (transient) | "Still processing" and a Check again button. **Never** an error, never an empty success, never a second attempt. |
 * | `PaymentConflict` (permanent) | "The amount changed" and a refusal. No fresh key is minted — see below. |
 * | The gateway declines | A short failure code and a **new** key, because a decline is a committed fact and the old key can now only replay it. |
 * | The ledger already holds money for this order | The pay button is not rendered at all. This is the reload case. |
 *
 * Told apart by `instanceof`, never by reading a message. The domain ships two
 * classes for exactly this reason: permanent and transient are opposite
 * instructions to a caller.
 *
 * ### Why a conflict does not mint a fresh key
 *
 * The checkout package drops its key on a conflict and mints another, and that
 * is right *there*: a conflict means nothing was ever committed under it. Here
 * it means the opposite. `AuthorizePayment` raises `PaymentConflict` only when a
 * payment already exists under this key **with different facts** — so money may
 * already have been reserved for a different amount. Minting a fresh key would
 * authorize a second payment for the new total with nobody having decided to.
 * So it refuses, says the amount changed, and the shopper starts again.
 *
 * ### Why a decline does
 *
 * A decline is not an exception. It is a `failed` entry, committed, approved
 * false — the key is spent on the record that this attempt took nothing.
 * Replaying it forever would leave a shopper unable to try another card, and a
 * new key is safe precisely because the old one definitively moved no money.
 *
 * ### The button is disabled too, and that is the courtesy
 *
 * `wire:loading.attr="disabled"` covers one browser mid-request. It does nothing
 * for a shopper on a flaky connection who reloads and presses again, and nothing
 * at all for a client that is not a browser. The key is the guarantee. Both are
 * tested.
 *
 * ## No amount, no instrument
 *
 * There is **no amount property on this class**, locked or otherwise. The
 * component holds a reference; the class named in
 * `payment-operations-livewire.payable` turns that into a {@see Payable} on the
 * server, on the request that charges. A `#[Locked]` amount would still be one
 * dehydration bug away from being a number a shopper set.
 *
 * There is no instrument property either, and no instrument field. The
 * provider's own widget tokenises, and the token arrives as an **argument to
 * {@see pay()}** rather than as a property — so there is nothing on this
 * component a browser could put a card number in, and a token that looks like
 * one is refused before the gateway is called.
 */
class PayForOrder extends Component
{
    use PresentsPayments;

    /**
     * The host's handle on what is being paid for — an order number, a checkout
     * reference, whatever it issues.
     *
     * Locked, because it is the input the amount is derived from. A browser that
     * could swap it could pay a cheaper order's total against this one.
     */
    #[Locked]
    public string $reference = '';

    /**
     * The key this payment will be made under — minted once, on mount, and
     * unchanged for every retry after it.
     *
     * Locked twice over: a browser that could set it could pay the same order
     * under two keys, which is the duplicate charge this component exists to
     * prevent.
     */
    #[Locked]
    public string $idempotencyKey = '';

    /** The domain's public reference for the payment, once there is one. */
    #[Locked]
    public string $paymentReference = '';

    /** True once this component has authorized. */
    #[Locked]
    public bool $paid = false;

    /** True when the domain replayed a stored payment rather than making one. */
    #[Locked]
    public bool $replayed = false;

    /** True when the first attempt is still working and this one must wait. */
    #[Locked]
    public bool $stillProcessing = false;

    /**
     * True when the ledger already held money for this order at mount.
     *
     * The reload case. A fresh component holds a fresh key, so calling the
     * domain would authorize a second payment; this is the read that stops it,
     * and it is a read of the ledger rather than of anything a browser sent.
     */
    #[Locked]
    public bool $alreadyPaid = false;

    /**
     * The gateway's short refusal code, never its prose.
     *
     * Free text from a provider next to a shopper's page is where an email
     * address ends up — a finding wave 4 made and wave 5 acted on twice. The
     * domain stores a code for the same reason.
     */
    #[Locked]
    public string $declineCode = '';

    /** True when the deployment has named no way of pricing a reference. */
    #[Locked]
    public bool $unconfigured = false;

    /** Resolved for the length of one request, and never serialised. */
    private ?Payable $resolved = null;

    private ?PaymentData $loaded = null;

    public function mount(string $reference): void
    {
        $this->reference = $reference;

        $resolver = config('payment-operations-livewire.payable');

        if (! is_string($resolver) || $resolver === '') {
            // A deployment that has not said how a reference becomes an amount
            // cannot take a payment. Said as something the shopper can act on
            // rather than as a stack trace, because it is not their fault.
            $this->unconfigured = true;
            $this->fail($this->say('pay.unconfigured'));

            return;
        }

        $payable = $this->payable();

        // A reference that prices to nothing is answered exactly as one that was
        // never issued. The difference between the two is information about
        // somebody else's order.
        if ($payable === null) {
            abort(404);
        }

        // **The one line this component exists for.** Minted on arrival, not on
        // click. Every press of the button below carries this string.
        $this->idempotencyKey = (string) Str::uuid();

        $this->readLedger($payable);
    }

    /**
     * What is being paid, priced on the server, on this request.
     *
     * A method rather than a property, so there is nothing to dehydrate and
     * nothing to tamper with, and so a total that changed between mount and
     * click is the total the gateway is asked for.
     */
    public function payable(): ?Payable
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolver = config('payment-operations-livewire.payable');

        if (! is_string($resolver) || $resolver === '') {
            return null;
        }

        $payable = app($resolver)($this->reference);

        return $this->resolved = $payable instanceof Payable ? $payable : null;
    }

    /** The payment this component made or found, folded from the ledger. */
    public function payment(): ?PaymentData
    {
        if ($this->paymentReference === '') {
            return null;
        }

        return $this->loaded ??= app(PaymentQuery::class)->byReference($this->paymentReference);
    }

    /**
     * Reserve the money, and take it if this deployment says so.
     *
     * `$instrumentToken` is the provider's stand-in for an instrument, produced
     * by the provider's own widget in the browser. It is an **argument, not a
     * property**, so there is no field on this component and nothing for
     * Livewire to hydrate. It is passed to the gateway and never stored: what
     * ends up in `ecommerce_payment_instruments` is whatever descriptor the
     * gateway returns — a brand, a last four, an expiry — and the domain has no
     * column that could hold anything more.
     *
     * Every exit from this method is one of the rows in the class docblock.
     */
    public function pay(?string $instrumentToken = null): void
    {
        $this->problem = '';
        $this->stillProcessing = false;
        $this->declineCode = '';

        if ($this->unconfigured) {
            $this->fail($this->say('pay.unconfigured'));

            return;
        }

        // The reload case, and the only hard stop here. This component's key is
        // not the key that payment was made under, so the domain would treat
        // this as a new payment and reserve the money twice.
        if ($this->alreadyPaid) {
            $this->fail($this->say('pay.already'));

            return;
        }

        $payable = $this->payable();

        if ($payable === null) {
            $this->fail($this->say('pay.closed'));

            return;
        }

        $token = $this->tokenFrom($instrumentToken);

        // A refusal, before the gateway is called and before anything is
        // written. Nothing here has touched the idempotency key, so the shopper
        // fixes their payment form and presses the same button with the same
        // key — which is exactly what they will do.
        if ($token !== null && $this->looksLikeAnInstrument($token)) {
            $this->fail($this->say('pay.instrument_refused'));

            return;
        }

        try {
            $result = app(AuthorizePayment::class)->handle(new AuthorizationInput(
                orderId: $payable->orderId,
                paymentKey: $this->idempotencyKey,
                gateway: $payable->gateway,
                // Server-side, every time. There is no property this could have
                // come from.
                amount: $payable->amount,
                customerId: $payable->customerId,
                teamId: $payable->teamId,
                checkoutReference: $payable->checkoutReference,
                instrumentToken: $token,
            ));
        } catch (PaymentInFlight) {
            // Transient. The first attempt has claimed the key and has not come
            // back. Saying "still processing" is the only honest answer: an error
            // would be a lie, and a success would be a worse one.
            $this->stillProcessing = true;
            $this->announce($this->say('pay.in_flight'));

            return;
        } catch (PaymentConflict) {
            // Permanent. A payment exists under this key with different facts,
            // so the amount moved under the shopper. No fresh key — see the
            // class docblock.
            $this->fail($this->say('pay.conflict'));

            return;
        } catch (UnknownGateway) {
            $this->fail($this->say('pay.unconfigured'));

            return;
        }

        $this->paymentReference = $result->payment->reference;
        $this->replayed = ! $result->recorded;
        $this->loaded = $result->payment;

        if (! $result->approved) {
            $this->declineCode = (string) ($result->entry?->failureCode ?? '');

            // Spent on a decline, so a new key for the next card. Safe precisely
            // because the old one is on record as having moved nothing.
            $this->idempotencyKey = (string) Str::uuid();
            $this->paymentReference = '';
            $this->loaded = null;

            $this->fail($this->say('pay.declined'));

            return;
        }

        $this->paid = true;

        if ((bool) config('payment-operations-livewire.capture', false)) {
            $this->capture();
        }

        $this->announce($this->say($this->replayed ? 'pay.replayed' : 'pay.confirmed'));

        // Identifier only, and only for a payment that exists. A host clearing a
        // basket or sending a receipt keys on this; anything richer is
        // `PaymentAuthorized`, which the domain dispatches and which a replay
        // deliberately does not.
        $this->dispatch(
            PaymentOperationsLivewireServiceProvider::NAMESPACE.'.paid',
            reference: $this->paymentReference,
        );
    }

    public function render(): View
    {
        return view(PaymentOperationsLivewireServiceProvider::NAMESPACE.'::livewire.pay');
    }

    /**
     * Take the whole authorization, under a key derived from this component's.
     *
     * Derived rather than fresh, so the impatient second click captures nothing
     * a second time: the entry key is `{paymentKey}:capture` on every retry, and
     * `ecommerce_payment_entries.entry_key` is unique.
     *
     * No amount is passed, so the domain captures what it folded as capturable
     * under its own lock. Computing it here would mean computing it from a read
     * that is already stale.
     */
    private function capture(): void
    {
        try {
            app(CapturePayment::class)->handle(new MovementInput(
                paymentReference: $this->paymentReference,
                entryKey: $this->idempotencyKey.':capture',
            ));
        } catch (PaymentInFlight) {
            // The authorization stands and the capture is somebody else's job
            // now. Not an error to the shopper: their money is reserved.
            $this->stillProcessing = true;
        } catch (PaymentConflict|ExceedsAuthorization) {
            // Also not an error to the shopper — they have paid. This is a
            // reconciliation problem, and `docs/runbook.md` says where it shows
            // up.
            $this->announce($this->say('pay.capture_deferred'));
        }

        $this->loaded = null;
    }

    /** An empty token is no token: a widget that has not produced one yet. */
    private function tokenFrom(?string $token): ?string
    {
        $token = trim((string) $token);

        return $token === '' ? null : $token;
    }

    /**
     * Whether what arrived is an instrument rather than a stand-in for one.
     *
     * The one place a card number could reach this package is a host widget
     * wired to post the field instead of the token it was given. That is a
     * mistake somebody makes once, and it must fail here rather than at the
     * gateway, in a log line, or in a stored `provider_token`.
     *
     * Two shapes, both cheap: a run of twelve to nineteen digits once spacing is
     * stripped, which is every card number there is, and an IBAN. A provider's
     * token is opaque and prefixed and matches neither. This is a guard, not a
     * validator — it is the schema having no column that makes an instrument
     * unstorable, and this that makes it unsendable.
     */
    private function looksLikeAnInstrument(string $token): bool
    {
        $bare = (string) preg_replace('/[\s-]/', '', $token);

        return preg_match('/\d{12,19}/', $bare) === 1
            || preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $bare) === 1;
    }

    /**
     * Whether this order already has money against it.
     *
     * Read once, at mount, from the fold rather than from a status column. Any
     * payment carrying an authorization or a capture counts — a `failed` or
     * `voided` one does not, because neither is money.
     *
     * This component is deliberately **single-tender**: it refuses rather than
     * adding a second payment to an order that has one. The domain supports
     * multi-tender and has no unique key on `order_id` precisely so it stays
     * possible, but choosing to split a total across two instruments is a
     * decision made by the host's checkout, not by pressing this button twice.
     */
    private function readLedger(Payable $payable): void
    {
        foreach (app(PaymentQuery::class)->forOrder($payable->orderId) as $payment) {
            if ($payment->state->authorizedMinor > 0 || $payment->state->capturedMinor > 0) {
                $this->alreadyPaid = true;
                $this->paymentReference = $payment->reference;

                return;
            }
        }
    }
}
