{{ $template === \App\Enums\NotificationTemplate::QuoteSent ? __('mail.common.quote_items') : __('mail.common.party') }}
@foreach ($d->party as $line)
{{ $line['label'] }}@if ($line['amount'] !== ''): {{ $line['amount'] }}@endif

@endforeach
@if ($d->discount !== null)
{{ __('mail.common.discount') }}@if ($d->discountCode !== null) ({{ $d->discountCode }})@endif: -{{ $d->discount }}
@endif
@if ($d->total !== null)
{{ __('mail.common.total') }}: {{ $d->total }}
@endif
@if ($d->paid !== null)
{{ __('mail.common.paid') }}: {{ $d->paid }}
@endif
@if ($d->balance !== null)
{{ $d->balanceDue !== null ? __('mail.common.balance_due', ['date' => $d->balanceDue]) : __('mail.common.balance') }}: {{ $d->balance }}
@endif
