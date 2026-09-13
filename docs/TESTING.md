# How Bolum's checks work

## The automated layers

| Check | How it works | What it establishes |
| --- | --- | --- |
| PHPUnit unit tests | Call pure calculation functions with known inputs; compare probabilities, sums and edge cases. | Mathematical behavior without a web server or provider credentials. |
| PHPUnit feature tests | Boot Laravel, migrate a disposable database, arrange records, send HTTP requests through Laravel's test client, assert responses and stored state. | Validation, policies, isolation, idempotency, credits, imports and metrics work together. |
| Queue integration tests | Store a real database job, run `queue:work --once`, inspect prediction/ledger/job state. Force HTTP failures and exhaust retries to check refunds. | Background processing works beyond merely checking that dispatch was requested. |
| Playwright browser tests | Start a separate app server and temporary SQLite file, then drive Chromium through actual UI actions. Some external responses are intercepted. | Loading, errors, forms, navigation and authentication behave in the browser. |
| Fresh installation | On Ubuntu and Windows, install locked dependencies, create a new SQLite database/key, migrate/seed, and repeat setup while checking the environment hash stays unchanged. | A new user can install; reruns do not replace the environment/key. This job does not test a Windows MySQL server. |
| Composer/Pint | Validate dependency metadata/platform requirements and PHP formatting. | Dependency/configuration or style problems are detected; this is not behavioral coverage. |

The SQLite/MySQL matrix runs the **same PHP suite twice**, using each database engine. It is not twice as many distinct scenarios. MySQL starts in a disposable service with `innodb_default_row_format=COMPACT`; Bolum's explicit DYNAMIC table configuration must still allow migrations to succeed.

Most feature tests use `RefreshDatabase` for clean schema/test transactions. Queue workflow tests use `DatabaseMigrations` so actual commit/worker behavior is exercised. `Http::fake()` supplies controlled successes, invalid payloads and upstream errors; `Queue::fake()` isolates request tests from execution. Assertions inspect both response contracts and side effects, such as one debit after two identical requests.

For example, `PredictionTest::test_request_debits_once_and_queues_after_commit()` seeds a 10-credit workspace, authenticates a member, sends the same key twice, checks 202 then 200 with the same ID, and verifies nine credits, one prediction and one queued job. `QueueWorkflowTest` separately proves a real worker can finish a committed request and refund an exhausted failure.

Club-profile tests fake TheSportsDB responses and check matching by name, country and sport, caching, attribution, malformed input and safe retries. A browser test checks on-demand loading and retry, and verifies provider text is rendered as text rather than executable HTML.

## Commands

```text
php artisan test
php artisan test --filter=PredictionTest
php artisan test --filter=QueueWorkflowTest
php artisan test --filter=ClubProfileTest
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
```

For browser checks, install the development dependencies once:

```text
npm ci
npx playwright install chromium
npm run test:browser
```

`phpunit.xml` defaults to in-memory SQLite and fake-friendly configuration. Browser tests create their own temporary database. If overriding environment values to test MySQL, use a **disposable database**, because migration-based tests recreate tables.

## Why keep Windows?

It is optional if the project only promises Linux support. Bolum provides Windows/PowerShell setup instructions, so a small Windows installation check is useful. The failed run exposed a real platform difference: Windows PHP lacked `fileinfo`, required by the locked Flysystem packages. Linux already had it. The workflow now explicitly enables `fileinfo` in all PHP jobs using setup-php's [extensions input](https://github.com/shivammathur/setup-php#heavy_plus_sign-php-extension-support).

The failure happened during dependency installation, before migrations. It does not require a Composer dependency update or an index-length workaround. Do not hide it using `--ignore-platform-reqs`. Keep the Windows job small rather than repeating the full browser/database matrix there.

Passing tests demonstrate the scenarios asserted. They do not establish live provider uptime, prediction profitability, complete security coverage or production-scale concurrency. A new workflow edit is not a passed remote run until Actions executes the pushed commit.

## Action refactor regressions

`ActionsTest` exercises registration both through the API and browser endpoints, and forces a welcome-ledger failure to verify the shared Action rolls back the user, company and membership together. It also verifies DTO mapping preserves explicit false and zero values, retains omitted update fields, and rejects explicit null where the HTTP contract forbids it. Existing prediction tests call `RequestPredictionAction` directly for transaction/evaluation setup, while HTTP and browser tests continue to exercise the public routes.
