{{-- Saved payment methods: list, remove, and nowhere to add one.

     Each Remove button is named after the card it removes, because four buttons
     all called "Remove" are one button to somebody tabbing through. --}}
<div data-payment-instruments>
    <h2>{{ __('module-ecommerce-payment-operations::payments.instrument.heading') }}</h2>

    <div role="alert" data-payment-alert>
        @if ($problem !== '')
            <p data-payment-problem>{{ $problem }}</p>
        @endif
    </div>

    <p role="status" aria-live="polite">
        <span wire:loading data-payment-loading>{{ __('module-ecommerce-payment-operations::payments.loading') }}</span>
        <span data-payment-announcement>{{ $announcement }}</span>
    </p>

    @if ($this->instruments() === [])
        <p data-payment-instruments-empty>{{ __('module-ecommerce-payment-operations::payments.instrument.empty') }}</p>
    @else
        <ul data-payment-instrument-list>
            @foreach ($this->instruments() as $instrument)
                <li wire:key="payment-instrument-{{ $instrument['reference'] }}" data-payment-instrument>
                    {{-- A brand and four digits. The provider token never reaches
                         this view: the component hands it plain arrays that do
                         not contain one. --}}
                    <span data-payment-instrument-label>
                        {{ $this->label($instrument['brand'], $instrument['lastFour']) }}
                    </span>

                    @if ($instrument['expiry'])
                        <span data-payment-instrument-expiry>
                            {{ __('module-ecommerce-payment-operations::payments.instrument.expires', ['expiry' => $instrument['expiry']]) }}
                        </span>
                    @endif

                    <button
                        type="button"
                        wire:click="detach('{{ $instrument['reference'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="detach"
                        data-payment-detach
                    >
                        {{ __('module-ecommerce-payment-operations::payments.instrument.remove', [
                            'label' => $this->label($instrument['brand'], $instrument['lastFour']),
                        ]) }}
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Said once, plainly, rather than left for somebody to discover by hunting
         for a control that is not there. --}}
    <p data-payment-how-to-add>{{ __('module-ecommerce-payment-operations::payments.instrument.how_to_add') }}</p>
</div>
