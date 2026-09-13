# Football data integrations

Provider information checked on 11 September 2026. The football-data.org fixture importer, crest display, league standings, recent form, and local results-based prediction baseline are implemented. Other providers below remain possible extensions; no additional paid integration has been enabled.

## Best next steps

1. Collect more recorded results and prospective forecasts to evaluate the new baseline, especially when early-season venue samples are small. See [Prediction model](PREDICTIONS.md).
2. Compare calibrated models against the baseline using chronological validation and saved input versions.
3. Add another source when it supplies a specific missing input, such as injuries or lineups. Check actual Premier League season and field coverage using that account before building around it.

## Provider comparison

| Source | Useful Bolum additions | Access considerations |
| --- | --- | --- |
| football-data.org | Standings, prior results, team profiles, more supported leagues; fixtures, crests, tables and prior results already connected | Free plan lists 12 competitions, league tables, delayed scores/schedules, and 10 calls/minute. Richer/historical data varies by package. [Pricing](https://www.football-data.org/pricing), [coverage](https://www.football-data.org/coverage). |
| API-Football | Team statistics, injuries, confirmed lineups, match events, head-to-head context, and an external prediction benchmark | Free plan lists 100 requests/day and restricted seasons. Pro lists $19/month and 7,500 requests/day. Endpoint availability alone does not guarantee that every league/season has every field. [Plans](https://www.api-football.com/pricing). |
| Sportmonks | Rich fixture statistics, xG, lineups, injury/suspension context | Starter is advertised from €29/month for five selected leagues. Its current sales page says full data access, while the FAQ still describes xG and other products as add-ons. Verify Premier League and xG entitlement in the intended account before choosing it. [Plans](https://www.sportmonks.com/football-api/plans-pricing/), [FAQ](https://docs.sportmonks.com/v3/faq-1). |
| The Odds API | Timestamped bookmaker probabilities alongside our forecasts, plus over/under market comparisons | Free starter lists 500 credits/month and excludes historical odds. Requests can cost multiple credits depending on markets and regions. Use cached snapshots and a planned polling budget. [Plans](https://the-odds-api.com/), [quota calculation](https://the-odds-api.com/liveapi/guides/v4/). |
| StatsBomb open data | Selected historical event-level datasets for model experiments and shot-based analysis | An open dataset, not a live Premier League feed. Competition and season availability is explicit in its data files; observe the repository's attribution and usage requirements. [Official repository](https://github.com/hudl/open-data). |

## Implemented context and possible additions

- **Standings panel (implemented):** position, played, wins/draws/losses, goals and points, with season and refresh time.
- **Form comparison (implemented):** recent completed matches and home/away scoring rates. Mark small samples and distinguish raw form from opponent-adjusted strength.
- **Team news panel:** confirmed versus expected lineups, injuries, and observation time. Do not present a predicted lineup as confirmed.
- **Model versus market:** convert decimal odds to implied probabilities and normalize for the displayed bookmaker margin; compare snapshots available at the forecast cutoff. A probability difference does not establish a profitable strategy.
- **Forecast provenance (implemented for results model):** show which source and model version produced each input, when the data was observed, and what was missing.

## Integration rules for this codebase

Keep credentials server-side. Reuse bounded HTTP timeouts, caching, sanitized telemetry, and queued refreshes. Add a shared provider quota budget before frequent polling, rather than multiplying calls per browser user.

Keep source IDs separate from Bolum IDs. The same club or fixture has different IDs across providers; map and verify competition, season, teams, and kickoff. Do not rely on fuzzy name matching alone for production joins.

Snapshot forecast inputs and observation timestamps before kickoff, then evaluate stored forecasts prospectively. Keep sample results distinct from models built with real data. Richer data and a more expensive subscription do not establish predictive accuracy on their own.

For crests, the importer accepts HTTPS image URLs only from the configured source's public crest CDN. The browser uses image elements, reserves space for the artwork, and keeps initials when a crest cannot load. Optional missing crest metadata does not prevent importing fixtures or erase previously imported valid artwork.

## TheSportsDB club profiles

`GET /api/v1/teams/{team}/profile` enriches an imported club with stadium, location, founding year and a short description. In the dashboard, open a match and choose either club in **Club guide**. Lookups happen only on request and are cached for one hour, including unmatched searches. Source attribution and retrieval time are returned. The fixture catalog, crests and prediction inputs remain owned by their existing sources.

The server calls the fixed HTTPS v1 `searchteams.php` endpoint with the club name and validates an unambiguous soccer/name/country match. Bolum IDs are not reused as TheSportsDB IDs. Unknown/ambiguous clubs return 404; malformed data or upstream/configuration problems return a sanitized 503 and can be retried. A cache lock limits duplicate concurrent lookups. The existing HTTP client applies timeouts, bounded retries and sanitized telemetry; the public route shares the 10 football reads/minute/IP limiter with standings.

`SPORTSDB_API_KEY=123` is the documented public free key; no personal signup is needed for the default lookup. Set a personal key if needed, or blank to disable, then clear configuration and restart long-running processes. Keys remain on the server. The upstream free tier currently permits 30 requests/minute; per-IP limits do not guarantee aggregate traffic stays below that limit, so high-traffic deployments need a shared upstream budget or an appropriate plan. See the [official documentation](https://www.thesportsdb.com/documentation).

Club metadata is third-party content, can be missing or inaccurate, and is not forecasting evidence. It is displayed as escaped text with a source link. This integration does not import historical forecasts or silently replace football-data.org fixtures.
