{{-- Inter, self-hosted (typography review, 2026-09-24).

     Every guest page asked for «Inter» and none of them loaded it: the brand's
     font source is "system", so a visitor without Inter installed (nearly all
     of them) got Arial or Helvetica on the site and system-ui on the booking
     pages — three faces between choosing a trip and paying for it.

     The variable font from @fontsource-variable/inter 5.3.0 (OFL-1.1, see
     public/fonts/inter/LICENSE.txt): one file per script, every weight from
     100 to 900 in it, so the 400–800 the pages use is three downloads at most
     and in practice two (Greek and Latin). Same origin, so the hosted pages'
     `font-src 'self'` and the booking pages' `font-src 'self' data:` already
     allow it — no Google request, no new CSP origin.

     Included inside each layout's own `<style>` (the hosted one carries the
     nonce), not as a separate stylesheet: one less request before first paint.
     `swap`, so the text is readable in the fallback while the file arrives. --}}
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-display: swap;
            font-weight: 100 900;
            src: url('/fonts/inter/inter-greek-wght-normal.woff2') format('woff2');
            unicode-range: U+0370-0377, U+037A-037F, U+0384-038A, U+038C, U+038E-03A1, U+03A3-03FF;
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-display: swap;
            font-weight: 100 900;
            src: url('/fonts/inter/inter-latin-ext-wght-normal.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-display: swap;
            font-weight: 100 900;
            src: url('/fonts/inter/inter-latin-wght-normal.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
