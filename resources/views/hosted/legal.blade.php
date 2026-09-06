{{--
    The operator's legal, privacy and cancellation text (HOS-9).

    One page with three anchors rather than three pages: an operator with a
    dozen trips does not want three URLs to keep current, and the footer links
    to the anchors so a visitor still lands where they meant to.
--}}
@extends('hosted.layout')

@section('title', __('hosted.legal.title') . ' — ' . $tenant->name)
@section('description', __('hosted.legal.title') . ' — ' . $tenant->name)

@section('content')
    <h1>{{ __('hosted.legal.title') }}</h1>

    <h2 id="operator">{{ __('hosted.footer.operator') }}</h2>
    <p><strong>{{ $tenant->legal_name ?? $tenant->name }}</strong></p>
    @if ($tenant->address_line1)
        <p>{{ $tenant->address_line1 }}@if ($tenant->address_line2), {{ $tenant->address_line2 }}@endif</p>
    @endif
    @if ($tenant->city || $tenant->postcode)
        <p>{{ trim($tenant->postcode . ' ' . $tenant->city) }}</p>
    @endif
    @if ($tenant->vat_number)
        <p>{{ __('hosted.footer.vat_number') }}: {{ $tenant->vat_number }}</p>
    @endif
    @if ($tenant->tax_office)
        <p>{{ __('hosted.footer.tax_office') }}: {{ $tenant->tax_office }}</p>
    @endif
    @if ($tenant->gemi_number)
        <p>{{ __('hosted.legal.gemi') }}: {{ $tenant->gemi_number }}</p>
    @endif

    <h2 id="terms">{{ __('hosted.footer.terms') }}</h2>
    <p>{{ __('hosted.legal.terms_body', ['operator' => $tenant->legal_name ?? $tenant->name]) }}</p>

    <h2 id="cancellation">{{ __('hosted.footer.cancellation') }}</h2>
    {{-- The policy text belongs to the product, because a fleet can run several.
         Naming that here is more honest than printing one policy as if it were
         the operator's only one. --}}
    <p>{{ __('hosted.legal.cancellation_body') }}</p>

    <h2 id="privacy">{{ __('hosted.footer.privacy') }}</h2>
    <p>{{ __('hosted.legal.privacy_body', ['email' => $tenant->email]) }}</p>
@endsection
