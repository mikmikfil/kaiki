{{--
    The platform's announcement, at the top of every operator page (SAA-1).

    Plain text only: `{{ }}` escapes it, and `PlatformAnnouncement` strips any
    markup before it is saved. The close button is a plain form post to
    `DismissAnnouncementController` — no Livewire on every page for a button
    most people press once.

    A scoped `<style>` rather than utility classes (Filament's CSS is
    precompiled). No uppercase anywhere (I18N-2).
--}}
<div class="ka-announcement ka-announcement--{{ $severity }}" role="status" aria-label="{{ __('platform.banner.label') }}">
    <p class="ka-announcement-text">{{ $message }}</p>

    <form method="POST" action="{{ $dismissUrl }}" class="ka-announcement-form">
        @csrf
        <button type="submit" class="ka-announcement-close">{{ __('platform.banner.dismiss') }}</button>
    </form>
</div>

<style>
    .ka-announcement {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
        margin: 0 0 1.25rem;
        padding: .8rem 1rem;
        border-radius: .75rem;
        border: 1px solid rgba(var(--primary-500), .3);
        background: rgba(var(--primary-500), .08);
        color: rgb(var(--gray-950));
    }

    .ka-announcement--warning {
        border-color: rgba(var(--warning-500), .45);
        background: rgba(var(--warning-500), .12);
    }

    .ka-announcement-text { margin: 0; font-size: .9375rem; line-height: 1.45; white-space: pre-line; }

    .ka-announcement-form { margin: 0; flex: none; }

    .ka-announcement-close {
        font-size: .8125rem; font-weight: 600;
        padding: .3rem .7rem; border-radius: .5rem;
        border: 1px solid rgba(var(--gray-950), .12);
        background: #fff; color: rgb(var(--gray-700));
        cursor: pointer;
    }

    .ka-announcement-close:hover { background: rgb(var(--gray-50)); }

    .dark .ka-announcement { color: #fff; }
    .dark .ka-announcement-close { background: rgb(var(--gray-900)); color: rgb(var(--gray-200)); border-color: rgba(255, 255, 255, .15); }
</style>
