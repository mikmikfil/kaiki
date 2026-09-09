{{--
    The sandbox checkout page (SAA-9, PAY-11, issue 111).

    ## It must be impossible to mistake for a real payment

    An operator walking SAA-9's test booking, and a developer debugging the
    widget, both arrive here from a flow that looks exactly like buying a boat
    trip. So the page says what it is before it says anything else, in a banner
    that is the first thing on it and not a footnote — and there is no card
    field anywhere, because a form that asked for a number would teach somebody
    to type a real one.

    ## Two buttons, because a failure is half the flow

    BKG-12's arithmetic — the seats coming back out of `seats_sold`, the fresh
    hold attempted, the booking that expires because the boat filled while the
    guest was failing to pay — only runs on the failure path, and it is the
    half with the interesting bugs in it.

    Nothing here is a hardcoded string (I18N-1), and there is no JavaScript:
    two forms and a `POST`.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Never indexed. It is a payment page for a booking that is not real. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('sandbox.title') }}</title>
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0;
            background: #f2f5f7;
            color: #131A22;
            font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .wrap { max-width: 34rem; margin: 0 auto; padding: 2.5rem 1rem; }
        .banner {
            background: #B5511F;
            color: #fff;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-weight: 700;
        }
        .card {
            margin-top: 1rem;
            background: #fff;
            border: 1px solid #dbe3e9;
            border-radius: 0.5rem;
            padding: 1.5rem;
        }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 0.4rem 1rem; margin: 1.25rem 0; }
        dt { color: #5b6b78; }
        dd { margin: 0; text-align: right; font-weight: 600; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem; }
        button {
            font: inherit;
            font-weight: 600;
            padding: 0.7rem 1.4rem;
            border-radius: 0.4rem;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .pay { background: #123A5E; color: #fff; }
        .decline { background: #fff; color: #131A22; border-color: #dbe3e9; }
        .note { color: #5b6b78; font-size: 0.875rem; margin-top: 1.25rem; }
    </style>
</head>
<body>
<div class="wrap">
    <p class="banner">{{ __('sandbox.banner') }}</p>

    <div class="card">
        <h1>{{ __('sandbox.heading') }}</h1>
        <p>{{ __('sandbox.body') }}</p>

        <dl>
            <dt>{{ __('sandbox.reference') }}</dt>
            <dd>{{ $booking->reference }}</dd>

            <dt>{{ __('sandbox.amount') }}</dt>
            <dd>{{ \App\Support\Format\MoneyFormatter::format($payment->amount_cents, app()->getLocale(), $payment->currency) }}</dd>
        </dl>

        <div class="actions">
            <form method="POST" action="{{ route('sandbox.checkout.pay', ['reference' => $reference]) }}">
                @csrf
                <button type="submit" class="pay">{{ __('sandbox.actions.pay') }}</button>
            </form>

            <form method="POST" action="{{ route('sandbox.checkout.fail', ['reference' => $reference]) }}">
                @csrf
                <button type="submit" class="decline">{{ __('sandbox.actions.decline') }}</button>
            </form>
        </div>

        <p class="note">{{ __('sandbox.note') }}</p>
    </div>
</div>
</body>
</html>
