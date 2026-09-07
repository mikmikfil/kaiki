# Caddy, on-demand TLS and the ask endpoint

> Written by #109. **M8 (#13) wires this to a running server** — this file is the fragment and the
> reasoning, not a deployment.

Kaiki serves three kinds of host:

| Host | What it is |
|---|---|
| `kaiki.app` | the application: `/app`, `/admin`, `/api/v1`, the guest token pages |
| `book.kaiki.app` | the hosted operator pages at `/{operator-slug}` |
| an operator's own hostname | the same pages at the root, via a verified `tenant_domains` row |

The first two have certificates the ordinary way. The third cannot: the hostnames are not known when
the server starts, an operator adds one at three in the afternoon, and nobody is going to edit a
Caddyfile for it. That is what **on-demand TLS** is for, and what makes the ask endpoint the most
security-sensitive surface in the product.

## The fragment

```caddyfile
{
	# On-demand TLS asks before obtaining a certificate for a hostname Caddy has
	# never seen. `ask` is not optional and must never be removed: without it,
	# anybody who points a DNS record at this server can make it request
	# certificates until Let's Encrypt's rate limit is exhausted — for every
	# operator at once.
	on_demand_tls {
		ask http://127.0.0.1:8000/tls/ask
		interval 2m
		burst 5
	}
}

# The application and the hosted host: ordinary certificates, named explicitly.
kaiki.app, book.kaiki.app {
	reverse_proxy app:8000
}

# Everything else — the operators' own domains.
:443 {
	tls {
		on_demand
	}

	reverse_proxy app:8000
}
```

## Why the ask endpoint answers the way it does

`GET /tls/ask?domain=<hostname>` returns **200 only** for a hostname with a `verified` row in
`tenant_domains`. Everything else — unknown, pending, failed, disabled, empty, malformed — is an
empty `403`.

- **Empty body, same status for known and unknown.** A `404` saying "no such domain" beside a `403`
  saying "not verified" is a probe oracle: ask about a hostname, learn whether the platform knows it.
  `TlsAskEndpointTest` asserts the two responses are indistinguishable.
- **`verified` and not "belongs to a tenant".** Only `VerifyDomain` sets that status, and only after
  the DNS said the hostname points here. A pending row is somebody's intention.
- **`interval` and `burst` are a second limit, not the first.** They cap how fast Caddy will ask at
  all, so a flood of unknown hostnames costs one refused HTTP request each rather than a certificate
  attempt each.

## What an operator does

1. The panel shows them a CNAME: `book` → `book.kaiki.app`.
2. They create it at their registrar.
3. `domains:check` sweeps every fifteen minutes (`routes/console.php`), or they press **Check now**.
4. On success the row is `verified`, the resolver's cache entry is cleared, and the next request to
   the hostname is served — Caddy asks, gets a 200, and obtains the certificate on the spot.

A domain that **stops** resolving is recorded and reported and **keeps serving** (`VerifyDomain`): a
registrar's bad afternoon must not take an operator's site down, and from here it is indistinguishable
from one.

## What M8 still owes

- The Caddyfile above, in the deployment repository, with the real hostnames.
- A persistent volume for Caddy's certificate storage, or every restart re-issues and meets the rate
  limit the ask endpoint exists to protect.
- `interval`/`burst` tuned against the real number of operators.
