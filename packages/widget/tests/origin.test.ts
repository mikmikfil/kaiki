import { expect, it, vi } from 'vitest';

import { ApiClient } from '../src/api-client';
import { BookingApi } from '../src/booking/api';
import { initialState } from '../src/booking/machine';

/*
 * The page the guest started on travels with the draft (2026-09-11), so the
 * checkout and booking pages can offer «back to the website». The API decides
 * whether to keep it; the widget only has to send it, without the fragment.
 */

it('sends the page the guest is booking from, without the fragment', async () => {
  const fetchMock = vi.fn(() =>
    Promise.resolve(
      new Response(
        JSON.stringify({
          data: { uuid: 'b-1', reference: 'KAI-1', manage_token: 'tok', checkout_url: 'https://book.kaiki.app/c/tok', status: 'draft' },
        }),
        { status: 201, headers: { 'Content-Type': 'application/json' } },
      ),
    ),
  );

  const api = new BookingApi(new ApiClient('https://api.kaiki.app/api/v1', 'pk_test', fetchMock as unknown as typeof fetch));

  window.history.replaceState(null, '', '/tours/sunset?utm_source=newsletter#book');

  await api.createDraft({ ...initialState(), localDate: '2026-10-03', pax: { adult: 2 } }, 'product-uuid', 'en');

  const init = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1];
  const body = JSON.parse(String(init.body)) as { origin_url?: string };

  expect(body.origin_url).toBe(`${window.location.origin}/tours/sunset?utm_source=newsletter`);
});
