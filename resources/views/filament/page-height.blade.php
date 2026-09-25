{{--
    The page is exactly as tall as the layout, so the sidebar reaches the bottom.

    Mike, 25/9: scrolled to the foot of the home page, the blue sidebar stopped
    24px short of the bottom and «Kaiki» was cut off at the top. The sidebar is
    `sticky` inside `.fi-layout`, and a sticky box cannot leave its parent: a
    modal Filament mounts after the layout, as a direct child of `<body>`, is
    `inline-block`, and an inline box there opens a line box of its own — 24px
    of document below the layout. At the end of the scroll the layout's bottom
    edge came up into view and took the sidebar with it.

    As a block it is still zero high, and opens no line.
--}}
<style>
    .fi-body > .fi-modal { display: block; }
</style>
