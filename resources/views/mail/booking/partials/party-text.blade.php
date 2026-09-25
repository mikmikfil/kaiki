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
{{ $d->balance !== null ? __('mail.common.deposit_paid') : __('mail.common.paid') }}: {{ $d->paid }}
@endif
@if ($d->balance !== null && $d->balanceOnBoard)
{{ __('mail.common.balance_on_board', ['amount' => $d->balance]) }}
@elseif ($d->balance !== null)
{{ __('mail.common.balance') }}: {{ $d->balance }}
@if ($d->balanceDue !== null)
{{ __('mail.common.until') }}: {{ $d->balanceDue }}
@endif
@endif
