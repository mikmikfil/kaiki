{{--
    The plain-text half (NTF-6). Mandatory, and here it is also the version that
    survives a client which mangles the button. Direction Γ, in words.
--}}
@php($isOwner = $role === \App\Enums\Role::Owner)
{{ __('staff.invitation.heading') }}

{{ $isOwner ? __('staff.invitation.lead_owner') : __('staff.invitation.lead') }}

{{ __('staff.invitation.facts.name') }}: {{ $invitee->name }}
{{ __('staff.invitation.facts.company') }}: {{ $operator }}
{{ __('staff.invitation.facts.email') }}: {{ $invitee->email }}
{{ __('staff.invitation.facts.added_by') }}: {{ $addedBy }}
@if ($role !== null)

{{ __('staff.invitation.role_label') }}: {{ $role->label() }}
{{ $isOwner ? __('staff.invitation.can_owner') : __('staff.invitation.can') }}
@foreach ((array) __("staff.invitation.roles.{$role->value}.can") as $item)
- {{ $item }}
@endforeach
@if (\Illuminate\Support\Facades\Lang::has("staff.invitation.roles.{$role->value}.cannot"))
{{ __("staff.invitation.roles.{$role->value}.cannot") }}
@endif
@endif

{{ __('staff.invitation.action') }}:
{!! $resetUrl !!}

{{ trans_choice('staff.invitation.expiry', $days, ['days' => $days]) }}
@if ($askEmail !== null)
{{ __('staff.invitation.questions', ['email' => $askEmail]) }}
@endif

--
{{ $operator }}
