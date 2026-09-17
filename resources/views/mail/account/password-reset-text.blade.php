{{-- The plain-text half (NTF-6). --}}
{{ __('auth.reset_mail.heading') }}

{{ __('auth.reset_mail.body', ['name' => $user->name, 'operator' => $operator]) }}

{{ __('auth.reset_mail.action') }}:
{!! $resetUrl !!}

{{ __('auth.reset_mail.expiry', ['minutes' => $minutes]) }}
{{ __('auth.reset_mail.ignore') }}

--
{{ $operator }}
