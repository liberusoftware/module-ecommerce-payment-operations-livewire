<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire\Components;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\PaymentOperations\Data\PaymentData;
use Liberu\Ecommerce\PaymentOperations\Livewire\Concerns\PresentsPayments;
use Liberu\Ecommerce\PaymentOperations\Livewire\PaymentOperationsLivewireServiceProvider;
use Liberu\Ecommerce\PaymentOperations\Queries\PaymentQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * **What happened to one payment, for the person who made it.**
 *
 * Everything on this page is folded from `ecommerce_payment_entries` by
 * `PaymentState::fold()` at the moment it is asked. There is no status column in
 * the domain and there is no cached total on this component, so a capture or a
 * refund recorded by a webhook thirty seconds ago is on the shopper's next
 * render — not at their next login, and never in disagreement with the events
 * that produced it.
 *
 * ## Who may see it
 *
 * `customer_id` on the payment, and the viewer, and nothing else. Not a policy:
 * the domain's `PaymentPolicy` gates *staff* by team, and a shopper is in no
 * team — asking it would be asking the wrong question and would answer no for
 * everybody.
 *
 * Three refusals, all answered identically with a 404:
 *
 * - a reference that names no payment,
 * - a payment belonging to somebody else,
 * - a payment belonging to nobody, viewed by anybody.
 *
 * The last is the one worth stating. **A guest payment has no durable receipt.**
 * `customer_id` is null, so there is no identity to check a viewer against, and
 * a `PAY-…` reference is not a credential — it is short, it is printed on
 * things, and treating it as one would make every guest receipt readable by
 * anybody who guessed a reference. A guest sees the outcome of their payment in
 * the pay component that made it, on that request, and nowhere else. A host that
 * wants more than that gives its guests an identity.
 *
 * All three answers are the same 404 on purpose: telling a stranger that a
 * reference exists but is not theirs is telling them a reference exists.
 *
 * ## What it does not show
 *
 * No provider reference and no gateway name: neither means anything to a shopper
 * and the first is a support handle, not a receipt line. No provider token, ever
 * — `PaymentData` does not carry one, so there is nothing here to leak. No
 * settlement currency or rate: that is a merchant's reconciliation, and putting a
 * second currency next to the amount a customer was charged invites exactly the
 * wrong conclusion about what they paid.
 */
class PaymentReceipt extends Component
{
    use PresentsPayments;

    /**
     * The payment's public reference.
     *
     * Locked, but locking it is not the control — the ownership check is. A
     * shopper who edited this would still only ever be shown a payment whose
     * `customer_id` is theirs.
     */
    #[Locked]
    public string $reference = '';

    private ?PaymentData $loaded = null;

    public function mount(string $reference): void
    {
        $this->reference = $reference;

        // Resolved at mount so a receipt that is not this shopper's 404s before
        // anything about it renders, rather than after a heading has already
        // confirmed that the reference names something.
        $this->payment();
    }

    /**
     * The payment, or a 404 — re-read every request.
     *
     * Not `#[Computed]`: Livewire's computed cache outlives the request in ways
     * that are fine for a product name and wrong for a balance. The whole value
     * of a folded state is that it is current.
     */
    public function payment(): PaymentData
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $payment = app(PaymentQuery::class)->byReference($this->reference);
        $viewer = $this->viewer();

        if ($payment === null || $viewer === null || $payment->customerId !== $viewer) {
            abort(404);
        }

        return $this->loaded = $payment;
    }

    /**
     * The ledger, oldest first, as the shopper's own history of the payment.
     *
     * Entries are shown whatever their origin. A `provider` row is a fact about
     * their money that arrived by webhook rather than by their click, and hiding
     * it would mean the history disagreeing with the totals folded from it.
     *
     * @return list<array{kind: string, amount: string, occurredAt: string, failureCode: ?string}>
     */
    public function history(): array
    {
        $rows = [];

        foreach ($this->payment()->entries as $entry) {
            $rows[] = [
                'kind' => $this->say('kind.'.$entry->kind->value),
                'amount' => $this->money($entry->amount),
                'occurredAt' => $entry->occurredAt,
                'failureCode' => $entry->failureCode,
            ];
        }

        return $rows;
    }

    public function render(): View
    {
        return view(PaymentOperationsLivewireServiceProvider::NAMESPACE.'::livewire.receipt');
    }
}
