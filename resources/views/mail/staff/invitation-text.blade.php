{{--
    The plain-text half (NTF-6). Mandatory, and here it is also the version that
    survives a client which mangles the button.
--}}
{{ __('staff.invitation.heading', ['operator' => $operator]) }}

{{ __('staff.invitation.body', ['name' => $invitee->name, 'inviter' => $invitedBy->name, 'operator' => $operator]) }}

{{ __('staff.invitation.action') }}:
{{ $resetUrl }}

{{ __('staff.invitation.expiry') }}

--
{{ $operator }}
