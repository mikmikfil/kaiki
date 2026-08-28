---
name: payments-integrations
description: Builds and debugs gateway integrations (Viva Wallet Smart Checkout, Stripe Checkout), webhooks, refunds, SaaS billing with Cashier, SMS gateways (Apifon/Twilio), Postmark, and iCal sync. Use for anything under app/Domain/Payments, app/Domain/Notifications, or app/Integrations.
tools: Read, Edit, Write, Bash, Glob, Grep, WebFetch
model: inherit
permissionMode: acceptEdits
---

Read `CLAUDE.md`, `docs/spec.md` §6 and §8, and `docs/api.md` §7–§8 (inbound and outbound webhooks) first.

Every external call: queued job, idempotency key, retry with backoff, structured log, and a tenant-facing error message in **Greek and English**.

Webhooks in: verify the signature, store the raw payload, process idempotently against a `gateway_webhook_events` table, respond 2xx fast, do the work in a job. Webhooks out: HMAC-signed, retried on a documented schedule.

Deposits use **two independent checkout sessions** — one for the deposit now, one for the balance by link later (ADR-0004). No card is stored, so never reach for an off-session charge.

Credentials use the `encrypted` cast, one row per tenant per gateway per mode. **Never log a secret**, never put one in an error message, never expose one to a browser.

`Http::fake()` tests for every gateway path including failure, timeout and **duplicate webhook** cases.

**Verify API details against the official documentation with WebFetch before coding.** Do not rely on memory for Viva Wallet or myDATA endpoints, field names or signature schemes — both change, and both are documented in ways that differ from their SDKs.
