# Predictions from recorded results

Bolum's `results-ratings-v2` model forecasts from final scores imported through football-data.org. It fits opponent-adjusted attack and defence ratings, and the calculator applies a Dixon-Coles low-score adjustment. Data sufficiency, calculations and forecast history stay inspectable. A historical backtest is summarized [below](#backtest); prospective results in Performance remain the measure that counts.

## Enable it

```bash
php artisan fixtures:sync
php artisan predictions:use-results
```

The second command checks available history, activates the results provider and pauses sample providers. It preserves HTTP provider settings and existing predictions. Keep the queue worker and scheduler running. A fresh installation still starts with the sample provider so the workflow can be explored without a token.

## Eligible information

A new forecast uses up to 1,000 finished imported matches from the same league within the previous 365 days. Each match must have final goals, a result-observation timestamp and an update timestamp at or before the request cutoff. Future fixtures, unobserved results, local sample scores and other leagues are excluded. The target fixture must be imported, scheduled and upcoming.

There must be at least 20 eligible league matches and 3 matches involving each team. Fewer matches produce a 409 response before any charge. This threshold prevents empty-history predictions; it does not establish statistical reliability. Fewer than five matches for either team produces a visible limited-history message; prior smoothing keeps those ratings close to league average.

The lookback may include previous-season results if they have been imported. No additional historical data is fetched automatically. The current season alone can be a small sample in September. Result corrections change future calculations, while earlier accepted forecasts retain their original saved inputs.

## Calculation

Every eligible match has weight `0.5 ^ (age_in_days / 90)`. A result 90 days old has half the weight of a result at the cutoff. From those matches calculate weighted league mean home goals `Lh` and away goals `La`, each floored at 0.15.

Each team gets an attack rating `a` and a defence rating `d` (1.00 is league average; a higher `a` scores more, a lower `d` concedes less). The model is:

```text
home goals ~ Poisson(Lh × a[home] × d[away])
away goals ~ Poisson(La × a[away] × d[home])
```

Ratings are the weighted maximum-likelihood fit across **all** eligible league matches, found by alternating closed-form updates until no rating changes by more than 1e-7 (at most 200 iterations):

```text
a[t] = (goals scored by t + prior) / (goals t would score against its opponents' defences at average attack + prior)
d[t] = (goals conceded by t + prior) / (goals t would concede to its opponents' attacks at average defence + prior)
prior = 5 × (Lh + La) / 2
```

Because each rating is measured against the opponents actually faced, goals against a strong defence raise attack more than the same goals against a weak one. The prior acts as five league-average matches per team, pulling small samples toward 1.00. Attack ratings are normalized to a geometric mean of 1. Expected goals are bounded to 0.15–5.0. Other active providers can contribute through their configured weights.

### Low-score adjustment (Dixon-Coles)

Independent Poisson counts under-predict 0-0 and 1-1 draws. The calculator multiplies the four low scorelines by the Dixon-Coles factor `τ`:

```text
τ(0,0) = 1 − λμρ    τ(0,1) = 1 + λρ    τ(1,0) = 1 + μρ    τ(1,1) = 1 − ρ    otherwise 1
```

`ρ` is fitted per forecast on a grid from −0.20 to 0.10 (step 0.005) by maximizing the weighted low-score likelihood plus a normal prior with mean −0.1 and standard deviation 0.05 (Dixon & Coles report about −0.13 for English football). The prior matters early in a season: without it, 39 matches pushed ρ to the −0.20 grid edge. With plenty of low-scoring results the league's own evidence dominates. Forecasts without a results snapshot use ρ = −0.1. `τ` is clamped at 0 and the score grid is renormalized, so probabilities remain valid for extreme inputs.

The calculator turns the combined rates into home-win/draw/away-win probabilities, scoreline probabilities, goal totals and both-teams-to-score probability. The most likely scoreline is a single possibility, not a promised final score.

These expected goals are inferred from score rates. They are not shot-based xG measurements. Raw recent form is shown as context; the sequence of W/D/L labels is not an additional model feature.

### Backtest

A walk-forward backtest predicted each of 372 Premier League 2025-26 matches using only earlier results (2024-25 and 2025-26 up to that match, same lookback, decay and minimums). Lower log loss and Brier score are better:

| Forecast | Log loss | Brier | Accuracy | Mean P(draw) |
| --- | --- | --- | --- | --- |
| Equal chances (⅓ each) | 1.0986 | 0.6667 | 41.7% | 33.3% |
| League base rate | 1.0900 | 0.6603 | 41.7% | 24.3% |
| `results-rates-v1` + independent Poisson | 1.0495 | 0.6319 | 48.1% | 24.6% |
| `results-ratings-v2`, ρ = 0 | 1.0362 | 0.6226 | 48.7% | 24.3% |
| `results-ratings-v2` + Dixon-Coles | **1.0336** | **0.6215** | **48.7%** | 26.6% |

The actual draw rate was 27.7%; the median fitted ρ was −0.095. This is one league and one season, and the parameters (90-day half-life, five prior matches) were not tuned on it. It supports the model change; it does not certify future accuracy.

## What is frozen

The request stores expected-goal inputs, the fitted `rho`, league and team sample counts, both teams' attack/defence ratings, fit iterations, source fixture IDs, cutoff, parameters, match-date range and model version in `fixture_snapshot.results_inputs`. A worker reads that snapshot and never recalculates these inputs from today's database. Snapshots frozen by `results-rates-v1` remain valid, so predictions accepted before an upgrade still complete. The source breakdown exposes the same evidence after completion.

The snapshot preserves model inputs, not a complete immutable copy of every upstream response. Bolum does not reconstruct historical knowledge from today's standings. Imported results only become eligible when Bolum records them. There is no fabricated historical performance record.

## Evaluation and next work

Generate forecasts before kickoff, import results afterward, and use Performance → Real data. Only eligible completed pre-kickoff forecasts count. Sample and mixed predictions stay separate; the API retains `external` as the real-data category for compatibility. Different real-data provider configurations currently share that category, so compare equivalent model versions and inputs before interpreting changes in aggregate metrics.

Accuracy is only one measure. Brier score and log loss penalize overconfident errors, and calibration compares stated confidence with observed outcomes. Performance also scores two naive forecasts on the same fixtures, equal chances and a leave-one-out league base rate, and reports the Brier skill against the base rate. Evaluate across sufficient fixtures using chronological splits. Choose smoothing and decay parameters on training/validation periods, then assess an untouched later period. Avoid optimizing repeatedly against the same displayed results.

Useful future experiments include tuning the half-life and prior on separated periods, lineups/injuries with observation timestamps, shot-level xG where licensed data exists, and timestamped market benchmarks. Each should earn its place through prospective or properly separated historical evaluation. More inputs alone do not prove an improvement.
