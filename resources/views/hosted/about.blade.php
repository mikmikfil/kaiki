{{--
    «Σχετικά με εμάς» (2026-09-24): the operator's own sections, on a page of
    their own. Same rules as the home page — no `{!! !!}`, no script — because
    it is the same partials.
--}}
@extends('hosted.layout')

@section('title', __('hosted.about.title', ['operator' => $tenant->name]))
@section('description', $metaDescription)

@section('content')
    @include('hosted.partials.blocks', ['blocks' => $blocks])
@endsection
