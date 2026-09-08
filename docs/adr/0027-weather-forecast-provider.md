# ADR-0027 — Open-Meteo as the weather forecast provider

- **Status:** Accepted
- **Date:** 2026-09-08
- **Decided by:** the product owner
- **Affects:** OPS-6, OPS-7, CXL-6, and a new per-vessel threshold on `vessels`
- **Adds a dependency on a third-party API** — see *Licensing*, which is the one
  part of this that needs a decision the engineering cannot make.

## Context

Weather cancellation already exists end to end: `CancelDeparture` (#84), the
preview that shows each guest what they are owed from their own policy snapshot
(#121, OPS-7), and the fourteen-day choice window (CXL-7). What none of it has
is the thing that starts the process — **knowing the weather is coming**.

Today an operator finds out from their phone, at nine in the evening, and then
goes looking for the affected sailings. The product can put the forecast beside
the sailings it threatens, which is the whole of this feature: it does not
decide anything, it removes the gap between *"it is going to blow on Thursday"*
and *"these three departures and twenty-seven passengers are affected"*.

A forecast means an external API, and `CLAUDE.md` makes that a hard stop rather
than a judgement call.

## Decision

**Open-Meteo** (`api.open-meteo.com`), chosen by the product owner.

- **Wind, not weather.** Daily maximum wind speed and maximum gust at the port's
  own coordinates. Rain does not cancel a boat trip; wind does, and the number
  a Greek skipper actually uses is **Beaufort**.
- **Per-vessel threshold.** A new nullable `vessels.max_wind_bft`. A RIB and a
  forty-eight-seat kaiki do not stop sailing at the same number, and a
  fleet-wide constant would either cancel trips that were fine or fail to flag
  the one that was not.
- **No key, no SDK.** The free endpoint needs neither. It is called through
  `App\Contracts\WeatherProvider` so the provider is one class, and the base URL
  is config so the paid endpoint is an `.env` change rather than a code change.

## Licensing — the part that is not an engineering decision

**Open-Meteo's free API is for non-commercial use.** Commercial use requires
their paid subscription. Kaiki is a commercial SaaS, so shipping this to paying
operators on the free endpoint would be outside those terms.

Nothing here is blocked by that: the code is written against the contract, the
base URL and an optional API key are config, and switching to the commercial
endpoint — or to a different provider entirely — is an `.env` change plus, at
worst, one class. **But the subscription has to be bought before this is in
front of a paying operator, and that is the product owner's call, not mine.**
Recorded here rather than in a comment nobody reads.

## Consequences

### The forecast is advice, and the product never acts on it

No automatic cancellation, ever. The panel shows the days over a vessel's
threshold and how many sailings and passengers sit on them, and the button goes
to #121's preview — where a person still chooses. A product that cancelled a
charter because an API said 7 Bft would eventually cancel one on a day that
turned out fine, and the operator would lose the money and the customer.

### A forecast that cannot be fetched shows nothing, not zero

The same rule the iCal import follows. An empty panel says "we do not know";
a panel showing 0 Bft because a request timed out says "it is calm on Thursday",
which is the one thing it must never say wrongly.

### It is cached hard, and refreshed on a schedule

A daily maximum does not change minute to minute. The forecast is fetched per
*port* — not per vessel, since a fleet in one marina shares a sky — cached for
hours, and refreshed by a scheduled job. Without that, every dashboard render by
every operator is an outbound HTTP call, which is both a bill and a rate limit.

### Beaufort is computed here, from metres per second

Open-Meteo returns a speed; skippers use a scale. The conversion is a table in
one class with a test, because a threshold comparison against a wrongly derived
number is a cancellation that should not have happened.

## Alternatives considered

**OpenWeatherMap.** Requires a key on the free tier, and its free plan has been
narrowed repeatedly. Rejected in favour of one that needs no key to develop
against.

**The national service (ΕΜΥ).** Authoritative for Greek waters and the obvious
long-term answer, but it publishes no documented JSON API. Worth revisiting if
Open-Meteo's marine coverage proves poor in the Cyclades.

**No forecast; leave it to the operator's phone.** This is what happens today
and it works — the feature's value is not the forecast, it is joining the
forecast to the sailing list. That join is the part no phone app can do.
