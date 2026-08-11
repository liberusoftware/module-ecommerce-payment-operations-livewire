{{-- One payment, as the person who made it sees it.

     Every number here is folded from the ledger on this request. There is no
     status column in the domain and no cached total on the component, so a
     refund a webhook recorded a minute ago is on this page now.

     `<dl>` rather than a table: this is one thing described by several
     properties, not several things with the same columns — and a screen reader
     reads a description list as pairs, which is what these are. --}}
@php($payment = $this->payment())
@php($state = $payment->state)

<div data-payment-receipt>
    <h2>{{ __('module-ecommerce-payment-operations::payments.receipt.heading') }}</h2>

    <div role="alert" data-payment-alert>
        @if ($problem !== '')
            <p data-payment-problem>{{ $problem }}</p>
        @endif
    </div>

    <p role="status" aria-live="polite">
        <span wire:loading data-payment-loading>{{ __('module-ecommerce-payment-operations::payments.loading') }}</span>
        <span data-payment-announcement>{{ $announcement }}</span>
    </p>

    <dl data-payment-summary>
        <dt>{{ __('module-ecommerce-payment-operations::payments.receipt.reference') }}</dt>
        <dd data-payment-reference-value>{{ $payment->reference }}</dd>

        <dt>{{ __('module-ecommerce-payment-operations::payments.receipt.status') }}</dt>
        <dd data-payment-status>{{ __('module-ecommerce-payment-operations::payments.status.'.$state->status()->value) }}</dd>

        <dt>{{ __('module-ecommerce-payment-operations::payments.receipt.amount') }}</dt>
        <dd data-payment-amount>{{ $this->money($payment->amount) }}</dd>

        <dt>{{ __('module-ecommerce-payment-operations::payments.receipt.captured') }}</dt>
        <dd data-payment-captured>{{ $this->money($state->captured()) }}</dd>

        @if ($state->refundedMinor > 0)
            <dt>{{ __('module-ecommerce-payment-operations::payments.receipt.refunded') }}</dt>
            <dd data-payment-refunded>{{ $this->money($state->refunded()) }}</dd>
        @endif

        @if ($payment->instrumentLastFour)
            <dt>{{ __('module-ecommerce-payment-operations::payments.receipt.instrument') }}</dt>
            {{-- A brand and four digits. There is no column behind this that
                 could hold anything more. --}}
            <dd data-payment-instrument>
                {{ __('module-ecommerce-payment-operations::payments.instrument.label', [
                    'brand' => $payment->instrumentBrand ?? '',
                    'last_four' => $payment->instrumentLastFour,
                ]) }}
            </dd>
        @endif

        @if ($payment->createdAt)
            <dt>{{ __('module-ecommerce-payment-operations::payments.receipt.made_on') }}</dt>
            <dd data-payment-made-on>{{ $payment->createdAt }}</dd>
        @endif
    </dl>

    <h3>{{ __('module-ecommerce-payment-operations::payments.receipt.history') }}</h3>

    @if ($this->history() === [])
        <p data-payment-history-empty>{{ __('module-ecommerce-payment-operations::payments.receipt.history_empty') }}</p>
    @else
        <ol data-payment-history>
            {{-- Provider-origin rows included. A capture reported by a webhook is
                 a fact about this shopper's money, and hiding it would leave the
                 history disagreeing with the totals folded from it. --}}
            @foreach ($this->history() as $index => $entry)
                <li wire:key="payment-entry-{{ $index }}" data-payment-entry>
                    <span data-payment-entry-kind>{{ $entry['kind'] }}</span>
                    <span data-payment-entry-amount>{{ $entry['amount'] }}</span>
                    <time datetime="{{ $entry['occurredAt'] }}">{{ $entry['occurredAt'] }}</time>

                    @if ($entry['failureCode'])
                        <span data-payment-entry-failure>
                            {{ __('module-ecommerce-payment-operations::payments.receipt.failure_code', ['code' => $entry['failureCode']]) }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
