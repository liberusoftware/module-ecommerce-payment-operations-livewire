{{-- The pay control.

     Two live regions carry everything a shopper who is not looking at the
     screen needs: a `role="alert"` for a refusal, which interrupts, and a
     `role="status"` for an outcome, which waits its turn. Both are on the page
     from the first render rather than appearing with their text — a live region
     inserted at the same moment as its content is not announced by every screen
     reader.

     There is no card field here and there is no amount field here. The amount
     comes from the server on the request that renders this; the instrument goes
     to the provider's own widget and comes back as a token. --}}
@php($payable = $this->payable())
@php($payment = $this->payment())

<div data-payment-control>
    <h2>{{ __('module-ecommerce-payment-operations::payments.pay.heading') }}</h2>

    <div role="alert" data-payment-alert>
        @if ($problem !== '')
            <p data-payment-problem>{{ $problem }}</p>

            @if ($declineCode !== '')
                {{-- The provider's short code, never its prose. Useful to
                     support, and it cannot carry anybody's data. --}}
                <p data-payment-decline-code>
                    {{ __('module-ecommerce-payment-operations::payments.pay.decline_code', ['code' => $declineCode]) }}
                </p>
            @endif
        @endif
    </div>

    <p role="status" aria-live="polite">
        <span wire:loading data-payment-loading>{{ __('module-ecommerce-payment-operations::payments.loading') }}</span>
        <span data-payment-announcement>{{ $announcement }}</span>
    </p>

    @if ($unconfigured)
        <p data-payment-unconfigured>{{ __('module-ecommerce-payment-operations::payments.pay.unconfigured') }}</p>
    @elseif ($alreadyPaid)
        {{-- The reload case. No button at all: this component holds a key the
             earlier payment was not made under, so pressing anything here could
             only ever reserve the money a second time. --}}
        <p data-payment-already>{{ __('module-ecommerce-payment-operations::payments.pay.already_paid') }}</p>

        @if ($paymentReference !== '')
            <p data-payment-reference>
                {{ __('module-ecommerce-payment-operations::payments.pay.reference') }}:
                <span data-payment-reference-value>{{ $paymentReference }}</span>
            </p>
        @endif
    @elseif ($paid)
        <p data-payment-confirmed>
            {{ __('module-ecommerce-payment-operations::payments.pay.'.($replayed ? 'replayed' : 'confirmed')) }}
        </p>

        <p data-payment-reference>
            {{ __('module-ecommerce-payment-operations::payments.pay.reference') }}:
            <span data-payment-reference-value>{{ $paymentReference }}</span>
        </p>

        @if ($payment)
            <p data-payment-status>
                {{ __('module-ecommerce-payment-operations::payments.receipt.status') }}:
                {{ __('module-ecommerce-payment-operations::payments.status.'.$payment->state->status()->value) }}
            </p>
        @endif
    @elseif ($payable)
        <p data-payment-due>
            {{ __('module-ecommerce-payment-operations::payments.pay.due', ['amount' => $this->money($payable->amount)]) }}
        </p>

        @if ($stillProcessing)
            {{-- Not an error, and not an empty success. The first attempt is
                 still working, and saying so is the only honest answer. --}}
            <p data-payment-in-flight>{{ __('module-ecommerce-payment-operations::payments.pay.in_flight') }}</p>

            <button
                type="button"
                wire:click="pay"
                wire:loading.attr="disabled"
                wire:target="pay"
                data-payment-retry
            >
                {{ __('module-ecommerce-payment-operations::payments.pay.in_flight_retry') }}
            </button>
        @endif

        {{-- The provider's own widget, if this deployment wired one. It receives
             the reference and what is owed, and it calls `$parent.pay($token)`
             when it has tokenised an instrument. This package never sees the
             instrument, and refuses the token if it turns out to be one. --}}
        @if ($component = $this->slot('instrument'))
            @livewire($component, ['reference' => $reference, 'amountMinor' => $payable->amount->minor], key('payment-instrument-slot'))
        @endif

        {{-- The courtesy, not the guarantee: `wire:loading.attr` covers one
             browser mid-request and does nothing for a reload. The idempotency
             key minted at mount is what makes the second press safe. --}}
        <button
            type="button"
            wire:click="pay"
            wire:loading.attr="disabled"
            wire:target="pay"
            data-payment-pay
        >
            <span wire:loading.remove wire:target="pay">
                {{ __('module-ecommerce-payment-operations::payments.pay.button', ['amount' => $this->money($payable->amount)]) }}
            </span>
            <span wire:loading wire:target="pay">
                {{ __('module-ecommerce-payment-operations::payments.pay.paying') }}
            </span>
        </button>
    @else
        <p data-payment-closed>{{ __('module-ecommerce-payment-operations::payments.pay.closed') }}</p>
    @endif
</div>
