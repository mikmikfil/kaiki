{{--
    TEN-7's persistent banner: who is really here, whose account this is, and
    how long is left.

    **Renders nothing when nobody is impersonating**, which is every request an
    operator ever makes — so this is a `@if` around the whole file rather than a
    hook that is registered conditionally: the condition is a session fact and
    changes within a session.

    The three facts are not decoration. For the next hour every row written from
    this browser is recorded as the operator's own work, and the only thing that
    makes that explicable while it happens is this strip.

    `position: sticky` and not `fixed`: fixed would sit over the panel's own
    topbar, and a banner that covers the sign-out button is a banner that makes
    the way out harder to find.
--}}
@php
    use App\Domain\Tenancy\Support\ImpersonationSession;

    $impersonator = ImpersonationSession::isActive() ? ImpersonationSession::impersonator() : null;
@endphp

@if ($impersonator)
    <div class="ka-impersonating" role="status">
        <span class="ka-impersonating-dot" aria-hidden="true"></span>

        <span class="ka-impersonating-text">
            {{ __('tenants.impersonation.banner.viewing', ['name' => auth()->user()?->name]) }}
            <span class="ka-impersonating-who">{{ __('tenants.impersonation.banner.as', ['admin' => $impersonator->name]) }}</span>
        </span>

        <span class="ka-impersonating-left">
            {{ trans_choice('tenants.impersonation.banner.minutes', ImpersonationSession::minutesLeft(), ['count' => ImpersonationSession::minutesLeft()]) }}
        </span>

        {{-- A plain form, not a Livewire action: this has to work on a page
             whose Livewire component has already failed, which is one of the
             reasons somebody signs in as an operator in the first place. --}}
        <form method="POST" action="{{ route('impersonation.stop') }}" class="ka-impersonating-out">
            @csrf
            <button type="submit">{{ __('tenants.impersonation.banner.stop') }}</button>
        </form>
    </div>

    <style>
        .ka-impersonating {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
            padding: .55rem clamp(.75rem, 3vw, 1.5rem);
            background: #7C2D12;
            color: #FFF7ED;
            font-size: .875rem;
            line-height: 1.4;
        }

        .ka-impersonating-dot {
            inline-size: .5rem;
            block-size: .5rem;
            border-radius: 50%;
            background: #FDBA74;
            flex: none;
        }

        .ka-impersonating-text { min-width: 0; }
        .ka-impersonating-who { font-weight: 600; }

        /* Pushed to the end on a wide screen, and simply next in line on a
           phone — where wrapping is better than shrinking the way out. */
        .ka-impersonating-left { margin-inline-start: auto; opacity: .85; font-variant-numeric: tabular-nums; }

        .ka-impersonating-out button {
            background: #FFF7ED;
            color: #7C2D12;
            border: 0;
            border-radius: 6px;
            padding: .3rem .7rem;
            font-size: .8125rem;
            font-weight: 600;
            cursor: pointer;
        }

        .ka-impersonating-out button:hover { background: #FFFFFF; }
        .ka-impersonating-out button:focus-visible { outline: 2px solid #FFF7ED; outline-offset: 2px; }
    </style>
@endif
