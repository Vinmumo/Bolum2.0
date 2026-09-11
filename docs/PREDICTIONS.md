# Predictions from recorded results

Bolum's `results-rates-v1` model produces a transparent baseline from final scores imported through football-data.org. It is designed to make data sufficiency, calculations, and forecast history inspectable. It has not yet demonstrated forecasting accuracy.

## Enable it

```bash
php artisan fixtures:sync
php artisan predictions:use-results
```

The second command checks available history, activates the results provider and pauses sample providers. It preserves HTTP provider settings and existing predictions. Keep the queue worker and scheduler running. A fresh installation still starts with the sample provider so the workflow can be explored without a token.

## Eligible information

A new forecast uses up to 1,000 finished imported matches from the same league within the previous 365 days. Each match must have final goals, a result-observation timestamp and an update timestamp at or before the request cutoff. Future fixtures, unobserved results, local sample scores and other leagues are excluded. The target fixture must be imported, scheduled and upcoming.

There must be at least 20 eligible league matches and 3 matches involving each team. Fewer matches produce a 409 response before any charge. This threshold prevents empty-history predictions; it does not establish statistical reliability. Each team also needs enough matches in the relevant venue for strong estimates. Fewer than five relevant venue matches produces a visible limited-history message. Zero venue matches use league-average rates, provided the overall team minimum is met.

The lookback may include previous-season results if they have been imported. No additional historical data is fetched automatically. The current season alone can be a small sample in September. Result corrections change future calculations, while earlier accepted forecasts retain their original saved inputs.

## Calculation

Every eligible match has weight `0.5 ^ (age_in_days / 90)`. A result 90 days old has half the weight of a result at the cutoff. From those matches calculate weighted league mean home goals `Lh` and away goals `La`, each floored at 0.15.

For a team's relevant venue, smooth each weighted scoring/conceding mean using five equivalent league-average matches:

```text
smoothed_rate = (weighted_goal_sum + 5 × league_rate) / (weight_sum + 5)
```

The home team's home matches provide its home attack rate `Ha` and home conceding rate `Hd`. The away team's away matches provide its away attack rate `Aa` and away conceding rate `Ad`.

```text
home_expected_goals = Ha × Ad / Lh
away_expected_goals = Aa × Hd / La
```

For example, `Lh=1.5`, `La=1.2`, `Ha=1.8`, `Hd=1.0`, `Aa=1.3`, `Ad=1.6` give home 1.92 and away approximately 1.083 expected goals. Inputs are bounded to 0.15–5.0. Other active providers can contribute through their configured weights.

The existing independent Poisson calculator turns the combined home/away rates into home-win/draw/away-win probabilities, scoreline probabilities, goal totals and both-teams-to-score probability. It normalizes the finite score grid. The most likely scoreline is a single possibility, not a promised final score.

These expected goals are inferred from score rates. They are not shot-based xG measurements. Raw recent form is shown as context; the sequence of W/D/L labels is not an additional model feature.

## What is frozen

The request stores expected-goal inputs, league and team sample counts, relevant venue counts, smoothed attack/defence rates, source fixture IDs, cutoff, parameters, match-date range and model version in `fixture_snapshot.results_inputs`. A worker reads that snapshot and never recalculates these inputs from today's database. The source breakdown exposes the same evidence after completion.

The snapshot preserves model inputs, not a complete immutable copy of every upstream response. Bolum does not reconstruct historical knowledge from today's standings. Imported results only become eligible when Bolum records them. There is no fabricated historical performance record.

## Evaluation and next work

Generate forecasts before kickoff, import results afterward, and use Performance → Real data. Only eligible completed pre-kickoff forecasts count. Sample and mixed predictions stay separate; the API retains `external` as the real-data category for compatibility. Different real-data provider configurations currently share that category, so compare equivalent model versions and inputs before interpreting changes in aggregate metrics.

Accuracy is only one measure. Brier score and log loss penalize overconfident errors, and calibration compares stated confidence with observed outcomes. Evaluate across sufficient fixtures using chronological splits. Choose smoothing and decay parameters on training/validation periods, then assess an untouched later period. Avoid optimizing repeatedly against the same displayed results.

Useful future experiments include opponent-adjusted team strengths, low-score dependence, lineups/injuries with observation timestamps, shot-level xG where licensed data exists, and timestamped market benchmarks. Each should earn its place through prospective or properly separated historical evaluation. More inputs alone do not prove an improvement.
