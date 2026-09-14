{{--
    The two links the header carries, in one file because the header renders
    them twice: as a row on a wide screen, and inside the burger on a phone.

    They were one `<nav>` shared by both for a few minutes on 14 September, with
    the `<details>` set to `display: contents` above the breakpoint so the links
    would step out of it. They rendered — and Chromium kept them out of the
    focus order anyway, because the `<details>` around them was still closed. A
    link that is visible and cannot be tabbed to is worse than the crowded
    header this replaced, so the markup is emitted twice and each copy is
    `display: none` where it does not belong, which takes it out of the
    accessibility tree and the tab order together.
--}}
<a href="{{ route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.search.nav') }}</a>
<a href="{{ route('hosted.contact', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.contact.nav') }}</a>
