# myDATA error codes, in Greek an operator can act on

> **Status: mechanism complete, table partial.** Read §"What is verified" before
> trusting a row.

Spec **MYD-9**: *"AADE error codes, starting with error 243 and its neighbours,
are surfaced with plain-Greek explanations. The dictionary lives in
`docs/compliance/mydata-errors.md` and is loaded from lang files at runtime."*

## How it works

The Greek strings live in `lang/{el,en}/mydata.php` under `errors.<code>`, not
in this file — this file is the reference and the record of where each entry came
from. `App\Domain\Compliance\Support\AadeErrors` looks a code up and falls back
to an `unknown` entry that shows **AADE's own message verbatim** beside a generic
explanation.

That fallback is the important part. A code nobody has mapped yet still reaches
the operator as something they can read out to their accountant, rather than as a
blank row.

## What is verified, and what is not

**This table is not AADE's published error list.** It has not been checked
against a live endpoint, because the platform has no AADE credentials yet
(2026-09-08). Every row below is marked with where it came from.

| Marker | Meaning |
|---|---|
| ✔ | Named in the Kaiki spec or brief, or observed in a real response |
| ~ | Reasonable from the API shape; **verify before go-live** |
| ✘ | Not an AADE code at all — a transport outcome this system invents |

**Before M6 ships, somebody with an AADE developer account must walk this table
against the official error list and either confirm each `~` row or delete it.**
A confidently wrong Greek explanation in front of an operator is worse than no
explanation: they will act on it. The `unknown` fallback is safe; a wrong entry
is not.

## The table

| Code | Marker | Greek shown to the operator | What it usually means |
|---|---|---|---|
| `243` | ✔ | Ο ΑΦΜ του πελάτη δεν βρέθηκε ή δεν είναι ενεργός. | The counterparty ΑΦΜ on a ΤΠΥ is not a live registration. Named explicitly in MYD-9. |
| `not_configured` | ✘ | Δεν έχει συνδεθεί το myDATA. | Kaiki's own: no credentials stored, nothing was sent. See `NullMyDataGateway`. |
| `timeout` | ✘ | Η ΑΑΔΕ δεν απάντησε. Θα ξαναπροσπαθήσουμε. | Transport. Retryable. |
| `http_500` | ✘ | Η ΑΑΔΕ έχει πρόβλημα αυτή τη στιγμή. Θα ξαναπροσπαθήσουμε. | Transport. Retryable. |
| `http_401` | ✘ | Τα διαπιστευτήρια της ΑΑΔΕ δεν έγιναν δεκτά. | Transport, and **not** retryable: a wrong key is wrong in six hours too. |
| `unknown` | ✘ | *(fallback — shows AADE's own message)* | Any code not listed. |

Note how short the verified list is. That is deliberate and it is the honest
state of this file today.

## Adding a code

1. Add the row here with a marker and where it came from.
2. Add `errors.<code>` to **both** `lang/el/mydata.php` and `lang/en/mydata.php`
   — `LangKeyParityTest` fails otherwise, in both directions.
3. If the code should stop the retry ladder, add it to
   `AadeErrors::PERMANENT`. Getting this wrong in either direction is the
   expensive mistake: a permanent error retried eight times fills a failure feed
   with something that was never going to work, and a transient error treated as
   permanent loses an invoice that would have gone through on the next attempt.
