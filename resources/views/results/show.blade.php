@extends('fawaterk::layout')

@php
    /** @var \BiztechEG\Fawaterk\Results\ResultPage $page */
    $isCode = $page->state === 'awaiting_payment' && $page->reference !== null && $page->checkoutUrl === null;
    $text = $isCode ? 'awaiting_code' : $page->state;
    $tone = match ($page->state) {
        'paid', 'under_review' => 'ok',
        'processing', 'unconfirmed', 'awaiting_payment', 'refunded' => 'wait',
        default => 'bad',
    };
    $icon = ['ok' => '✓', 'wait' => '…', 'bad' => '!'][$tone];
    $at = fn (\Carbon\CarbonInterface $date) => ($local = $date->copy()->setTimezone($timezone))->format('Y-m-d H:i').' (UTC'.$local->format('P').')';
@endphp

@if ($page->state === 'processing')
    @section('head')
        <meta http-equiv="refresh" content="15">
    @endsection
@endif

@section('title', __('fawaterk::results.title'))

@section('content')
    <div class="fw-icon fw-{{ $tone }}" aria-hidden="true">{{ $icon }}</div>
    <h1>{{ __('fawaterk::results.states.'.$text.'.heading') }}</h1>
    <p>{{ __('fawaterk::results.states.'.$text.'.body') }}</p>

    <dl>
        <div>
            <dt>{{ __('fawaterk::results.amount') }}</dt>
            <dd>{{ $page->amount }} {{ $page->currency }}</dd>
        </div>
        @if ($page->reference !== null)
            <div>
                <dt>{{ __($isCode ? 'fawaterk::results.code' : 'fawaterk::results.reference') }}</dt>
                <dd @class(['fw-code' => $isCode])>{{ $page->reference }}</dd>
            </div>
        @endif
        @if ($page->referenceExpiresAt !== null)
            <div>
                <dt>{{ __('fawaterk::results.pay_before') }}</dt>
                <dd>{{ $at($page->referenceExpiresAt) }}</dd>
            </div>
        @endif
        @if ($page->paidAt !== null)
            <div>
                <dt>{{ __('fawaterk::results.paid_at') }}</dt>
                <dd>{{ $at($page->paidAt) }}</dd>
            </div>
        @endif
    </dl>

    @if ($page->checkoutUrl !== null || $page->backUrl !== null)
        <div class="fw-actions">
            @if ($page->checkoutUrl !== null)
                <a class="fw-button fw-primary" href="{{ $page->checkoutUrl }}" rel="noopener noreferrer">{{ __('fawaterk::results.continue') }}</a>
            @endif
            @if ($page->backUrl !== null)
                <a class="fw-button fw-secondary" href="{{ $page->backUrl }}" rel="noreferrer">{{ __('fawaterk::results.back') }}</a>
            @endif
        </div>
    @endif
@endsection
