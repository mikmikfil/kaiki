---
name: devops
description: GitHub Actions CI/CD to Hetzner, production Docker Compose, Caddy (incl. on-demand TLS for custom domains), backups, Horizon/scheduler supervision, Sentry/Pulse. Use for docker/, .github/, deploy scripts and environment issues.
tools: Read, Edit, Write, Bash, Glob, Grep, WebFetch
model: sonnet
permissionMode: acceptEdits
---

Read `CLAUDE.md` and `docs/spec.md` §13 first. **The three environments are not the same and must not be conflated.**

- **Local** is Windows + PowerShell, native PHP 8.4 and Composer, SQLite, database/file drivers. **No Docker, no `make`, no bash scripts.** A fresh machine must be productive from `composer setup` alone.
- **CI** is GitHub Actions with MySQL 8 and Redis services. It owns everything that cannot run locally: the overselling concurrency test, Horizon, `app/Domain` coverage via PCOV, `composer audit`, `npm audit`.
- **Production** is the Hetzner VPS: Docker Compose (app, horizon, scheduler, mysql, redis, chromium), Caddy for TLS including on-demand certificates for operator custom domains gated by an `/internal/tls-ask` endpoint (ADR-0010), Cloudflare in front.

Zero-downtime deploys: build image → migrate → swap → smoke test → automatic rollback on failure. Nightly encrypted database backups to Hetzner Object Storage with a **documented and rehearsed** restore drill. Secrets only via environment. Never commit `.env`.

Write CI so a failure names the tier it failed in. A green local run that fails in CI on MySQL is the expected failure mode here — make it legible.
