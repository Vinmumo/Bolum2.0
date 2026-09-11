<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Bolum football analytics. Explore fixtures, compare predictions and track your results.">
    <title>Bolum — Football intelligence</title>
    <link rel="stylesheet" href="/assets/dashboard.css">
    <script src="/assets/dashboard.js" defer></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="/" aria-label="Bolum home"><span class="brand-mark">b<span></span></span>bolum<span class="brand-dot">.</span></a>
        <div class="workspace-label">FOOTBALL INTELLIGENCE</div>
        <nav aria-label="Main navigation">
            <button class="nav-item active" data-tab="matches"><span aria-hidden="true">▦</span> Match center <i>01</i></button>
            <button class="nav-item" data-tab="predictions"><span aria-hidden="true">◈</span> Predictions <i>02</i></button>
            <button class="nav-item" data-tab="performance"><span aria-hidden="true">↗</span> Performance <i>03</i></button>
            <button class="nav-item admin-only" data-tab="providers" hidden><span aria-hidden="true">⌘</span> Data sources <i>04</i></button>
        </nav>
        <div class="sidebar-bottom"><div class="sidebar-note"><span class="tiny-dot"></span> A clearer view of the game<p>Explore the numbers.<br>Keep track of the outcomes.</p></div><a href="/api/v1/fixtures" target="_blank" rel="noopener">Explore the API <span>↗</span></a><span class="version">BOLUM / V2.0</span></div>
    </aside>
    <div class="main-shell">
        <header class="topbar">
            <div class="breadcrumb">Workspace <span>/</span> <strong id="page-label">Match center</strong></div>
            <div class="topbar-actions"><label class="sr-only" for="company">Company</label><select id="company" hidden></select><button id="account" class="account-button">Sign in <span class="avatar">→</span></button></div>
        </header>
        <main id="main-content">
            <section class="page-heading"><div><div class="eyebrow"><span class="tiny-dot"></span> THE MATCHDAY PERSPECTIVE</div><h1 id="page-title">A better view of the game<span>.</span></h1><p id="page-description">Explore the fixtures. Find the probabilities. Follow the results.</p></div><div id="today" class="date-card"></div></section>
            <div id="notice" class="notice" role="status" hidden></div>
            <section class="metrics" aria-label="Workspace summary">
                <article class="metric"><div>Fixtures in view <span>↗</span></div><strong id="fixture-count">—</strong><small>Across your selected filters</small></article>
                <article class="metric"><div>Your predictions <span>◈</span></div><strong id="prediction-count">—</strong><small>Private to your company</small></article>
                <article class="metric balance-metric"><div>Available credits <span>◎</span></div><strong id="credit-count">—</strong><small>1 credit per prediction <button id="topup-button" hidden>Add credits ↗</button></small></article>
            </section>
            <section id="matches-panel" class="tab-panel">
                <div class="section-heading"><div><h2>Match center <span class="tag">FIXTURES</span></h2><p>Your next match starts here.</p></div><button id="new-fixture" class="button secondary admin-only" hidden>+ Add fixture</button></div>
                <form id="filters" class="filters"><label class="search"><span aria-hidden="true">⌕</span><input id="search" name="q" placeholder="Search for a team…" aria-label="Search teams" maxlength="100"></label><label><span class="sr-only">League</span><select id="league-filter" name="league_id"><option value="">All leagues</option></select></label><label><span class="sr-only">Fixture status</span><select name="status" id="status-filter"><option value="">All matches</option><option value="scheduled">Scheduled</option><option value="live">Live</option><option value="finished">Finished</option><option value="postponed">Postponed</option><option value="cancelled">Cancelled</option></select></label><button class="button secondary" type="submit">Apply filters</button></form>
                <div id="fixtures" class="fixture-grid" aria-live="polite"><div class="empty">Loading fixtures…</div></div>
                <div class="pagination"><span id="fixture-page-label"></span><div><button id="fixtures-prev" class="button secondary">← Previous</button><button id="fixtures-next" class="button secondary">Next →</button></div></div>
            </section>
            <section id="predictions-panel" class="tab-panel" hidden>
                <div class="section-heading"><div><h2>Prediction history</h2><p>Every request, every outcome. Only visible to your company.</p></div><button class="button secondary" id="refresh-predictions">↻ Refresh</button></div>
                <div id="predictions" class="list-panel"></div>
                <div class="pagination"><span id="prediction-page-label"></span><div><button id="predictions-prev" class="button secondary">← Previous</button><button id="predictions-next" class="button secondary">Next →</button></div></div>
                <div class="section-heading ledger-heading"><div><h2>Credit activity</h2><p>Demo credits, with a recorded entry for every change.</p></div></div><div id="ledger" class="list-panel"></div>
            </section>
            <section id="performance-panel" class="tab-panel" hidden>
                <div class="section-heading"><div><h2>Track the results</h2><p>Frozen predictions compared with final scores.</p></div><label>Data category <select id="quality"><option value="external">External data</option><option value="sample">Sample data</option><option value="mixed">Mixed data</option></select></label></div>
                <div id="performance"></div>
            </section>
            <section id="providers-panel" class="tab-panel" hidden>
                <div class="section-heading"><div><h2>Data sources</h2><p>Provider configuration and the last 24 hours of activity.</p></div><div class="actions"><button id="sync-fixtures" class="button secondary">↻ Sync fixtures</button><button id="new-provider" class="button primary">+ Add provider</button></div></div>
                <div id="providers" class="fixture-grid"></div><div id="provider-usage"></div>
            </section>
            <footer><span>BUILT FOR A CLOSER LOOK.</span><span>Sample predictions use synthetic inputs. Credits have no monetary value.</span></footer>
        </main>
    </div>
</div>
<dialog id="auth-dialog"><form id="login-form"><div class="dialog-heading"><div><div class="eyebrow">YOUR WORKSPACE</div><h2>Welcome to Bolum.</h2></div><button type="button" class="icon-button" data-close aria-label="Close">×</button></div><p>Sign in to generate predictions and follow your results.</p><label>Email<input name="email" type="email" autocomplete="username" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><div class="form-error" role="alert"></div><button class="button primary full" type="submit">Sign in →</button>@if(app()->environment('local'))<p class="demo-hint">Local sample accounts:<br><strong>admin@bolum.test</strong> or <strong>member@bolum.test</strong><br>Password: <strong>password123</strong></p>@endif<p>Need a workspace? <button class="text-button" type="button" id="show-register">Create an account</button></p></form></dialog>
<dialog id="register-dialog"><form id="register-form"><div class="dialog-heading"><h2>Create your workspace</h2><button type="button" class="icon-button" data-close aria-label="Close">×</button></div><label>Your name<input name="name" required maxlength="100" autocomplete="name"></label><label>Company name<input name="company_name" required maxlength="100"></label><label>Email<input name="email" type="email" required autocomplete="username"></label><label>Password<input name="password" type="password" required minlength="10" autocomplete="new-password"></label><label>Confirm password<input name="password_confirmation" type="password" required minlength="10" autocomplete="new-password"></label><div class="form-error" role="alert"></div><button type="submit" class="button primary full">Create workspace →</button></form></dialog>
<dialog id="detail-dialog" class="detail-dialog"><div class="dialog-heading"><div><div class="eyebrow">MATCH ANALYSIS</div><h2 id="detail-title">Fixture</h2></div><button class="icon-button" data-close aria-label="Close">×</button></div><div id="detail-body"></div></dialog>
<dialog id="topup-dialog"><form id="topup-form"><div class="dialog-heading"><h2>Add demo credits</h2><button type="button" class="icon-button" data-close aria-label="Close">×</button></div><p>These are free demo units, not a purchase.</p><label>Credits<input name="amount" type="number" min="1" max="10000" value="10" required></label><div class="form-error" role="alert"></div><button class="button primary full">Add credits</button></form></dialog>
<dialog id="provider-dialog"><form id="provider-form"><div class="dialog-heading"><h2>Provider configuration</h2><button type="button" class="icon-button" data-close aria-label="Close">×</button></div><input name="id" type="hidden"><label>Name<input name="name" maxlength="100" required></label><label>Driver<select name="driver"><option value="sample">Sample · synthetic inputs</option><option value="http">HTTP · configured gateway</option></select></label><label>Weight<input type="number" name="weight" min="0.001" max="100" step="0.001" value="1" required></label><label class="check-label"><input type="checkbox" name="is_active" checked> Active</label><p>HTTP credentials are configured on the server.</p><div class="form-error" role="alert"></div><button class="button primary full">Save provider</button></form></dialog>
<dialog id="fixture-dialog"><form id="fixture-form"><div class="dialog-heading"><h2>Add a fixture</h2><button type="button" class="icon-button" data-close aria-label="Close">×</button></div><label>League<select id="fixture-league" name="league_id" required></select></label><label>Home team<select name="home_team_id" required></select></label><label>Away team<select name="away_team_id" required></select></label><label>Kickoff (your local time)<input type="datetime-local" name="kickoff_at" required></label><div class="form-error" role="alert"></div><button class="button primary full">Create fixture</button></form></dialog>
</body>
</html>
