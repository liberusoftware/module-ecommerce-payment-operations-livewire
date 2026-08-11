<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire\Components;

use Illuminate\Contracts\View\View;
use Liberu\Ecommerce\PaymentOperations\Livewire\Concerns\PresentsPayments;
use Liberu\Ecommerce\PaymentOperations\Livewire\PaymentOperationsLivewireServiceProvider;
use Liberu\Ecommerce\PaymentOperations\Models\PaymentInstrument;
use Livewire\Component;

/**
 * **The saved cards on somebody's account: list them, remove one, add none.**
 *
 * ## Adding is not a thing this package can do
 *
 * There is no add control here and there will never be one, because adding an
 * instrument means holding an instrument for as long as it takes to send it
 * somewhere, and this package holds none. A row in
 * `ecommerce_payment_instruments` is written by `AuthorizePayment` from the
 * descriptor the **gateway** returns — a brand, a last four, an expiry and the
 * provider's token — after the provider's own widget has tokenised the thing
 * the shopper typed into the provider's own iframe. The way to add a card is to
 * pay with it.
 *
 * ## What a row renders as
 *
 * A brand, four digits and a month. `PaymentInstrument::$provider_token` is
 * credential-shaped — presented to the provider it moves money — so this
 * component maps each model to a plain array before the view ever sees it.
 * The view is handed data that does not contain the token rather than a model
 * that has it and a convention about not printing it. A test asserts the token
 * is absent from the rendered HTML.
 *
 * ## Removing
 *
 * `detached_at`, not a delete. A payment made last month points at the
 * instrument it was made with, and deleting the row would leave a ledger entry
 * whose brand and last four came from nowhere. Detached instruments stop being
 * listed and stop being offerable; nothing forgets what was already paid.
 *
 * The scope is the query, not a check after it: `where('customer_id', $viewer)`
 * means a reference belonging to somebody else is simply not found, and the
 * answer is the same one an invented reference gets. There is no policy call
 * here for the same reason there is none on the receipt — `PaymentInstrument`'s
 * policy gates staff by team, and a shopper is in no team.
 */
class SavedInstruments extends Component
{
    use PresentsPayments;

    /**
     * The instruments this viewer may still pay with.
     *
     * Plain arrays, deliberately: the token never leaves the model layer.
     *
     * @return list<array{reference: string, brand: ?string, lastFour: ?string, expiry: ?string}>
     */
    public function instruments(): array
    {
        $viewer = $this->viewer();

        if ($viewer === null) {
            return [];
        }

        $rows = [];

        foreach (PaymentInstrument::query()->attached()->where('customer_id', $viewer)->orderByDesc('id')->get() as $instrument) {
            $rows[] = [
                'reference' => (string) $instrument->reference,
                'brand' => $instrument->brand === null ? null : (string) $instrument->brand,
                'lastFour' => $instrument->last_four === null ? null : (string) $instrument->last_four,
                'expiry' => $instrument->expiry_month === null || $instrument->expiry_year === null
                    ? null
                    : sprintf('%02d/%04d', $instrument->expiry_month, $instrument->expiry_year),
            ];
        }

        return $rows;
    }

    /**
     * Take one off the account.
     *
     * The reference is the only thing a browser sends, and it is looked up
     * inside the viewer's own scope. Somebody else's reference and a made-up one
     * get the same answer, because the difference between them is information.
     */
    public function detach(string $reference): void
    {
        $this->problem = '';

        $viewer = $this->viewer();

        $instrument = $viewer === null ? null : PaymentInstrument::query()
            ->attached()
            ->where('customer_id', $viewer)
            ->where('reference', $reference)
            ->first();

        if ($instrument === null) {
            $this->fail($this->say('instrument.unknown'));

            return;
        }

        $instrument->update(['detached_at' => now()]);

        $this->announce($this->say('instrument.detached', [
            'label' => $this->label($instrument->brand, $instrument->last_four),
        ]));
    }

    /**
     * A name a screen reader can distinguish from the next row's.
     *
     * "Remove" four times over is four identical buttons; "Remove Visa ending
     * 4242" is four different ones, and the difference is whether somebody
     * tabbing through knows which card they are about to take off.
     */
    public function label(?string $brand, ?string $lastFour): string
    {
        if ($brand === null && $lastFour === null) {
            return $this->say('instrument.unnamed');
        }

        if ($lastFour === null) {
            return (string) $brand;
        }

        return trim($this->say('instrument.label', [
            'brand' => (string) $brand,
            'last_four' => $lastFour,
        ]));
    }

    public function render(): View
    {
        return view(PaymentOperationsLivewireServiceProvider::NAMESPACE.'::livewire.saved-instruments');
    }
}
