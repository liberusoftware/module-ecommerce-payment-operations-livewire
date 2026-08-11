<?php

namespace Liberu\Ecommerce\PaymentOperations\Livewire\Tests\Fixtures;

use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A host's tokenising widget, standing in for the provider's own.
 *
 * It exists to prove the slot contract: what the slot receives is a reference
 * and an amount in minor units, and what it hands back is a **token**, by
 * calling `$parent.pay($token)`. A real one wraps the provider's iframe, which
 * is where the shopper's card actually goes — never through this package.
 */
class InstrumentWidget extends Component
{
    #[Locked]
    public string $reference = '';

    #[Locked]
    public int $amountMinor = 0;

    public function mount(string $reference, int $amountMinor): void
    {
        $this->reference = $reference;
        $this->amountMinor = $amountMinor;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div data-instrument-widget>
                <button type="button" wire:click="$parent.pay('tok_from_the_widget')">Use this card</button>
            </div>
        BLADE;
    }
}
