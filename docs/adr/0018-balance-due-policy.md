# ADR-0018: Balance due date and what happens when a deposit booking never pays the balance

- Status: **Accepted (Option A)**
- Decided: 2026-08-28
- Date: 2026-08-28
- Deciders: product owner
- Related: §2, §6, §9 (unpaid balances), §4 (RatePlan) of `docs/BRIEF.md`; requirements PAY-14 … PAY-20, BKG-27

## Context
Deposits and balance links are in scope (§2), and the operator dashboard shows "unpaid balances" (§9), but nothing in the brief says *when* the balance is due, whether the guest is reminded, or what the operator can do if it is never paid. Without a due date the dashboard cannot sort by urgency, no reminder can be scheduled, and the seat or vessel window stays committed indefinitely for a partly paid booking. This is coupled to ADR-0004 Option D (two separate checkout sessions) and blocks **M2**.

## Options

### Option A — Per-tenant `balance_due_days_before_departure` (default 14), optional per-RatePlan override, reminders at due date minus 7 and minus 1 days, no automatic cancellation
The balance due instant is `starts_at_utc` minus N days, floored to 09:00 in the tenant timezone. Two reminder emails plus optional SMS. Overdue bookings appear in a dashboard bucket with one-click actions: send reminder, cancel per policy, convert to full refund, or mark as paid cash.
Pros
- Predictable, explainable to guests in the confirmation email and in the cancellation policy text.
- Never cancels a paying customer automatically, which is the failure mode operators fear most.
- Fits the existing reminder scheduler built for guest details and T-24h (§6).
Cons
- Requires operator attention for genuinely dead bookings; seats stay committed until they act.
- If the booking is made inside the due window, the balance is due immediately — needs an explicit rule (see consequences).

### Option B — As above, plus automatic cancellation N days after the due date, applying the cancellation policy
Pros
- Self-healing inventory; seats return to sale without operator action.
Cons
- Automatically cancelling a booking that has money on it is a support incident waiting to happen, especially across a language barrier.
- Interacts badly with weather cancellations and with manual bookings marked paid by bank transfer that arrives late.

### Option C — No due date; balance is simply payable up to departure, dashboard sorts by departure date
Pros
- Nothing to build; matches the literal brief.
Cons
- No reminders, so operators chase manually — the exact administrative work the product claims to remove.
- Crew discover unpaid balances at the pier.

## Recommendation
**Option A** for MVP, with Option B kept behind a Pennant flag (`auto_cancel_overdue_balances`, off) so it can be enabled per tenant later once real behaviour is observed. Automatic cancellation is a one-way door for the guest relationship and should not be the default in v1.

## Consequences if accepted
- `tenants.balance_due_days_before_departure` (default 14) and optional `rate_plans.balance_due_days_before_departure`; `bookings.balance_due_at` is computed and stored at confirmation so reminders and sorting are index-friendly.
- If `starts_at_utc` minus N days is already in the past at confirmation, `balance_due_at` is set to confirmation time plus 24 hours, capped at 2 hours before departure.
- Reminder jobs at due minus 7 days and due minus 1 day, plus an overdue notice; all logged in the `Notification` log and idempotent per booking per reminder type.
- Dashboard gains an "Υπόλοιπα / Balances due" bucket with counts for due soon and overdue.
- The confirmation email and `/b/{manage_token}` both state the due date in the guest locale.
- The Pennant flag and the auto-cancel job are designed but not built in MVP.
