# Development guide

## Request flow

`public/index.php` boots the application. `bootstrap/app.php` registers routes, middleware aliases, and JSON error handling. `AppServiceProvider` registers dependencies, gates, and rate limiters.

For prediction requests, follow these components:

1. `routes/api.php` selects the endpoint and middleware.
2. Sanctum authenticates the caller, and company membership middleware establishes access to the selected company.
3. `Api/PredictionController` calls the company policy and copies the authoritative idempotency header into input.
4. `RequestPredictionData::from($request)` validates the HTTP input using Spatie, constructs typed Data, and the controller calls `RequestPredictionAction::execute()`.
5. The Action creates the prediction and credit debit in a transaction, then dispatches the job after commit.
6. `GeneratePrediction` calculates the result and performs a conditional state transition.
7. `PredictionResource` defines the public response.

## Code organization

- **ListRequest** remains a Form Request for read-only catalog filters. Mutation validation belongs to Data classes.
- **Policies and gates** enforce permissions. Global catalog administration uses a gate; company and prediction actions use policies.
- **API controllers** live in `Http/Controllers/Api`, map HTTP input to Data, delegate operations and return API Resources. `SessionController` owns the browser session lifecycle.
- **Data objects** extend Spatie `Data`, declare typed properties and `rules()`, and validate when constructed with `::from($request)`. Use `::validateAndCreate($array)` for untrusted arrays; constructors and default `::from($array)` do not guarantee validation.
- **Actions** expose `execute()` for an operation and own multi-write transactions.
- **Services** provide calculations, reports and external integrations.
- **Jobs** execute deferred work with bounded retries and idempotent state transitions.
- **API Resources** control serialized fields and relationships.
- **Factories and seeders** provide test records and local sample data.

`FootballDataProvider` is bound to `SampleFootballProvider` in the service provider. Constructor injection makes this dependency replaceable. The pure `PoissonCalculator` has no HTTP or database dependencies.

## Extending the API

When adding a field or endpoint, update the migration, model assignment rules and casts, Data validation rules/properties, Action behavior, resource representation, and relevant tests. Keep company-owned queries scoped through the selected company's relationship. Apply the same isolation to jobs, exports, and caches.

For partial fixture updates, validate the effective values of both changed and unchanged fields. Eager load relationships serialized in list responses and keep pagination bounded. Avoid network calls inside database transactions.

New provider adapters should validate upstream data, enforce timeouts, and expose safe failures. Keep credentials in server configuration. Do not silently substitute synthetic data when a configured provider fails.

## Testing

```bash
php artisan test
vendor/bin/pint --test
composer validate --strict
```

Feature tests cover authentication, permissions, validation, tenant isolation, pagination, query counts, transaction rollback, and idempotency. HTTP fakes verify provider retries and caching without network access. Queue workflow tests run the database worker to verify completion and exhausted-job refunds. Unit tests check the calculation's invariants independently of Laravel.

Local SQLite tests do not establish production row-lock behavior. Use the production database engine for concurrency and contention testing. The CI matrix includes SQLite and MySQL for engine compatibility.

## Operational behavior

A prediction request atomically creates a pending record, debits the balance, and appends a ledger entry. Repeating its idempotency key returns the existing record; a changed fixture or actor conflicts.

Workers use immutable input snapshots and perform provider calls outside database locks. Completion and exhausted failure only transition pending records. Failure refunds the debit once. A refunded prediction remains terminal; a new request requires a new key.

Dispatch after commit prevents workers from reading uncommitted records but leaves a small commit-to-enqueue gap. The recovery command requeues stale pending records. A transactional outbox is a possible extension for stronger delivery tracking.

See [ARCHITECTURE.md](ARCHITECTURE.md) for transaction boundaries and scope decisions, and [API.md](API.md) for endpoint contracts.

## Framework references

- [Request lifecycle](https://laravel.com/docs/13.x/lifecycle)
- [Service container](https://laravel.com/docs/13.x/container)
- [Eloquent relationships](https://laravel.com/docs/13.x/eloquent-relationships)
- [Validation](https://laravel.com/docs/13.x/validation)
- [Authorization](https://laravel.com/docs/13.x/authorization)
- [Sanctum](https://laravel.com/docs/13.x/sanctum)
- [Database transactions](https://laravel.com/docs/13.x/database)
- [Queues](https://laravel.com/docs/13.x/queues)
- [HTTP tests](https://laravel.com/docs/13.x/http-tests)

## Dashboard development

The dashboard template is `resources/views/dashboard.blade.php`, with static assets in `public/assets`. It needs no Vite build to run. The JavaScript uses same-origin cookie authentication, escapes server-provided text before HTML insertion, and uses company-scoped API routes for private data.

Run browser tests with `npm ci`, `npx playwright install chromium`, and `npm run test:browser`. The Playwright configuration creates a new temporary SQLite database, seeds it, and starts an isolated server. Browser tests cover responsive navigation, cross-origin request rejection, sign-in/logout, a real queued prediction, and role-based controls.

The new backend entry points are `FixtureImporter` (validated idempotent imports), `ProviderHttpClient` (safe telemetry and caching), `PerformanceService` (chronologically eligible evaluation), and `SessionController` (browser sessions). Preserve separate sample/external categories and never backdate production predictions to make historical metrics look populated.

Use `demo:refresh` for fresh sample fixtures. It preserves existing history/results and balances, and is unavailable in production.

## Spatie Data boundaries

Authorization runs in controller Gates/Policies or route middleware before Data creation. For prediction and credit writes, the controller copies `Idempotency-Key` from the header into the request, replacing any body value. The Data class validates it; Actions still own replay checks and transactional writes.

`Optional` marks omitted update fields. `toArray()` omits those fields and preserves explicit false and zero. Current fixture/provider optional fields reject null. A future clearable field should use `Optional|Type|null` plus a nullable rule. The shared `ValidatesFixtureTeams::withValidator()` hook checks effective team/league values using the bound fixture on updates; array validation outside HTTP needs equivalent existing-fixture context for partial updates. Provider uniqueness similarly uses the bound provider when excluding the updated record.

Resources still define API responses. Input Data objects containing passwords must never be returned as responses. `ListRequest` and some report-controller validation remain for read-only filters.

See [Spatie request validation](https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/request-to-data-object) and [optional properties](https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/optional-properties).
