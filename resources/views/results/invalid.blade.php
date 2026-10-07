@extends('fawaterk::layout')

@section('title', __('fawaterk::results.title'))

@section('content')
    <div class="fw-icon fw-bad" aria-hidden="true">!</div>
    <h1>{{ __('fawaterk::results.invalid.heading') }}</h1>
    <p>{{ __('fawaterk::results.invalid.body') }}</p>
@endsection
