import { useCallback, useEffect, useState } from 'preact/hooks';
import type { RefObject } from 'preact';

import type { Translator } from '../../i18n';

/**
 * The bottom sheet's two questions: may it, and is it open.
 *
 * ADR-0033, Option A. Below 60rem the booking card leaves the flow and pins
 * itself to the bottom of the viewport, collapsed to a bar carrying the price,
 * the selection and the action. Same DOM, same widget instance, same state —
 * the arrangement is CSS, not a second component, so nothing can get out of
 * step with anything.
 */

/** The breakpoint the hosted page's own two columns begin at. */
const WIDE = '(min-width: 60rem)';

/** 60rem at the 16px this widget pins itself to, for the fallback below. */
const WIDE_PX = 960;

/**
 * Is the viewport wide enough to leave the card in the flow?
 *
 * `matchMedia` is the right question, and it is not always there to ask: a
 * server-side render, a test environment, an old embedded webview. Falling back
 * to `innerWidth` keeps the answer sensible; answering «narrow» by accident
 * would pin a sheet to the bottom of a desktop.
 */
function isWide(view: Window): boolean {
  return typeof view.matchMedia === 'function'
    ? view.matchMedia(WIDE).matches
    : (view.innerWidth ?? WIDE_PX) >= WIDE_PX;
}

/**
 * Can a `position: fixed` element in here actually reach the viewport?
 *
 * WGT-22 requires the widget to work inside "iframe-heavy page builders", and
 * this is where that bites: `position: fixed` is trapped by any ancestor with a
 * `transform`, `filter`, `perspective`, `backdrop-filter`, `will-change` naming
 * one of those, or `contain: paint`. Elementor, Divi and WPBakery all apply
 * transforms to animated sections, so on somebody's WordPress page the sheet
 * would pin itself to the middle of a slider instead of the bottom of the
 * screen.
 *
 * **Measured, not sniffed.** Reading `getComputedStyle` up the tree means
 * naming every property that creates a containing block and being wrong the
 * next time the list grows — and it cannot see out of a shadow host. A probe
 * asks the browser the actual question: put a fixed element at the viewport
 * origin and see whether it landed there. Anything that traps it, known to this
 * code or not, moves it.
 *
 * The probe is appended, measured and removed inside one frame; it never paints
 * (`opacity: 0`, no pointer events) and never reaches the accessibility tree.
 */
export function fixedReachesViewport(root: HTMLElement): boolean {
  const doc = root.ownerDocument;

  if (doc === null || doc.defaultView === null) {
    return false;
  }

  const probe = doc.createElement('div');

  probe.setAttribute('aria-hidden', 'true');
  probe.style.cssText =
    'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;contain:strict';

  root.appendChild(probe);

  const box = probe.getBoundingClientRect();

  probe.remove();

  // A trapped probe sits at its containing block's origin instead of the
  // viewport's. One pixel of tolerance for subpixel layout and zoom.
  return Math.abs(box.top) <= 1 && Math.abs(box.left) <= 1;
}

/**
 * Whether the booking card should be a sheet right now.
 *
 * Narrow **and** able to pin. The width is watched, because a phone rotates and
 * a desktop window is dragged; the probe is re-run with it, because a host page
 * can add a transform to an ancestor long after mount — a page builder starting
 * an animation is exactly that.
 */
export function useSheetMode(rootRef: RefObject<HTMLElement>): boolean {
  const [sheet, setSheet] = useState(false);

  const decide = useCallback(() => {
    const root = rootRef.current;

    if (root === null || root.ownerDocument?.defaultView == null) {
      setSheet(false);

      return;
    }

    const on = !isWide(root.ownerDocument.defaultView) && fixedReachesViewport(root);

    setSheet(on);

    /**
     * Tell the host page, so it can stop drawing what the bar now carries.
     *
     * The decision is made in here, at runtime, from a measurement — the page
     * has no way to work it out for itself, and a media query on its side would
     * be a second opinion that can disagree with this one. So the shadow host
     * carries the answer as an attribute the page's own CSS can match. It is
     * the only thing the widget writes into somebody else's document, and it
     * says nothing about the guest.
     */
    const host = (root.getRootNode() as { host?: unknown }).host;

    if (host instanceof HTMLElement) {
      if (on) {
        host.setAttribute('data-kaiki-sheet', 'true');
      } else {
        host.removeAttribute('data-kaiki-sheet');
      }
    }
  }, [rootRef]);

  useEffect(() => {
    decide();

    const view = rootRef.current?.ownerDocument?.defaultView;

    if (view == null) {
      return;
    }

    /**
     * `matchMedia` only, and deliberately not `resize`.
     *
     * It was both, and `resize` was a mistake that only shows on a phone: the
     * address bar collapsing and expanding as you scroll fires `resize`
     * continuously, and every one of those ran the probe — appending an
     * element, reading `getBoundingClientRect` (a forced synchronous layout)
     * and removing it again, dozens of times a second, during a scroll. The
     * answer was the same every time, because the only thing that can change it
     * is crossing the breakpoint.
     *
     * A media query change event fires exactly when that happens and not once
     * otherwise. The `resize` fallback stays only for an environment with no
     * `matchMedia` at all, where there is nothing else to listen to.
     */
    if (typeof view.matchMedia === 'function') {
      const query = view.matchMedia(WIDE);

      query.addEventListener('change', decide);

      return () => query.removeEventListener('change', decide);
    }

    let timer = 0;
    const settle = (): void => {
      view.clearTimeout(timer);
      timer = view.setTimeout(decide, 150);
    };

    view.addEventListener('resize', settle);

    return () => {
      view.clearTimeout(timer);
      view.removeEventListener('resize', settle);
    };
  }, [decide, rootRef]);

  return sheet;
}

/**
 * False for the first two frames after the sheet appears, true afterwards.
 *
 * The sheet rests at `translateY(calc(100% - peek))` and animates to `0` when it
 * opens. On the frame it first becomes fixed, that resting transform is itself
 * a change from nothing, so the browser animated *it* — the bar slid up from
 * below the screen every time the widget finished loading. Coming after the
 * page's own instant bar had been on screen for a moment and then vanished, it
 * read as the thing loading twice.
 *
 * So the transition is attached one paint late: the sheet's first frame is
 * drawn where it belongs with nothing to animate, and only the opening and
 * closing that follow are animated. Two frames rather than one because the
 * first only guarantees the style has been computed, not that it has been
 * painted with it.
 */
export function useSettled(active: boolean): boolean {
  const [settled, setSettled] = useState(false);

  useEffect(() => {
    if (!active) {
      setSettled(false);

      return;
    }

    let second = 0;
    const first = requestAnimationFrame(() => {
      second = requestAnimationFrame(() => setSettled(true));
    });

    return () => {
      cancelAnimationFrame(first);
      cancelAnimationFrame(second);
    };
  }, [active]);

  return settled;
}

/**
 * The bar, and the three things it says at once.
 *
 * | Chosen | Price | Summary | Action |
 * |---|---|---|---|
 * | nothing | `από 55,00 €` | «Διαλέξτε ημερομηνία» | Κράτηση |
 * | a day | `από 55,00 €` | `16 Σεπ · 09:00` | Συνέχεια |
 * | a party | `110,00 €` | `16 Σεπ · 09:00 · 2 άτομα` | Κράτηση |
 *
 * The `από` is dropped the moment a real total exists. It is not decoration: it
 * is the difference between an indication and a promise, and a bar still
 * reading «από» after two adults have been chosen is lying on every scroll.
 *
 * The button stays short. The amount is already sitting a few pixels away in
 * type twice its size, and at 372px the two do not fit; the pinned action
 * inside the open sheet is where the label repeats it, because that is where
 * the width is.
 */
export function Peek({
  price,
  summary,
  action,
  ready,
  open,
  onToggle,
}: {
  readonly price: string;
  readonly summary: string;
  readonly action: string;
  readonly ready: boolean;
  readonly open: boolean;
  readonly onToggle: () => void;
  readonly t?: Translator;
}) {
  return (
    <button type="button" class="kaiki-peek" aria-expanded={open} onClick={onToggle}>
      {/* The tab, standing above the bar's own edge.

          An arrow inside the bar was a mark on a surface — easy to read as
          decoration, and it had to compete with the price for the eye. Lifted
          out into a tab it stops being a symbol and becomes a shape: a piece of
          the sheet sticking up past its own top edge, which is the thing itself
          saying it goes further. It carries the same surface, the same hairline
          and no bottom border, so it and the bar are one outline rather than a
          badge sitting on one.

          A child of the button, not a control of its own: the whole bar is the
          target — ADR-0033's point that a thumb should not have to aim — and a
          nested button inside a button is invalid anyway. */}
      <span class="kaiki-peek-tab" data-open={open} aria-hidden="true">
        <svg viewBox="0 0 24 14" width="100%" height="100%" focusable="false">
          <path
            d="M2.6 11.1 12 2.9l9.4 8.2"
            fill="none"
            stroke="currentColor"
            stroke-width="2.6"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </span>

      <span class="kaiki-peek-text">
        <span class="kaiki-peek-price">{price}</span>
        <span class="kaiki-peek-summary">{summary}</span>
      </span>

      {open ? null : (
        <span class="kaiki-peek-action" data-ready={ready}>
          {action}
        </span>
      )}
    </button>
  );
}
