{{--
    «Σάρωση εισιτηρίων» at the top of the menu is a phone's shortcut, not a
    computer's (Mike, 25/9: «όχι στην κορυφή» on desktop).

    It was pinned above every group on 24/9 because it is done forty times a
    morning on the quay, and on a phone that stays true: the phone's menu is
    {@see \App\Filament\App\Navigation\BoxMenu}, a separate drawer this rule
    does not touch. On a computer the sidebar is always open, nobody scans
    tickets at a desk with the webcam, and «Επιβίβαση» under «Σήμερα» is the
    same page — so from `lg`, where the sidebar is fixed open, the pinned item
    is hidden.
--}}
<style>
    @media (min-width: 64rem) {
        .fi-sidebar-nav .fi-sidebar-item:has(> a[href*="boarding?camera=1"]),
        .fi-sidebar-nav .fi-sidebar-item:has(> a[href*="boarding%3Fcamera%3D1"]) { display: none; }
    }
</style>
