# API guide

Base URL: `http://127.0.0.1:8000/api/v1`. Send `Accept: application/json` and JSON bodies with `Content-Type: application/json`. Protected endpoints require `Authorization: Bearer <token>`.

## Endpoints

| Method | Path | Access / behavior |
| --- | --- | --- |
| POST | `/auth/register` | Public; creates user, company owner membership, 10 demo credits, token |
| POST | `/auth/login` | Public; returns user, companies, and 8-hour token |
| GET | `/auth/me` | Authenticated user and memberships |
| POST | `/auth/logout` | Revokes current token; 204 |
| GET | `/leagues` | Public paginated list |
| GET | `/leagues/{league}/standings` | Public imported league table; cached 10 minutes, 10 reads/minute/IP |
| POST | `/leagues` | Admin; name and country |
| GET | `/teams` | Public paginated list |
| GET | `/teams/{team}/profile` | Public imported-club information from TheSportsDB; cached one hour, 10 football reads/minute/IP; 404 unmatched, 503 unavailable |
| POST | `/teams` | Admin; name and league_id |
| GET | `/fixtures` | Public paginated list; optional league_id and upcoming=1 |
| GET | `/fixtures/{fixture}` | Public detail plus recent `form` |
| POST | `/fixtures` | Admin creation |
| PUT/PATCH | `/fixtures/{fixture}` | Admin update; omitted fields retain existing values |
| DELETE | `/fixtures/{fixture}` | Admin; 409 if prediction history exists |
| GET | `/providers` | Admin paginated list |
| POST | `/providers` | Admin creation |
| PUT | `/providers/{provider}` | Admin configuration update |
| GET | `/companies/{company}/credits` | Member; ledger plus balance and unit |
| POST | `/companies/{company}/credits/top-ups` | Owner; free demo grant |
| GET | `/companies/{company}/predictions` | Member; private paginated history |
| GET | `/companies/{company}/predictions/{prediction}` | Member; scoped detail |
| POST | `/companies/{company}/fixtures/{fixture}/predictions` | Member; requests generation |
| GET | `/companies/{company}/fixtures/{fixture}/predictions` | Member; fixture history |
| GET | `/companies/{company}/fixtures/{fixture}/predictions/latest` | Member; latest completed prediction, or 404 |

Lists accept `page` and `per_page` (1–100, default 15), and return Laravel `data`, `links`, and `meta`. League/team/provider management is intentionally limited to the operations listed above.

## Payloads

Registration:

```json
{"name":"Alex","email":"alex@example.test","password":"strong-password","password_confirmation":"strong-password","company_name":"Alex Analytics"}
```

Login:

```json
{"email":"admin@bolum.test","password":"password123"}
```

Read the returned `data.token` and `data.companies[].id`; do not assume IDs from another installation. Passwords and token hashes are not serialized.

Fixture creation:

```json
{"league_id":1,"home_team_id":1,"away_team_id":2,"kickoff_at":"2030-06-01T18:00:00Z","is_finished":false}
```

Both teams must belong to the selected league and differ. PATCH validation merges omitted values from the existing fixture before checking this rule.

Provider:

```json
{"name":"Sample Football","driver":"sample","weight":1,"is_active":true}
```

Drivers: `sample`, `http`, or `results`. Weight must be greater than zero and at most 100. Provider configuration never contains an API key; gateway credentials come from server environment configuration.

Prediction request: empty object `{}`, plus header `Idempotency-Key: demo-prediction-001`. The key must contain 1–100 ASCII letters, digits, underscores, or hyphens. The fixture must be upcoming. One credit is debited when the pending prediction is created; the response is `202`. Repeating the same company/user/fixture/key returns the original record with `200` and no extra charge. Reusing the key with another fixture or user returns `409`. Keys are scoped to a company.

A completed result contains probabilities as fractions, expected goals, the most likely score, and a model identifier. `GET .../latest` selects the most recently requested prediction that has completed, using its ID, not its worker completion time.

Top-up: `{"amount":20}` plus `Idempotency-Key: demo-topup-001`. Amount is an integer from 1 to 10,000 and the balance is capped at 1,000,000. A replay returns the original ledger entry; changed amounts or actors conflict. Prediction and top-up keys have separate namespaces. Balances and ledger values use integer demo credits.

## Errors

| Status | Meaning |
| --- | --- |
| 401 | Missing, expired, or revoked authentication |
| 403 | Valid user without membership, ownership, or admin permission |
| 404 | Missing resource or prediction ID outside the selected company |
| 409 | Conflicting idempotency key, insufficient credits, unavailable providers, or invalid fixture state |
| 422 | Validation error, including incorrect login credentials |
| 429 | Authentication, generation, or standings rate limit |
| 503 | Standings source unavailable or invalid response |

Validation errors have `message` and an `errors` object keyed by field. Authentication routes allow 10 attempts per minute per IP. Generation allows 20 requests per minute per user, including replays.

Failed asynchronous work is represented by prediction `status: "failed"` and a safe error string. Its debit is refunded exactly once. A worker failure is not returned as an HTTP failure on the earlier accepted request.

## Browser sessions

The dashboard at `/` uses these same-origin routes outside the `/api/v1` prefix:

| Method | Path | Behavior |
| --- | --- | --- |
| POST | `/session/login` | Email/password login; regenerates session and returns a fresh CSRF token |
| POST | `/session/register` | Same registration payload; creates workspace and logs in without issuing a bearer token |
| POST | `/session/logout` | Invalidates the browser session and regenerates CSRF token |

Session writes use Laravel request-forgery protection. The browser sends `X-CSRF-TOKEN` from the page metadata. Laravel 13 also recognizes trustworthy same-origin Fetch Metadata. A cross-origin write with no valid token is rejected. Configure `SANCTUM_STATEFUL_DOMAINS` for your deployed frontend origin. Token clients still use `/auth/login` and `/auth/logout`; session clients use `/session/logout`.

## Source breakdown and goal probabilities

Completed prediction results now include:

- `sources`: provider ID, name, driver, configured weight, observed expected goals, and that source's outcome probabilities.
- `data_quality`: `sample`, `mixed`, or `external`, based on the participating drivers.
- `totals`: `over_1_5`, `over_2_5`, `over_3_5`, and `both_teams_score`, expressed as fractions.
- `scorelines`: five most likely home/away scores with their probabilities.

The prediction resource includes `completed_at`. These fields are not fabricated for older records that lack them.

## Fixture data and outcomes

Fixtures now expose `source`, `season`, `matchday`, `status`, and `score: {home, away}`. Status is scheduled/live/finished/postponed/cancelled. Only upcoming scheduled fixtures accept prediction requests. List filters additionally support `q` (team name, max 100 characters), `status`, and a four-digit `season`.

`PUT /fixtures/{fixture}/result` is admin-only. Send `{"home_goals":2,"away_goals":1}`. Goals must be integers from 0 to 100. Future, cancelled, and postponed fixtures cannot be finalized. The endpoint records the score, completion state, and result timestamp atomically. Administrators may correct a recorded score; performance reports reflect the current score.

`POST /fixtures/sync` is admin-only and returns `202` with a synchronization record. It requires `FOOTBALL_DATA_TOKEN` on the server. A queue worker imports the configured competition from football-data.org. The endpoint returns `409` if credentials have not been configured. Importing fixtures does not itself generate or charge for predictions.

## Provider monitoring

`GET /providers/usage?days=1` is admin-only; days must be 1–30. The response contains:

- `data`: per-source request/cache-read count, cache hits, failures, and average duration.
- `recent`: the latest 30 sanitized call records in that interval.
- `syncs`: the five most recent queued synchronization records.

Each HTTP retry has its own call record. Cached reads have duration zero and no HTTP status. Sample predictions do not produce external request records. No credentials or response bodies are returned.

## Performance evaluation

`GET /companies/{company}/performance?quality=external` requires company membership. Supported quality filters are `external` (default), `sample`, and `mixed`.

The response includes eligible fixture count, outcome accuracy, multiclass Brier score, log loss, five calibration buckets, and up to 20 recent evaluated predictions. Brier score is the sum of squared errors across home/draw/away, averaged over fixtures (0 is best, 2 is worst). Log loss uses the probability assigned to the actual outcome (lower is better).

Within a selected data category, only the latest eligible prediction per fixture is counted. Request and completion timestamps must precede both the original snapshot kickoff and the current kickoff; team identities must still match the snapshot. Fixtures need a final score. Records missing completion/category metadata are excluded. An empty report has count zero and null aggregate scores, rather than reporting zero-error accuracy.

## Standings, form and results-based forecasts

`GET /leagues/{league}/standings` returns `{data: {league_id, league_name, season, source, fetched_at, rows}}`. Each row contains `position`, `team: {external_id, name, crest_url}`, `played`, `won`, `drawn`, `lost`, `goals_for`, `goals_against`, `goal_difference`, and `points`. Points are preserved from the provider, including deductions. `season` is the starting year. The configured season applies when set; otherwise the provider's current season is used. `fetched_at` is the time Bolum retrieved the table, not the provider's last score update. Cache hits keep that timestamp. Non-imported leagues return 404; missing credentials and upstream failures return a safe 503. Cup group tables are not supported.

`GET /fixtures/{fixture}` additionally returns top-level `form: {cutoff_at, source, order, home, away}`. Each team has up to five eligible matches, newest first, with `fixture_id`, `kickoff_at`, `venue`, `opponent`, `goals_for`, `goals_against`, and `outcome` (W/D/L). Scores and outcomes follow that team's perspective. Eligibility requires same-league imported final results, kickoff within the last year, and result observation/update no later than the earlier of now and the viewed fixture's kickoff. Local fixtures have empty form. An empty history is not evidence of zero prior games.

For an active `results` provider, prediction requests need 20 eligible league matches and 3 per team. Otherwise they return 409 without a prediction or debit. Accepted requests store `fixture_snapshot.results_inputs`; a completed result repeats those inputs under the matching source's `evidence`. This includes rates, match counts, fixture IDs, observation cutoff, model version, smoothing/decay parameters and limited-sample status. `data_quality: external` includes real-result models and configured HTTP inputs; `sample` and `mixed` remain separate. No API token is required for calculating from already imported results.


## User profiles and operations

- `GET /profile`: authenticated user's `id`, `name`, `email`, `avatar`, `created_at` and `is_admin`. No user ID selector or secrets are returned.
- `PATCH /profile`: authenticated account update with required `name` (nonblank, at most 100 characters) and `avatar` (`football`, `captain`, `keeper`, `trophy`, `stadium`, `lightning`). Email and administrative fields are not writable through this endpoint.
- `PUT /profile/password`: requires `current_password`, a different `password` of at least ten characters, and matching `password_confirmation`. A successful update revokes all of that user's API tokens, deletes their database-backed sessions, resets the remember token and signs out the current browser session. The response tells the caller to sign in again. With other session drivers, Sanctum's password-hash session check rejects previously initialized sessions on their next authenticated API request.
- `GET /admin/overview`: global catalog administrator only. Returns active provider, pending/stale prediction, recent failed prediction, recent provider error and imported fixture counts, plus sync/fixture update times. It does not expose individual companies' predictions or queue payloads.

Account writes share a limit of ten requests/minute per authenticated user, separate from the login IP limit. All browser mutations use the existing session/CSRF protection. Server validation errors return `422` with field-keyed `errors`; the dashboard shows these alongside the corresponding inputs. Avatar choices are local presets, with no upload or external URL support.


## Fixture filter options and gameweek record

`GET /fixtures` additionally supports `matchday` (integer 1–100) alongside `league_id`, `season`, `q` and status filters. `GET /fixtures/filter-options` returns up to 2,000 grouped `{league_id, season, matchday, fixtures, finished}` entries derived from the catalog. The dashboard uses these to populate season/gameweek controls. These options do not generate forecasts or fetch another provider.

`GET /companies/{company}/track-record?league_id=2&season=2026&matchday=3&quality=external` requires both workspace membership and global catalog administrator permission. League, season and matchday are required; quality accepts `external`, `sample` or `mixed` (default external). The report covers at most 100 fixtures per round. It returns fixture/finished/evaluated/correct/missing-forecast counts, nullable accuracy and rows with teams, kickoff, actual score, saved forecast, and nullable correctness. Forecasts contain their saved ID/time, selected outcome, probabilities and optional most-likely score.

Only the latest eligible completed pre-kickoff forecast in the selected workspace/category is considered for each fixture. Team identities must still match and request/completion must precede both original and current kickoff. Missing and malformed probability records are excluded. A missing forecast has `forecast: null`; an unfinished or unevaluated fixture has `correct: null`. Accuracy is `correct / evaluated`, or null when no forecasts can be evaluated. The endpoint is read-only and never retroactively generates predictions.

The browser uses “workspace” for the existing Company relationship. API paths and ownership semantics remain unchanged.

For guided local requests and an importable collection, see [Postman](POSTMAN.md). See [Permissions](PERMISSIONS.md) for role boundaries.
