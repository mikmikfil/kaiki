# ADR-0026: How is the `docs/api.md` contract validated and diffed, given no YAML parser is approved?

- Status: **Proposed — awaiting human decision**
- Date: 2026-09-01
- Deciders: product owner
- Related: `docs/spec.md` ENV-28, ARC-19, ARC-20, ARC-21c; `docs/api.md` §5, §10; [ADR-0019](0019-packages-beyond-section-3.md)
- Raised by: issue #11, which implemented as much of §10 as could be built without a decision.
- **Blocks nothing today.** The partial gate is live and required. This decides how much further it can go.

## Context

`docs/api.md` §5 is a 4,100-line OpenAPI 3.1 document written by hand, ahead of the code, and §10 defines a five-step CI pipeline to reconcile it with what Scramble generates:

1. extract the fenced YAML block,
2. **`spectral lint` it** against OpenAPI 3.1 (unresolved `$ref`, operation missing `operationId`/`summary`/`security`/a `4xx`, duplicate `operationId`, a property neither required nor nullable),
3. generate from the routes,
4. **structurally diff** on paths, methods, `operationId`s, `security`, **required request-body fields** and **`$ref` targets**,
5. fail loudly.

Steps 1, 3 and part of 4 shipped in #11. **Steps 2 and the rest of 4 did not, because they need to parse YAML and this repository has no way to do that.**

- `symfony/yaml` is not installed and is not in ARC-20 or the ADR-0019 shortlist.
- PHP's `yaml` extension is not enabled in any of the three environment tiers, and ENV-1 pins the extension list.
- Node ships no YAML parser; `@stoplight/spectral-cli` and any `yaml` package are new npm dependencies.

ARC-19 and CLAUDE.md both say the same thing about this: anything outside the approved list "needs its own ADR — that is a hard stop, not a judgement call". #11 therefore built the strongest gate a **line scanner** can support and stopped, rather than quietly adding a package to finish the job.

There is precedent for the scanner in `tests/Support/WorkflowFile.php` (#4), which reads the CI workflow YAML the same way, for the same reason, and says so.

### What the partial gate does and does not catch

| §10 promise | Shipped in #11 | Why |
|---|---|---|
| One fenced YAML block | ✅ | asserted directly |
| Paths and methods | ✅ | path keys are legible at fixed indentation |
| `security` per operation | ✅ | and compared against what the route's middleware actually enforces |
| `operationId` | ✅ read, ⚠️ not yet asserted unique | trivial to add |
| Every operation has `summary`, a `4xx` | ❌ | needs the document as a tree |
| Required request-body fields | ❌ | needs `$ref` resolution |
| `$ref` targets resolve | ❌ | needs a parser |
| Schema property neither required nor nullable | ❌ | needs a parser |

The gap is not academic. **Required request-body fields are the half of the contract a client actually breaks on**, and they are exactly what a scanner cannot see: they live behind `$ref` chains into `components/schemas`.

## Options

### Option A — `symfony/yaml` as a composer dev dependency
Parse §5 in PHP; implement §10.2's checks and the full §10.4 diff as Pest tests, extending the existing `Tests\Support\Api\OpenApiContract`.

**Pros**
- One language for the whole gate; no Node in a PHP CI job.
- `require-dev` only, so it never ships to production and cannot be used by application code by accident.
- Symfony is already a transitive dependency of the framework, so the supply-chain surface barely moves.
- Deleting the line scanner removes the one place in the repo that reads YAML with regex.

**Cons**
- ARC-21c's rule is about `composer.json`, so this is precisely the list this ADR must widen.
- It is a parser, not a validator: §10.2's OpenAPI-specific rules (unresolved `$ref`, missing `4xx`) still have to be hand-written, perhaps 150 lines.

### Option B — `@stoplight/spectral-cli` plus a YAML parser as npm dev dependencies
Do what §10.2 literally names. A `spectral.yaml` ruleset encodes the five rules; a small Node script does the §10.4 diff.

**Pros**
- §10 names spectral, so the contract document and the implementation would agree.
- Spectral's OpenAPI ruleset is far stricter than anything hand-written, and free — it catches contract errors nobody has thought to test for.
- MJML sets the precedent for an npm **dev** dependency in ARC-21.

**Cons**
- Two new npm dependencies, one of them large, and the drift job grows a Node toolchain it does not currently need.
- The diff logic ends up in JavaScript, away from the Pest tests that assert everything else, so a developer chasing a red build changes language mid-investigation.
- npm advisories now matter to a gate that guards the API contract (SEC-12 already fails the build on high/critical).

### Option C — Keep the line scanner; accept the gap permanently
Write the limits into §10 and stop pretending the rest is coming.

**Pros**
- No new dependency; the hard stop stays a hard stop.
- The gate that exists is real and has already caught drift in #11's own sabotage test.

**Cons**
- **Required request-body fields go unchecked forever**, which is the failure a client meets in production: an endpoint that quietly stops requiring a field, or starts requiring a new one.
- §10 stays a description of a pipeline that does not exist, which is worse than a smaller pipeline honestly described.
- The scanner grows every time M1/M2 need one more thing checked, and regex-over-YAML rots — the escaping bug found while writing #11's literal scanner is the warning.

## Recommendation

**Option A.** The gap that matters is required request-body fields and `$ref` resolution, and both need a real parser rather than a linter. Keeping the work in PHP keeps it beside the tests that already assert the routes, so a red build is investigated in one language. Spectral's ruleset is genuinely better than hand-written checks, but it buys strictness about a document a human wrote carefully, while Option A buys the check on the part the *code* can drift on — and drift is what ENV-28 exists to catch.

If Option A is accepted, `docs/api.md` §10.2's "spectral lint (or equivalent)" should be amended to name the equivalent, so the document and the pipeline agree.

## Consequences if accepted

- `symfony/yaml` is added to `require-dev` and to the ARC-20/ARC-21 approved list in `docs/spec.md` §3.2.
- `Tests\Support\Api\OpenApiContract` loses its line scanner and gains a parsed tree; the public methods and every existing assertion stay as they are.
- §10.2 and the rest of §10.4 land as Pest tests in the `api-docs` group, so the required `api-docs-drift` job gets stricter with no workflow change.
- ARC-21c's "a test asserts `composer.json` requires nothing outside ARC-20 plus ARC-21" — which has **no implementation anywhere in `tests/`** — should be written at the same time, or this ADR widens a list that nothing enforces.

## Consequences if rejected

- §10 is amended to describe what the scanner actually does, and the four unchecked promises are struck from it rather than left as aspiration.
- The `api-docs-drift` gate stays as shipped in #11: paths, methods and security schemes, in both directions, with the unbuilt surface printed.
