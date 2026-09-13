# Test Bolum with Postman

## 1. Start the app and worker

For your existing installation, keep `.env` and the application key:

```text
php artisan config:clear
php artisan migrate
php artisan serve
```

In another terminal, from the same project directory:

```text
php artisan queue:work --tries=3 --timeout=60
```

A fresh installation uses `composer setup` first. If you are using local sample fixtures and they have already passed, run `php artisan demo:refresh`. Real fixture imports require the football-data.org settings in the README. Do not reset your database to test the API.

## 2. Import and select the environment

Use Postman Desktop, or the web app with its Desktop Agent so requests can reach your local machine. Select **Import** and choose both files from `docs/postman/`:

- `Bolum.postman_collection.json`
- `Bolum-local.postman_environment.json`

Select **Bolum Local** as the active environment. Its `base_url` is `http://127.0.0.1:8000`, without `/api/v1` or a trailing slash. If Artisan uses another port, change this value. Postman's [import guide](https://learning.postman.com/docs/getting-started/importing-and-exporting/importing-data/) explains the import controls.

Start by sending the numbered folders' requests **one at a time**. The optional integrations need configuration; the isolation check creates a local test account. This is a guided collection, not an unconditional load-test script.

## 3. Login and understand headers

Open **Login as owner/admin**, then **Send**:

```http
POST {{base_url}}/api/v1/auth/login
Accept: application/json
Content-Type: application/json
```

```json
{"email":"admin@bolum.test","password":"password123"}
```

Expect **200** with `data.token`, `data.user`, and `data.companies`. The sample credentials work only if those local accounts were seeded and their passwords have not changed. Otherwise edit `email` and `password` in the environment to use your account.

The request's **Scripts → Post-response** script saves the token and the first membership's `company_id` to the selected environment. The collection's Authorization setting is **Bearer Token**, value `{{token}}`; protected requests inherit it. Public requests use No Auth. See [Postman collection configuration](https://learning.postman.com/docs/collections/use-collections/create-collections).

If building a request manually, select **Body → raw → JSON** for POST bodies. Use **Authorization → Bearer Token**, and paste the token without another `Bearer` prefix. Do not paste the entire login response. Tokens expire after eight hours. Keep populated environments private and do not export real credentials/tokens into the repository.

Send **Who am I?**: `GET {{base_url}}/api/v1/auth/me`. Expect 200 and your user/memberships. No login means 401. Bolum uses `/companies/` in API paths even though the interface calls these workspaces.

## 4. Discover real IDs before building URLs

Send **Leagues — capture preferred league**. It chooses an imported league if available, otherwise the sample league, and saves `league_id`.

Send **Upcoming fixtures — capture IDs**. It saves `fixture_id`, `team_id`, a different `other_fixture_id` if one exists, and available season/matchday values. If there are no upcoming fixtures, update/import the catalog or choose another league. Never assume that ID 1 means Arsenal or your workspace on every installation.

Try these GETs:

```text
{{base_url}}/api/v1/fixtures/{{fixture_id}}
{{base_url}}/api/v1/fixtures?q=Arsenal&league_id={{league_id}}
{{base_url}}/api/v1/fixtures?league_id={{league_id}}&season={{season}}&matchday={{matchday}}
```

Edit filters in **Params**. Sample fixtures may have no season/matchday; skip that filter request until you import real fixtures. Valid searches can return an empty `data` array with 200. Invalid filter values return 422. Fixture details include recent `form` when prior results exist.

## 5. Generate a prediction and test a retry

Send **Balance before prediction** and note the top-level `balance` and ledger entries.

Send **Generate prediction — creates a fresh key**:

```http
POST {{base_url}}/api/v1/companies/{{company_id}}/fixtures/{{fixture_id}}/predictions
Idempotency-Key: {{prediction_key}}
```

Body: `{}`. A pre-request script creates a UUID key. Expect **202** and `data.status: pending` with the default database queue. The response script saves `prediction_id`. This request creates a **new operation on every Send**; it can spend another credit each time.

Now use **Replay SAME prediction — no second charge**. It reuses the saved key. Expect **200**, the same prediction ID, and no second debit. Do not change the key when testing a network retry. The collection checks the response ID in **Test Results**.

Send **Read saved prediction**, and repeat that GET until the state is `completed` or `failed`. The worker terminal should show the job. A completed response contains probabilities, expected goals, scorelines and sources. A failed job refunds its credit once after retries are exhausted. Read the balance/ledger again to verify.

A **409** during creation is a business-state response, not a Postman connection failure. Read its `message`: the fixture may have started, credits may be insufficient, or the selected provider may lack inputs. The results provider requires an upcoming imported fixture, at least 20 prior league results and three per team. A sample fixture is not eligible for that provider. Check active providers before choosing the fixture; do not change provider configuration just to make an invalid request appear successful.

Owner top-ups accept `{"amount":5}` and their own Idempotency-Key. The collection reuses that key until you clear `topup_key`: first creation is 201; identical replay is 200. These are free demo credits.

## 6. Check permissions and validation

| Request | Expected result and reason |
| --- | --- |
| GET `/auth/me` with No Auth | 401: no token. The collection disables cookie use so a browser session cannot mask this check. |
| Prediction POST without Idempotency-Key | 422: required field missing. |
| Prediction POST for a different existing fixture with the previous key | 409: key belongs to another operation. Run successful generation first. |
| Login as `member@bolum.test` | Saves a separate `member_token`; keeps the original admin token. |
| GET `/providers` using `member_token` | 403: member is not a catalog administrator. |
| POST workspace top-up using `member_token` | 403: member is not its owner. |
| Register outsider, then read original workspace with `outsider_token` | 403: outsider belongs to another workspace. Registration creates a real local user/workspace. |

Also try a nonexistent fixture ID for 404. For a scoped prediction 404, use a real prediction ID belonging to another company in a URL whose company you are authorized to access; an unrelated random ID only tests “not found,” not isolation.

If you get 419 while testing bearer authentication, remove manually added `Origin`/`Referer` headers and cookies; use the API login endpoint instead of browser session login. For 429, wait for the rate-limit window. Auth is limited to 10/minute/IP; prediction requests to 20/minute/user. Sending an entire collection repeatedly can hit these limits.

## 7. Try integrations and administration

- **New:** `GET /teams/{{team_id}}/profile` loads TheSportsDB club information. Choose an imported team. The public free key works by default; blank `SPORTSDB_API_KEY` disables access. 404 means no verified match/local sample team; 503 means unavailable configuration/upstream. No credits are charged.
- `GET /leagues/{{league_id}}/standings` needs a configured football-data.org token and an imported league.
- `GET /admin/overview` and `/providers/usage` require an admin token.
- `GET /companies/{{company_id}}/performance?quality=external` returns metrics; an empty evaluated set is valid.
- Track record also needs admin status, membership, league, season and matchday. Select a past gameweek. Missing pre-kickoff predictions remain missing.

Finish with **Logout current owner/admin token** (204). Other tokens issued during testing remain separate; logging out one token does not revoke every user's token. The [API guide](API.md) lists further endpoints, and [Permissions](PERMISSIONS.md) explains their boundaries.
