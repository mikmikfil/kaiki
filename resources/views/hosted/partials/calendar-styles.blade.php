{{--
    The departures calendar's own rules (2026-09-25), pushed into the page's
    head by `hosted.calendar` only — the other hosted pages never carry them.

    Built on the layout's tokens (`--kaiki-primary`, `--kaiki-accent`, `--rule`,
    `--deep`, `--mist`, the `--t-*` type steps), so it wears the operator's own
    colours. The status colours are fixed, as in the mockup: green for room,
    amber for little, grey for none, red for the weather — and every one of
    them is also a word, so colour is never the only signal.

    Phone first. Light only, no case transform, no underline, 44px targets.
--}}
.cal {
    --ok: #1F7A4D; --ok-bg: #E6F4EC;
    --few: #8A5300; --few-bg: #FFF3DA; --few-dot: #D98A00;
    --full: #66727F; --full-bg: #EEF1F4;
    --cxl: #B42318; --cxl-bg: #FDECEA;
    --cal-stick: calc(4.75rem + 1px);
    display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.75rem; align-items: start;
}
.cal a, .cal a:hover, .cal a:focus-visible { text-decoration: none; }
.cal .icon { inline-size: 1.1rem; block-size: 1.1rem; flex: none; }
.page-top.cal-top.band-dark { padding-block-end: clamp(2rem, 4vw, 3.5rem); }
@media (max-width: 40rem) { .page-top.cal-top .lede { display: none; } .page-top.cal-top h1 { margin-block-end: 0; } }

/* ---- chips, the round buttons of the page ---- */
.cal-chip {
    display: inline-flex; align-items: center; gap: .5rem; min-block-size: 2.75rem; padding: 0 1rem;
    border: 1px solid var(--rule); background: #fff; border-radius: 999px;
    font-size: var(--t-sm); font-weight: 600; color: var(--kaiki-text); white-space: nowrap; cursor: pointer;
}
.cal-chip:hover { border-color: color-mix(in srgb, var(--kaiki-primary) 35%, var(--rule)); }
.cal-chip.is-on, details[open] > summary.cal-chip { background: var(--kaiki-primary); border-color: var(--kaiki-primary); color: #fff; }
summary.cal-chip { list-style: none; }
summary.cal-chip::-webkit-details-marker { display: none; }
.cal-chip .chev { inline-size: .95rem; block-size: .95rem; transition: transform .15s ease; }
details[open] > summary .chev { transform: rotate(180deg); }
.cal-nav {
    inline-size: 2.75rem; block-size: 2.75rem; flex: none; border-radius: 999px; border: 1px solid var(--rule);
    background: #fff; display: grid; place-items: center; color: var(--kaiki-primary);
}
.cal-nav.is-off { color: #C4CCCA; border-color: #EDF1F0; }
.cal-nav:not(.is-off):hover { background: var(--mist); }

/* ---- the filters ---- */
.cal-aside { display: none; }
.cal-filters { display: grid; gap: 1.1rem; }
.cal-filters-title { margin: 0; font-size: var(--t-title); color: var(--deep); }
.cal-f { display: grid; gap: .15rem; }
.cal-f-label { margin: 0 0 .25rem; font-size: var(--t-cap); font-weight: 700; color: var(--ink-soft); }
.cal-stepper { display: inline-flex; align-items: center; justify-self: start; border: 1px solid var(--rule); border-radius: 999px; background: #fff; }
.cal-stepper > a, .cal-stepper > .is-off {
    inline-size: 2.75rem; block-size: 2.75rem; display: grid; place-items: center;
    font-size: 1.25rem; font-weight: 700; color: var(--kaiki-primary); border-radius: 999px;
}
.cal-stepper > a:hover { background: var(--mist); }
.cal-stepper > .is-off { color: #C4CCCA; }
.cal-stepper-value { min-inline-size: 5.5rem; text-align: center; font-weight: 700; font-size: var(--t-sm); }
.cal-check {
    display: flex; align-items: center; gap: .65rem; min-block-size: 2.75rem;
    font-size: var(--t-sm); color: var(--kaiki-text); line-height: 1.3;
}
.cal-check .box {
    inline-size: 1.25rem; block-size: 1.25rem; border-radius: 6px; border: 1.5px solid #B9C6C4;
    display: grid; place-items: center; flex: none; color: #fff; background: #fff;
}
.cal-check .box svg { inline-size: .85rem; block-size: .85rem; visibility: hidden; }
.cal-check[aria-current] .box { background: var(--kaiki-primary); border-color: var(--kaiki-primary); }
.cal-check[aria-current] .box svg { visibility: visible; }
.cal-check.is-radio .box { border-radius: 999px; }
.cal-check.is-radio[aria-current] .box { background: #fff; box-shadow: inset 0 0 0 4px var(--kaiki-primary); }
.cal-check small { margin-inline-start: auto; color: var(--ink-faint); font-size: var(--t-cap); white-space: nowrap; }
.cal-check:hover span:not(.box) { color: var(--kaiki-primary); }
.cal-clear { justify-self: start; }

/* ---- the strip of days ---- */
.cal-main { min-inline-size: 0; }
.cal-strip {
    position: sticky; top: var(--cal-stick); z-index: 20;
    display: flex; align-items: center; gap: .5rem;
    background: #fff; padding-block: .6rem; margin-inline: calc(-1 * clamp(1.25rem, 3vw, 2.5rem));
    padding-inline: clamp(1.25rem, 3vw, 2.5rem); border-bottom: 1px solid var(--rule);
}
.cal-strip .cal-week, .cal-strip .cal-month { display: none; }
.cal-pills {
    list-style: none; margin: 0; padding: 0; flex: 1; min-inline-size: 0;
    display: flex; gap: .4rem; overflow-x: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch;
}
.cal-pills::-webkit-scrollbar { display: none; }
.cal-pills li { flex: 0 0 3.4rem; }
.cal-pill {
    min-block-size: 3.9rem; border-radius: 12px; border: 1px solid var(--rule); background: #fff;
    display: grid; place-items: center; align-content: center; gap: .05rem; padding: .35rem .2rem; line-height: 1.15;
    color: var(--kaiki-text);
}
.cal-pill small { font-size: .74rem; font-weight: 600; color: var(--ink-faint); }
.cal-pill b { font-size: 1.02rem; }
.cal-pill:hover { border-color: color-mix(in srgb, var(--kaiki-primary) 35%, var(--rule)); }
.cal-pill.is-on { background: var(--kaiki-primary); border-color: var(--kaiki-primary); color: #fff; }
.cal-pill.is-on small { color: rgba(255, 255, 255, .78); }
.cal-pill.is-on b, .cal-pill.is-on.is-none b { color: #fff; }

/* While `calendar.js` fetches the next answer: the days fade, nothing covers
   them and nothing spins. `aria-busy` on the wrapper says the same aloud. */
.cal-main { transition: opacity .15s ease; }
.cal[aria-busy="true"] .cal-main { opacity: .5; }
.cal[aria-busy="true"] .cal-filters { cursor: progress; }
@media (prefers-reduced-motion: reduce) { .cal-main { transition: none; } }
.cal-pill.is-none b { color: #A9B3B1; }
.dot { inline-size: 6px; block-size: 6px; border-radius: 50%; margin-block-start: 3px; background: transparent; }
.dot-available { background: var(--ok); }
.dot-few { background: var(--few-dot); }
.dot-full { background: #B7C0C9; }
.dot-cancelled { background: var(--cxl); }
.cal-pill.is-on .dot-available { background: #7CE0AA; }

/* ---- a phone's tools: filters, month, part of the day ---- */
.cal-tools {
    display: flex; gap: .5rem; overflow-x: auto; scrollbar-width: none; padding-block: .85rem .25rem;
    margin-inline: calc(-1 * clamp(1.25rem, 3vw, 2.5rem)); padding-inline: clamp(1.25rem, 3vw, 2.5rem);
    align-items: flex-start;
}
.cal-tools > * { flex: none; }
/* One line that scrolls sideways, until a sheet opens and needs the width. */
.cal-tools:has(details[open]) { flex-wrap: wrap; overflow: visible; }
.cal-tools::-webkit-scrollbar { display: none; }
.cal-sheet, .cal-month { position: relative; }
.cal-sheet-panel, .cal-month-panel {
    margin-block-start: .5rem; background: #fff; border: 1px solid var(--rule); border-radius: 16px;
    padding: 1rem; box-shadow: 0 18px 40px -22px rgba(12, 41, 69, .45);
}
.cal-tools details[open] { flex: 1 0 100%; order: -1; }
.cal-tools details[open] > summary { display: inline-flex; }

/* ---- the month ---- */
.cal-month-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-block-end: .5rem; }
.cal-month-name { margin: 0; font-weight: 800; color: var(--deep); font-size: var(--t-title); }
.cal-month-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .2rem; text-align: center; }
.cal-month-grid .wd { font-size: .74rem; font-weight: 700; color: var(--ink-faint); padding-block: .25rem; }
.cal-month-grid .d {
    min-block-size: 2.75rem; display: grid; place-items: center; border-radius: 10px;
    font-weight: 600; font-size: var(--t-sm); color: var(--kaiki-text);
}
.cal-month-grid a.d:hover { background: var(--mist); }
.cal-month-grid .d.is-past, .cal-month-grid .d.is-empty { color: #B3BDBB; }
.cal-month-grid .d.is-weather { color: var(--cxl); }
.cal-month-grid .d.is-range { background: var(--mist); }
.cal-month-grid .d.is-on { background: var(--kaiki-primary); color: #fff; }

/* ---- a day ---- */
.cal-day { margin-block-start: 1.25rem; scroll-margin-top: calc(var(--cal-stick) + 5.5rem); }
.cal-day-h { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; padding: 0 .25rem .5rem; }
.cal-day-h h2 { margin: 0; font-size: var(--t-title); letter-spacing: -.01em; color: var(--deep); line-height: 1.3; }
.cal-day-h span { font-size: var(--t-cap); color: var(--ink-faint); white-space: nowrap; }
.cal-rows { background: #fff; border: 1px solid var(--rule); border-radius: 14px; overflow: hidden; }
.cal-list { list-style: none; margin: 0; padding: 0; }
.cal-empty { margin: 0; padding: 1rem 1.1rem; color: var(--ink-faint); font-size: var(--t-sm); }
.cal-wx {
    display: flex; gap: .75rem; align-items: flex-start; padding: .85rem 1.1rem;
    background: var(--cxl-bg); color: var(--cxl); font-size: var(--t-sm); border-bottom: 1px solid #F5CFCB;
}
.cal-wx .icon { inline-size: 1.3rem; block-size: 1.3rem; margin-block-start: .1rem; }
.cal-wx p { margin: 0; line-height: 1.4; }
.cal-wx b { display: block; }

/* ---- a row: two lines on a phone ---- */
.cal-row {
    display: grid; grid-template-columns: 3.1rem minmax(0, 1fr) auto;
    grid-template-areas: "t nm pr" ". sub sub";
    align-items: start; gap: .3rem .65rem; padding: .8rem .9rem; border-bottom: 1px solid var(--rule);
}
.cal-row:last-child { border-bottom: 0; }
.cal-t { grid-area: t; font-size: 1.1rem; font-weight: 800; color: var(--deep); font-variant-numeric: tabular-nums; line-height: 1.35; }
.cal-row.is-charter { background: var(--mist); }
.cal-row.is-charter .cal-t { font-size: .8rem; line-height: 1.3; padding-block-start: .2rem; }
.cal-nm { grid-area: nm; display: grid; gap: .1rem; min-inline-size: 0; }
.cal-nm a { font-weight: 700; line-height: 1.3; color: var(--kaiki-text); }
.cal-nm a:hover { color: var(--kaiki-accent); }
.cal-meta { font-size: var(--t-cap); color: var(--ink-faint); }
.cal-sub { grid-area: sub; display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-block-start: .2rem; }
.cal-st {
    grid-area: st; justify-self: start; align-self: center;
    display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; font-weight: 600; line-height: 1.2;
    padding: .3rem .6rem; border-radius: 999px;
}
.cal-st::before { content: ""; inline-size: 7px; block-size: 7px; border-radius: 50%; background: currentColor; flex: none; }
.st-ok { color: var(--ok); background: var(--ok-bg); }
.st-few { color: var(--few); background: var(--few-bg); }
.st-few::before { background: var(--few-dot); }
.st-full { color: var(--full); background: var(--full-bg); }
.st-cxl { color: var(--cxl); background: var(--cxl-bg); }
.st-req { color: var(--kaiki-primary); background: var(--mist); }
.st-past { color: var(--full); background: transparent; padding-inline-start: 0; }
.st-past::before { display: none; }
.cal-pr { grid-area: pr; text-align: end; line-height: 1.2; white-space: nowrap; }
.cal-pr b { font-size: 1.02rem; font-weight: 800; color: var(--deep); }
.cal-pr small { display: block; font-size: .74rem; color: var(--ink-faint); }
.cal-act { grid-area: act; justify-self: end; align-self: center; }
.cal-book {
    display: inline-flex; align-items: center; justify-content: center; min-block-size: 2.75rem; padding: 0 1rem;
    border-radius: var(--kaiki-radius); background: var(--kaiki-accent); color: #fff; font-weight: 700;
    font-size: var(--t-sm); white-space: nowrap;
}
.cal-book:hover { background: color-mix(in srgb, var(--kaiki-accent) 85%, #000); color: #fff; }
.cal-book.is-ghost { background: #fff; color: var(--kaiki-primary); border: 1px solid var(--kaiki-primary); }
.cal-book.is-ghost:hover { background: var(--mist); color: var(--kaiki-primary); }
.cal-row.is-dim .cal-t, .cal-row.is-dim .cal-nm a { color: var(--ink-faint); }
.cal-nm a.strike { text-decoration: line-through; text-decoration-thickness: 1px; }
.cal-nm a.strike:hover { text-decoration: line-through; }

/* ---- empty and closed ---- */
.cal-state {
    margin-block-start: 1.25rem; background: #fff; border: 1px solid var(--rule); border-radius: 16px;
    padding: 1.5rem 1.25rem; text-align: center; display: grid; gap: .5rem;
}
.cal-state h2 { margin: 0; font-size: var(--t-title); color: var(--deep); }
.cal-state p { margin: 0; color: var(--ink-soft); }
.cal-state-actions { display: flex; gap: .5rem; justify-content: center; flex-wrap: wrap; margin-block-start: .5rem !important; }
.cal-more { margin: 1.5rem 0 0; display: flex; justify-content: center; }

/* ---- a desk: the filters beside, the row on one line ---- */
@media (min-width: 64rem) {
    .cal { grid-template-columns: 16.5rem minmax(0, 1fr); gap: 1.75rem; }
    .cal-aside {
        display: block; position: sticky; top: calc(var(--cal-stick) + 1rem);
        background: #fff; border: 1px solid var(--rule); border-radius: 16px; padding: 1.1rem;
        max-block-size: calc(100vh - var(--cal-stick) - 2rem); overflow-y: auto;
    }
    .cal-tools { display: none; }
    .cal-strip { margin-inline: 0; padding-inline: 0; border-bottom: 0; }
    .cal-strip .cal-week { display: grid; }
    .cal-strip .cal-month { display: block; }
    .cal-pills li { flex: 1 0 3.6rem; }
    .cal-month-desk .cal-month-panel { position: absolute; inset-inline-end: 0; top: 100%; inline-size: 20rem; z-index: 30; }
    .cal-row {
        grid-template-columns: 4.75rem minmax(0, 1fr) 12rem 7rem 8rem;
        grid-template-areas: "t nm st pr act";
        align-items: center; gap: .9rem; padding: .75rem 1.1rem;
    }
    .cal-t { font-size: 1.25rem; }
    .cal-sub { display: contents; }
    .cal-row.is-charter .cal-t { font-size: .85rem; padding: 0; white-space: nowrap; }
    .cal-pr { text-align: start; }
    .cal-day-h h2 { font-size: var(--t-sub); }
}
