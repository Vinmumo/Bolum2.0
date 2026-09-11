# Architecture and tradeoffs

## Domain boundaries

League has many teams and fixtures. A fixture belongs to a league, a home team, and an away team. Separate foreign keys express the two different team roles. Fixture rows are public shared data; their histories are not publicly exposed.

Users belong to companies through a pivot with an owner/member role. Prediction and credit records belong to exactly one company. `is_admin` is protected from registration mass assignment and grants global catalog maintenance only. Membership middleware rejects nonmembers, company-scoped queries constrain datasets, scoped bindings constrain prediction IDs, and policies decide actions. A membership check alone would not prevent an IDOR if the subsequent query were unscoped.

## Request a prediction

`PredictionService` locks the company, resolves a previous idempotency key, verifies the upcoming fixture and balance, snapshots active providers and fixture inputs, inserts a pending prediction, decrements the balance, and appends a debit. A failure rolls back all these writes. The company/key unique constraint remains the final database safeguard against duplicate requests.

Idempotency keys are company-wide and tied to the requesting user and fixture. Replays never depend on current provider settings. A changed user or fixture returns a conflict. New requests use new keys and create a history rather than overwriting a previous result.

## Process a prediction

`GeneratePrediction` carries scalar company and prediction IDs, resolves the record within the company, and exits unless it is pending. It calls the injected calculator outside a transaction. The calculator combines providers' expected goals using their snapshotted weights and feeds a pure independent Poisson model. Successful completion locks the prediction and changes it only if still pending.

Three exhausted attempts trigger a separate transaction that locks company then prediction, marks pending work failed, restores its credit, and appends a uniquely keyed refund. Duplicate callbacks do nothing after the terminal transition. Completion and failure race on the prediction lock; exactly one terminal state wins. Failed records remain terminal on queue replay to avoid producing a refunded result.

The cache overlap lock reduces duplicate work; durable state checks provide correctness if a message is delivered twice or a cache lock expires. No correctness claim relies solely on a unique-job lock.

## Dispatch gap

Dispatch uses `afterCommit()` so a worker cannot read uncommitted data. There is still a small crash window after commit and before enqueue. The scheduled recovery command requeues pending records older than five minutes. Duplicates are harmless but can consume provider calls. At larger scale, replace this scan with a transactional outbox and explicit delivery tracking.

## Credits

Credits are integers. Both the registration grant and seed grant appear in the ledger. The current balance is a materialized aggregate updated in the same transaction as each ledger entry. The API has no ledger update/delete operation. Database users in a production system should also restrict mutation of audit records; this demo does not claim storage-level immutability.

Free owner top-ups exercise transactional state changes, not payment security. A financial deployment would need authorized payment events, verified webhook signatures, currency and rounding policies, reversals, reconciliation, and stricter operational controls.

## Queries and caching

Fixture lists eager load league, home team, and away team. Feature tests compare query count for two versus twenty fixtures. All lists are bounded and paginated. Indexes support fixture league/kickoff lookups, company fixture history, and pending-work scans. Unique constraints enforce league names, team names within a league, and company-specific request/ledger keys.

The HTTP cache key uses gateway URL and immutable fixture inputs. Shared caching is safe here because only public football statistics are cached. User, company, credit, and authorization data are not cached. If a future provider returns company-specific data, add company scope to both its contract and cache key.

## Scope decisions

A single explicit service orchestrates each multi-write workflow; there is no repository layer duplicating Eloquent. Form Requests handle validation and request authorization, API Resources control output, policies handle model permissions, and middleware establishes company access.

SQLite makes setup easy. Local tests verify transaction rollback and database constraints but do not simulate simultaneous MySQL/PostgreSQL requests. MySQL CI checks engine compatibility, not a full contention workload. Production load and race testing remain follow-up work.

The independent Poisson assumption omits team-strength estimation, correlated scores, injuries, calibration, and model evaluation. The sample input provider is synthetic. Those mathematical limits are separate from the reliability of the API workflow.

## Browser client

Laravel serves the Blade dashboard and static CSS/JavaScript directly. The client uses session cookies with Laravel's origin/CSRF protection and calls the versioned API through Sanctum's stateful middleware. Bearer-token clients remain supported independently. Login regenerates the session; logout invalidates it. No bearer tokens are persisted in browser storage.

The client guards company changes so stale responses cannot render another company's data after a switch. Prediction requests keep an idempotency key for uncertain retries. Pending results are polled while the document is visible. Empty, forbidden, failed, and pending states are distinct.

## Provider observability and fixture imports

`ProviderHttpClient` normalizes and validates a successful response before caching it. Each network attempt and cache read receives a sanitized `provider_calls` row. Cache keys hash the URL, query, and credentials to avoid mixing configurations; credentials themselves are not stored in telemetry. Average latency includes cache reads, whose duration is zero. The scheduler prunes records after 30 days.

`FixtureImporter` consumes a single configured competition from football-data.org, validates the batch, and performs domain writes in one transaction. A shared lock serializes imports. Stable source/external-ID keys make repeated imports safe. Team mappings include competition context to fit Bolum's league-to-team relationship. Upstream state distinguishes scheduled, live, finished, postponed, and cancelled matches. Only finished matches have final scores imported. No rows are deleted simply because an upstream response omits them.

Imports are synchronous through `fixtures:sync` and queued through the admin endpoint. They do not replace the expected-goals gateway contract. Credentials and scheduling are opt-in; synthetic predictions continue to be labeled synthetic even when the fixture itself came from a live feed.

## Evaluation boundary

Each new completed prediction records completion time, calculated per-provider inputs/results, and the sample/mixed/external data category. `PerformanceService` evaluates frozen outcome probabilities against recorded fixture scores. It selects one latest eligible prediction per fixture and data category, using both original and current kickoff boundaries and checking team identities.

This is prospective forecast evaluation, not historical feature reconstruction. Legacy records with missing metadata are excluded. Brier score, log loss, accuracy, and calibration remain separated by data category. Correcting an administrative final score recomputes the report on the next request. Large installations should materialize reports with an explicit result-version strategy.
