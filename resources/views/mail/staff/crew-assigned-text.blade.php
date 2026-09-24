{{--
    «Είστε στο πλήρωμα», the plain-text half (NTF-6).
--}}
{{ __($asCaptain ? 'availability.departure.crew.mail.heading_captain' : 'availability.departure.crew.mail.heading', $facts) }}

{{ __($asCaptain ? 'availability.departure.crew.mail.body_captain' : 'availability.departure.crew.mail.body', $facts) }}

{{ __('availability.departure.crew.mail.action') }}:
{!! $url !!}

--
{{ $facts['operator'] }}
