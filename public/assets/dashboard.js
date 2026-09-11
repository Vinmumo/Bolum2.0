(() => {
    'use strict';
    const $ = (id) => document.getElementById(id);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const pct = (value) => `${Math.round(Number(value || 0) * 100)}%`;
    const number = (value, places = 2) => value == null ? '—' : Number(value).toFixed(places);
    const when = (date) => new Date(date).toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    const badge = (status) => `<span class="status ${esc(status)}">${esc(status)}</span>`;
    const initials = (name) => name.split(' ').slice(0,2).map(x => x[0]).join('').toUpperCase();
    const state = {user:null,companies:[],company:null,epoch:0,fixtures:[],leagues:[],predictions:[],providers:[],page:1,historyPage:1,tab:'matches',selected:null,prediction:null,detailVersion:0,keys:new Map(),polling:false};
    let csrf = document.querySelector('meta[name="csrf-token"]').content;
    let toastTimer;
    let fixtureRequest = 0, privateRequest = 0, performanceRequest = 0, providerRequest = 0;
    let loadingVersion = 0, pendingRequests = 0, networkTimer;
    const spinner = '<span class="loading-spinner" aria-hidden="true"></span>';
    function networkBusy(change) {
        pendingRequests += change;
        if (pendingRequests === 1 && change > 0) networkTimer = setTimeout(() => $('network-status').hidden = false, 150);
        if (!pendingRequests) { clearTimeout(networkTimer); $('network-status').hidden = true; }
    }
    function skeleton(label, cards = false) {
        return `<div class="loading-caption" role="status">${spinner}${esc(label)}</div>${Array.from({length:cards ? 6 : 3}, () => `<div class="${cards ? 'skeleton-card' : 'skeleton-row'}" aria-hidden="true"><span class="skeleton-line short"></span>${cards ? '<div class="skeleton-teams"><i></i><i></i></div>' : ''}<span class="skeleton-line"></span><span class="skeleton-line short"></span></div>`).join('')}`;
    }
    async function withLoading(ids, label, task, {quiet = false} = {}) {
        const version = String(++loadingVersion), epoch = state.epoch;
        for (const id of ids) {
            const el = $(id); el.dataset.loadingVersion = version; el.setAttribute('aria-busy','true');
            if (!quiet) el.innerHTML = skeleton(label, ['fixtures','providers'].includes(id));
        }
        try { return await task(); }
        catch (error) {
            for (const id of ids) {
                const el = $(id);
                if (el.dataset.loadingVersion === version && epoch === state.epoch && !quiet) el.innerHTML = `<div class="empty load-error"><strong>Unable to load this section</strong><p>${esc(error.message)}</p><button class="button secondary" data-retry="${id}">Try again</button></div>`;
            }
            throw error;
        } finally {
            for (const id of ids) if ($(id).dataset.loadingVersion === version) $(id).setAttribute('aria-busy','false');
        }
    }
    function buttonLoading(button, label) {
        const html = button.innerHTML, disabled = button.disabled;
        button.disabled = true; button.setAttribute('aria-busy','true'); button.innerHTML = `${spinner}${esc(label)}`;
        return () => { button.innerHTML = html; button.disabled = disabled; button.removeAttribute('aria-busy'); };
    }
    function notice(message, error = false) {
        clearTimeout(toastTimer); $('notice').textContent = message; $('notice').className = `notice${error ? ' error' : ''}`; $('notice').hidden = false;
        if (!error) toastTimer = setTimeout(() => $('notice').hidden = true, 6500);
    }
    async function api(path, {method='GET',data,headers={},background=false} = {}) {
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 30000);
        if (!background) networkBusy(1);
        try {
        let response;
        try { response = await fetch(path, {method,signal:controller.signal,credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,...headers},...(data !== undefined ? {body:JSON.stringify(data)} : {})}); }
        catch { throw new Error(controller.signal.aborted ? 'The request took too long. Try again.' : 'Unable to reach Bolum. Check your connection and try again.'); }
        const body = response.status === 204 ? {} : await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = response.status === 419 ? 'Your session expired. Reload this page and sign in again.' : Object.values(body.errors || {}).flat()[0] || body.message || 'The request could not be completed.';
            const error = new Error(message); error.status = response.status; throw error;
        }
        if (body.csrf_token) csrf = body.csrf_token;
        return body;
        } finally { clearTimeout(timeout); if (!background) networkBusy(-1); }
    }
    function companyPath(path) { return `/api/v1/companies/${state.company}${path}`; }
    function empty(title, description, signin = false) { return `<div class="empty"><strong>${esc(title)}</strong>${esc(description)}${signin ? '<br><button class="button primary" data-signin>Sign in →</button>' : ''}</div>`; }
    function probability(probs) {
        if (!probs) return '';
        return `<div class="probability-bar" role="img" aria-label="Home ${pct(probs.home_win)}, draw ${pct(probs.draw)}, away ${pct(probs.away_win)}">${['home_win','draw','away_win'].map((key,i) => `<span class="${['home','draw','away'][i]}" style="width:${Math.max(0,Math.min(100,Number(probs[key])*100))}%"></span>`).join('')}</div><div class="probability-labels"><span>Home ${pct(probs.home_win)}</span><span>Draw ${pct(probs.draw)}</span><span>Away ${pct(probs.away_win)}</span></div>`;
    }
    function setTab(tab) {
        if (tab === 'providers' && !state.user?.is_admin) return;
        state.tab = tab;
        document.querySelectorAll('[data-tab]').forEach(el => el.classList.toggle('active', el.dataset.tab === tab));
        document.querySelectorAll('.tab-panel').forEach(el => el.hidden = el.id !== `${tab}-panel`);
        const headings = {matches:['Match center','A better view of the game','Explore the fixtures. Find the probabilities. Follow the results.'],predictions:['Predictions','Every prediction. One place','A clear record of your company’s requests and outcomes.'],performance:['Performance','Let the results speak','Evaluate what was predicted before the final whistle.'],providers:['Data sources','Know your sources','Manage your providers and keep an eye on their performance.']};
        $('page-label').textContent = headings[tab][0]; $('page-title').innerHTML = `${headings[tab][1]}<span>.</span>`; $('page-description').textContent = headings[tab][2];
        if (tab === 'performance') loadPerformance().catch(error => notice(error.message,true));
        if (tab === 'providers') loadProviders().catch(error => notice(error.message,true));
    }
    function renderIdentity() {
        $('account').innerHTML = state.user ? `${esc(state.user.name)} <span class="avatar">↗</span>` : 'Sign in <span class="avatar">→</span>';
        $('account').title = state.user ? 'Sign out' : 'Sign in';
        $('company').innerHTML = state.companies.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
        $('company').value = state.company || ''; $('company').hidden = !state.user;
        document.querySelectorAll('.admin-only').forEach(el => el.hidden = !state.user?.is_admin);
        $('topup-button').hidden = state.companies.find(c => c.id === Number(state.company))?.pivot?.role !== 'owner';
    }
    async function loadIdentity() {
        try { const response = await api('/api/v1/auth/me'); state.user = response.data.user; state.companies = response.data.companies; state.company = state.companies[0]?.id || null; }
        catch(error) { if (error.status !== 401) notice(error.message,true); state.user = null; state.company = null; state.companies = []; }
        state.epoch++; renderIdentity();
    }
    function renderFixtures(force = false) {
        if (!force && $('fixtures').getAttribute('aria-busy') === 'true') return;
        const latest = new Map(); state.predictions.forEach(p => { if (!latest.has(p.fixture_id)) latest.set(p.fixture_id,p); });
        $('fixtures').innerHTML = state.fixtures.map(f => {
            const prediction = latest.get(f.id);
            return `<article class="fixture-card"><div class="card-top"><span class="league-name">${esc(f.league.name)}${f.matchday ? ` · GW ${f.matchday}` : ''}</span>${badge(f.status)}</div><div class="teams"><div><span class="team-badge">${esc(initials(f.home_team.name))}</span><span class="team-name">${esc(f.home_team.name)}</span></div><div class="versus">${f.is_finished && f.score.home !== null ? `<strong>${f.score.home} : ${f.score.away}</strong>` : 'VS'}</div><div><span class="team-badge away">${esc(initials(f.away_team.name))}</span><span class="team-name">${esc(f.away_team.name)}</span></div></div><div class="match-date">${esc(when(f.kickoff_at))} · ${f.source === 'local' ? 'Local fixture' : 'Synced fixture'}</div>${prediction?.result ? `<div class="prediction-preview">${probability(prediction.result.probabilities)}</div>` : ''}<div class="card-bottom"><span>${prediction ? `${esc(prediction.status)} prediction` : 'Explore the numbers'}</span><button data-fixture="${f.id}">View match <span>↗</span></button></div></article>`;
        }).join('') || empty('No fixtures found','Try another league or status filter.');
    }
    async function loadFixtures() {
        const requestId = ++fixtureRequest;
        $('fixtures-prev').disabled = $('fixtures-next').disabled = true;
        $('fixture-page-label').textContent = 'Loading fixtures…'; $('fixture-count').textContent = '…';
        return withLoading(['fixtures'], 'Finding fixtures…', async () => {
        const params = new URLSearchParams(new FormData($('filters'))); [...params.keys()].forEach(key => { if (!params.get(key)) params.delete(key); });
        if (params.get('status') === 'upcoming') { params.delete('status'); params.set('upcoming','1'); }
        params.set('page',state.page); params.set('per_page',9);
        const page = state.page, response = await api(`/api/v1/fixtures?${params}`);
        if (page !== state.page || requestId !== fixtureRequest) return;
        state.fixtures = response.data; renderFixtures(true); $('fixture-count').textContent = response.meta.total;
        const league = state.leagues.find(l => String(l.id) === $('league-filter').value);
        $('fixture-context').textContent = league?.source === 'football-data' ? 'Real fixtures from football-data.org. Kickoff times are shown in your local time.' : 'Browse imported and local fixtures. Local sample schedules are for demonstration.';
        $('fixture-page-label').textContent = `${response.meta.total} fixtures · Page ${response.meta.current_page} of ${response.meta.last_page}`;
        $('fixtures-prev').disabled = !response.links.prev; $('fixtures-next').disabled = !response.links.next;
        }).finally(() => { if (requestId === fixtureRequest && $('fixtures').querySelector('.load-error')) { $('fixture-count').textContent = '—'; $('fixture-page-label').textContent = 'Fixtures could not be loaded.'; } });
    }
    async function loadLeagues() {
        const previous = $('league-filter').value;
        const response = await api('/api/v1/leagues?per_page=100'); state.leagues = response.data;
        const options = state.leagues.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
        $('league-filter').innerHTML = '<option value="">All leagues</option>' + options; $('fixture-league').innerHTML = options;
        const preferred = state.leagues.find(l => l.source === 'football-data');
        $('league-filter').value = state.catalogLoaded ? previous : String(preferred?.id || '');
        state.catalogLoaded = true;
    }
    async function loadPrivate({quiet = false} = {}) {
        if (quiet && $('predictions').getAttribute('aria-busy') === 'true') return;
        const requestId = ++privateRequest;
        const epoch = state.epoch;
        if (!state.company) {
            state.predictions = []; $('prediction-count').textContent = '—'; $('credit-count').textContent = '—';
            $('predictions').innerHTML = empty('Your predictions belong here','Sign in to view your company’s prediction history.',true);
            $('ledger').innerHTML = empty('A clear credit trail','Sign in to see grants, debits and refunds.',true);
            $('prediction-page-label').textContent = ''; $('predictions-prev').disabled = $('predictions-next').disabled = true; renderFixtures(); return;
        }
        if (!quiet) { $('prediction-count').textContent = $('credit-count').textContent = '…'; $('predictions-prev').disabled = $('predictions-next').disabled = true; $('prediction-page-label').textContent = 'Loading history…'; }
        return withLoading(['predictions','ledger'], 'Loading your company activity…', async () => {
        const [history,credits] = await Promise.all([api(companyPath(`/predictions?per_page=15&page=${state.historyPage}`),{background:quiet}),api(companyPath('/credits?per_page=15'),{background:quiet})]);
        if (epoch !== state.epoch || requestId !== privateRequest) return;
        state.predictions = history.data; $('prediction-count').textContent = history.meta.total; $('credit-count').textContent = credits.balance;
        $('prediction-page-label').textContent = `Page ${history.meta.current_page} of ${history.meta.last_page}`; $('predictions-prev').disabled = !history.links.prev; $('predictions-next').disabled = !history.links.next;
        $('predictions').innerHTML = history.data.map(p => `<div class="list-row"><div><strong>${esc(p.fixture_snapshot.home_name || p.fixture?.home_team?.name || 'Home')} vs ${esc(p.fixture_snapshot.away_name || p.fixture?.away_team?.name || 'Away')}</strong><small>#${p.id} · ${esc(when(p.created_at))} · ${esc(p.result?.data_quality || 'Awaiting result')}</small></div>${badge(p.status)}<button class="button secondary" data-prediction="${p.id}">View result ↗</button></div>`).join('') || empty('No predictions yet','Open a fixture and request your first prediction.');
        $('ledger').innerHTML = credits.data.map(e => `<div class="list-row"><div>${esc(e.kind.replaceAll('_',' '))}<small>${esc(when(e.created_at))}${e.prediction_id ? ` · Prediction #${e.prediction_id}` : ''}</small></div><strong class="${e.amount > 0 ? 'positive' : 'negative'}">${e.amount > 0 ? '+' : ''}${e.amount} credits</strong><small>Balance ${e.balance_after}</small></div>`).join('') || empty('No credit activity','Your transactions will appear here.');
        renderFixtures();
        }, {quiet}).finally(() => { if (epoch === state.epoch && requestId === privateRequest && $('predictions').querySelector('.load-error')) { $('prediction-count').textContent = $('credit-count').textContent = '—'; $('prediction-page-label').textContent = ''; } });
    }
    async function openFixture(id, predictionId = null) {
        const version = ++state.detailVersion; state.selected = null; state.prediction = null; state.detailTarget = {id,predictionId}; $('detail-title').textContent = 'Loading fixture…';
        if (!$('detail-dialog').open) $('detail-dialog').showModal();
        return withLoading(['detail-body'], 'Loading match analysis…', async () => {
        const fixture = await api(`/api/v1/fixtures/${id}`); if (version !== state.detailVersion) return;
        state.selected = fixture.data;
        if (state.company) {
            const response = await api(companyPath(predictionId ? `/predictions/${predictionId}` : `/fixtures/${id}/predictions?per_page=1`));
            if (version !== state.detailVersion) return;
            state.prediction = predictionId ? response.data : response.data[0] || null;
        }
        renderDetail();
        });
    }
    function renderDetail() {
        const f = state.selected, p = state.prediction; if (!f) return;
        $('detail-title').textContent = `${f.home_team.name} vs ${f.away_team.name}`;
        const canPredict = f.status === 'scheduled' && new Date(f.kickoff_at) > new Date();
        let html = `<div class="detail-meta">${esc(f.league.name)} · ${esc(when(f.kickoff_at))} ${badge(f.status)}</div>`;
        if (p) html += `<p>Prediction #${p.id} · ${esc(when(p.created_at))} ${badge(p.status)}</p>`;
        if (p?.status === 'pending') html += `<div class="notice queued-state" role="status">${spinner}<div><strong>Your prediction is queued.</strong><br>This page updates automatically when it is ready.</div></div>`;
        if (p?.status === 'failed') html += `<div class="notice error">${esc(p.error)}</div>`;
        if (p?.result) {
            const r = p.result;
            html += `<p>${r.data_quality === 'sample' ? 'Synthetic sample inputs · for exploring the workflow.' : r.data_quality === 'mixed' ? 'Mixed sample and external inputs.' : 'External provider inputs.'} Model: ${esc(r.model)}.</p><div class="detail-score"><div><span>Home expected goals</span><strong>${number(r.expected_goals.home)}</strong></div><div><span>Most likely score</span><strong>${r.most_likely_score.home} : ${r.most_likely_score.away}</strong></div><div><span>Away expected goals</span><strong>${number(r.expected_goals.away)}</strong></div></div><div class="detail-block"><h3>Match outcome</h3>${probability(r.probabilities)}</div>`;
            if (r.totals) html += `<div class="detail-block"><h3>Goal probabilities</h3><div class="total-grid">${[['over_1_5','Over 1.5'],['over_2_5','Over 2.5'],['over_3_5','Over 3.5'],['both_teams_score','Both teams score']].map(([key,label]) => `<div>${label}<strong>${pct(r.totals[key])}</strong></div>`).join('')}</div></div>`;
            if (r.scorelines) html += `<div class="detail-block"><h3>Likely scorelines</h3><div class="scorelines">${r.scorelines.map(s => `<div>${s.home} : ${s.away}<small>${pct(s.probability)}</small></div>`).join('')}</div></div>`;
            html += `<div class="detail-block"><h3>By data source</h3>${r.sources?.map(s => `<div class="source-row"><div><strong>${esc(s.name)}</strong><small>Weight ${number(s.weight)} · xG ${number(s.expected_goals.home)} / ${number(s.expected_goals.away)}</small></div>${probability(s.probabilities)}</div>`).join('') || '<p>This older prediction does not include a source breakdown.</p>'}</div>`;
        }
        if (canPredict && p?.status !== 'pending') html += `<button id="generate" class="button primary full">${state.user ? (p ? 'Generate a new prediction · 1 credit' : 'Generate prediction · 1 credit') : 'Sign in to generate a prediction →'}</button><p>Repeated delivery of the same request never spends another credit.</p>`;
        if (!p && !canPredict) html += '<p>New predictions are available only before a scheduled fixture starts.</p>';
        if (state.user?.is_admin && new Date(f.kickoff_at) <= new Date() && !['postponed','cancelled'].includes(f.status)) html += `<div class="detail-block"><h3>Record final score</h3><form id="result-form" class="result-form"><label>Home<input name="home_goals" type="number" min="0" max="100" value="${f.score.home ?? 0}" required></label><label>Away<input name="away_goals" type="number" min="0" max="100" value="${f.score.away ?? 0}" required></label><button class="button primary">Save result</button><div class="form-error" role="alert"></div></form></div>`;
        $('detail-body').innerHTML = html;
    }
    async function generate(button) {
        if (!state.user) { $('detail-dialog').close(); $('auth-dialog').showModal(); return; }
        const epoch = state.epoch, version = state.detailVersion, f = state.selected, keyId = `${state.company}:${f.id}`;
        const key = state.keys.get(keyId) || crypto.randomUUID(); state.keys.set(keyId,key);
        buttonLoading(button, 'Requesting prediction…');
        try {
            const response = await api(companyPath(`/fixtures/${f.id}/predictions`),{method:'POST',data:{},headers:{'Idempotency-Key':key}});
            state.keys.delete(keyId); if (epoch !== state.epoch) return;
            if (version === state.detailVersion && state.selected?.id === f.id) { state.prediction = response.data; renderDetail(); } await loadPrivate(); notice('Prediction requested. Your result will appear here.');
        } catch(error) { button.disabled = false; button.removeAttribute('aria-busy'); button.textContent = 'Retry prediction request · 1 credit'; notice(error.message,true); }
    }
    async function loadPerformance() {
        const requestId = ++performanceRequest;
        if (!state.company) { $('performance').innerHTML = empty('Measure your predictions','Sign in to view your company’s performance.',true); return; }
        const epoch = state.epoch, quality = $('quality').value;
        return withLoading(['performance'], 'Loading prediction performance…', async () => {
        const response = await api(companyPath(`/performance?quality=${quality}`)); if (epoch !== state.epoch || quality !== $('quality').value || requestId !== performanceRequest) return;
        const r = response.data;
        if (!r.count) { $('performance').innerHTML = empty('No evaluated matches yet','Predictions must be completed before kickoff, and the fixture must have a recorded final score. Try the correct data category.'); return; }
        $('performance').innerHTML = `<div class="metrics"><article class="metric"><div>Evaluated fixtures</div><strong>${r.count}</strong><small>${esc(quality)} data only</small></article><article class="metric"><div>Outcome accuracy</div><strong>${pct(r.accuracy)}</strong><small>Most likely outcome versus actual result</small></article><article class="metric"><div>Brier score</div><strong>${number(r.brier_score,3)}</strong><small>0 is best · 2 is worst</small></article></div><h2>Confidence and outcomes</h2><p class="panel-note">Blue: average confidence. Green: observed accuracy. Log loss: ${number(r.log_loss,3)} (lower is better).</p><div class="calibration">${r.calibration.map(b => `<div class="calibration-column"><div class="calibration-track"><div style="height:${b.confidence*100}%" title="Confidence ${pct(b.confidence)}"></div><div class="actual" style="height:${b.accuracy*100}%" title="Accuracy ${pct(b.accuracy)}"></div></div>${esc(b.label)}<small>${b.count} fixtures</small></div>`).join('')}</div><p class="panel-note">${esc(r.method)} Sample and mixed data are kept separate from external-data results. Small samples do not establish forecasting quality.</p><div class="list-panel">${r.recent.map(row => `<div class="list-row"><div>${esc(row.fixture.home_name || 'Home')} vs ${esc(row.fixture.away_name || 'Away')}<small>Prediction #${row.prediction_id} · ${pct(row.confidence)} confidence</small></div><strong>${row.home_goals} : ${row.away_goals}</strong>${badge(row.correct ? 'matched' : 'missed')}</div>`).join('')}</div>`;
        });
    }
    async function loadProviders({quiet = false} = {}) {
        if (!state.user?.is_admin) return;
        if (quiet && $('providers').getAttribute('aria-busy') === 'true') return;
        const epoch = state.epoch, requestId = ++providerRequest;
        return withLoading(['providers','provider-usage'], 'Loading data sources…', async () => {
        const [providers,usage] = await Promise.all([api('/api/v1/providers?per_page=100',{background:quiet}),api('/api/v1/providers/usage',{background:quiet})]);
        if (epoch !== state.epoch || !state.user?.is_admin || requestId !== providerRequest) return;
        state.providers = providers.data;
        $('providers').innerHTML = providers.data.map(p => `<article class="fixture-card provider-card">${badge(p.is_active ? 'active' : 'paused')}<h3>${esc(p.name)}</h3><p>${p.driver === 'sample' ? 'Synthetic sample inputs' : 'Server-configured HTTP gateway'} · Weight ${esc(p.weight)}</p><button class="button secondary" data-provider="${p.id}">Configure ↗</button></article>`).join('') || empty('No providers configured','Add a sample or HTTP provider.');
        $('provider-usage').innerHTML = `<div class="section-heading ledger-heading"><h2>Provider activity</h2></div>${usage.data.length ? `<div class="table-wrap"><table><thead><tr><th>Source</th><th>Calls + cache reads</th><th>Cache hits</th><th>Failures</th><th>Average latency</th></tr></thead><tbody>${usage.data.map(s => `<tr><td>${esc(s.source)}</td><td>${s.calls}</td><td>${s.cache_hits}</td><td>${s.failures}</td><td>${number(s.average_ms,0)} ms</td></tr>`).join('')}</tbody></table></div>` : empty('No external requests yet','Sample predictions do not make network requests. Gateway and fixture-feed activity appears here.')}<div class="table-wrap"><table><thead><tr><th>Time</th><th>Source / operation</th><th>Status</th><th>HTTP</th><th>Duration</th></tr></thead><tbody>${usage.recent.map(c => `<tr><td>${esc(when(c.created_at))}</td><td>${esc(c.source)} / ${esc(c.operation)}</td><td>${esc(c.status)}</td><td>${c.http_status ?? '—'}</td><td>${c.duration_ms} ms</td></tr>`).join('') || '<tr><td colspan="5">No calls recorded.</td></tr>'}</tbody></table></div><h2 class="ledger-heading">Fixture synchronization</h2><div class="list-panel">${usage.syncs.map(s => `<div class="list-row"><div>Sync #${s.id}<small>${esc(when(s.created_at))} · ${s.imported} fixtures imported${s.error ? ` · ${esc(s.error)}` : ''}</small></div>${badge(s.status)}</div>`).join('') || empty('No synchronization runs','Configure FOOTBALL_DATA_TOKEN on the server, then sync fixtures.')}</div>`;
        }, {quiet});
    }
    async function fillTeams() {
        const league = $('fixture-league').value;
        const response = await api(`/api/v1/teams?per_page=100&league_id=${league}`);
        if ($('fixture-league').value !== league) return;
        const options = response.data.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        $('fixture-form').elements.home_team_id.innerHTML = options; $('fixture-form').elements.away_team_id.innerHTML = options;
        if (response.data.length > 1) $('fixture-form').elements.away_team_id.value = response.data[1].id;
    }
    function bindForm(id, handler) {
        $(id).addEventListener('submit',async event => {
            event.preventDefault(); const form = event.currentTarget, button = form.querySelector('button[type=submit], button.primary');
            const errorEl = form.querySelector('.form-error'); errorEl.textContent = '';
            const restore = buttonLoading(button, id === 'login-form' ? 'Signing in…' : id === 'register-form' ? 'Creating workspace…' : 'Saving…');
            try { await handler(form); } catch(error) { errorEl.textContent = error.message; } finally { restore(); }
        });
    }
    bindForm('login-form',async form => {
        await api('/session/login',{method:'POST',data:Object.fromEntries(new FormData(form))}); form.reset(); $('auth-dialog').close(); await loadIdentity(); await loadPrivate(); if(state.tab==='performance') await loadPerformance(); notice('Welcome back. Your workspace is ready.');
    });
    bindForm('register-form',async form => {
        await api('/session/register',{method:'POST',data:Object.fromEntries(new FormData(form))}); form.reset(); $('register-dialog').close(); await loadIdentity(); await loadPrivate(); notice('Workspace created with 10 demo credits.');
    });
    let topupKey;
    bindForm('topup-form',async form => {
        topupKey ||= crypto.randomUUID(); await api(companyPath('/credits/top-ups'),{method:'POST',data:{amount:Number(form.elements.amount.value)},headers:{'Idempotency-Key':topupKey}});
        topupKey = null; $('topup-dialog').close(); await loadPrivate(); notice('Demo credits added.');
    });
    bindForm('provider-form',async form => {
        const id = form.elements.id.value, data = {name:form.elements.name.value,driver:form.elements.driver.value,weight:Number(form.elements.weight.value),is_active:form.elements.is_active.checked};
        await api(`/api/v1/providers${id ? `/${id}` : ''}`,{method:id ? 'PUT' : 'POST',data}); $('provider-dialog').close(); await loadProviders(); notice('Provider configuration saved.');
    });
    bindForm('fixture-form',async form => {
        const data = Object.fromEntries(new FormData(form)); data.kickoff_at = new Date(data.kickoff_at).toISOString();
        await api('/api/v1/fixtures',{method:'POST',data}); $('fixture-dialog').close(); state.page = 1; await loadFixtures(); notice('Fixture created.');
    });
    document.addEventListener('click',async event => {
        const target = event.target.closest('button'); if (!target) return;
        try {
            if (target.hasAttribute('data-close')) target.closest('dialog').close();
            else if (target.dataset.tab) setTab(target.dataset.tab);
            else if (target.dataset.retry) {
                const retries = {fixtures:loadFixtures,predictions:loadPrivate,ledger:loadPrivate,performance:loadPerformance,providers:loadProviders,'provider-usage':loadProviders,'detail-body':() => openFixture(state.detailTarget.id,state.detailTarget.predictionId)};
                await retries[target.dataset.retry]?.();
            }
            else if (target.hasAttribute('data-signin')) $('auth-dialog').showModal();
            else if (target.dataset.fixture) await openFixture(Number(target.dataset.fixture));
            else if (target.dataset.prediction) { const p = state.predictions.find(p => p.id === Number(target.dataset.prediction)); await openFixture(p.fixture_id,p.id); }
            else if (target.id === 'generate') await generate(target);
            else if (target.dataset.provider) {
                const p = state.providers.find(p => p.id === Number(target.dataset.provider)), form = $('provider-form');
                for (const key of ['id','name','driver','weight']) form.elements[key].value = p[key]; form.elements.is_active.checked = p.is_active; form.querySelector('.form-error').textContent = ''; $('provider-dialog').showModal();
            }
        } catch(error) { notice(error.message,true); }
    });
    $('detail-body').addEventListener('submit',async event => {
        if (event.target.id !== 'result-form') return; event.preventDefault();
        const form = event.target, button = form.querySelector('button'); button.disabled = true;
        try { await api(`/api/v1/fixtures/${state.selected.id}/result`,{method:'PUT',data:Object.fromEntries(new FormData(form))}); await openFixture(state.selected.id); await loadFixtures(); notice('Final score recorded.'); }
        catch(error) { form.querySelector('.form-error').textContent = error.message; button.disabled = false; }
    });
    $('account').addEventListener('click',async () => {
        if (!state.user) { $('auth-dialog').showModal(); return; }
        $('account').disabled = true;
        try { await api('/session/logout',{method:'POST',data:{}}); state.user=null; state.companies=[]; state.company=null; state.epoch++; state.keys.clear(); renderIdentity(); setTab('matches'); await loadPrivate(); $('providers').innerHTML = $('provider-usage').innerHTML = $('performance').innerHTML = ''; notice('You are signed out.'); }
        catch(error) { notice(error.message,true); }
        finally { $('account').disabled = false; }
    });
    $('show-register').onclick = () => { $('auth-dialog').close(); $('register-dialog').showModal(); };
    $('company').onchange = async () => { state.company=Number($('company').value); state.epoch++; state.historyPage=1; state.predictions=[]; state.detailVersion++; $('detail-dialog').close(); renderIdentity(); $('prediction-count').textContent = $('credit-count').textContent = '…'; $('predictions').innerHTML = $('ledger').innerHTML = empty('Loading company data…',''); $('performance').innerHTML = ''; renderFixtures(); try { await loadPrivate(); if(state.tab==='performance') await loadPerformance(); } catch(error) { notice(error.message,true); } };
    $('filters').onsubmit = async event => { event.preventDefault(); state.page=1; const restore=buttonLoading($('filters').querySelector('button'),'Finding matches…'); try { await loadFixtures(); } catch(error) { notice(error.message,true); } finally { restore(); } };
    for (const [id,delta] of [['fixtures-prev',-1],['fixtures-next',1]]) $(id).onclick = () => { state.page+=delta; loadFixtures().catch(error => notice(error.message,true)); };
    for (const [id,delta] of [['predictions-prev',-1],['predictions-next',1]]) $(id).onclick = () => { state.historyPage+=delta; loadPrivate().catch(error => notice(error.message,true)); };
    $('refresh-predictions').onclick = async () => { const restore=buttonLoading($('refresh-predictions'),'Refreshing…'); try { await loadPrivate(); } catch(error) { notice(error.message,true); } finally { restore(); } };
    $('quality').onchange = () => loadPerformance().catch(error => notice(error.message,true));
    $('topup-button').onclick = () => { $('topup-form').querySelector('.form-error').textContent=''; $('topup-dialog').showModal(); };
    $('new-provider').onclick = () => { $('provider-form').reset(); $('provider-form').elements.id.value=''; $('provider-form').querySelector('.form-error').textContent=''; $('provider-dialog').showModal(); };
    $('new-fixture').onclick = async () => { const restore=buttonLoading($('new-fixture'),'Loading teams…'); try { await fillTeams(); $('fixture-form').querySelector('.form-error').textContent=''; $('fixture-dialog').showModal(); } catch(error) { notice(error.message,true); } finally { restore(); } };
    $('fixture-league').onchange = () => fillTeams().catch(error => notice(error.message,true));
    $('sync-fixtures').onclick = async () => { const restore = buttonLoading($('sync-fixtures'),'Queuing sync…'); try { await api('/api/v1/fixtures/sync',{method:'POST',data:{}}); await loadProviders(); notice('Fixture synchronization queued.'); } catch(error) { notice(error.message,true); } finally { restore(); } };
    $('today').innerHTML = `YOUR MATCHDAY BRIEFING<br><strong>${esc(new Date().toLocaleDateString([], {weekday:'long',month:'short',day:'numeric'}))}</strong>`;
    async function poll() {
        if (!state.company || document.hidden || state.polling) return;
        state.polling=true; const version=state.detailVersion, epoch=state.epoch;
        try {
            if ($('detail-dialog').open && state.prediction?.status === 'pending') {
                const response=await api(companyPath(`/predictions/${state.prediction.id}`),{background:true});
                if (epoch===state.epoch && version===state.detailVersion) { state.prediction=response.data; renderDetail(); }
            }
            if (state.predictions.some(p=>p.status==='pending')) await loadPrivate({quiet:true});
            if (state.tab==='providers') await loadProviders({quiet:true});
        } catch(error) { if(error.status===401) { notice('Your session expired. Sign in again.',true); state.company=null; } }
        finally { state.polling=false; }
    }
    $('fixtures').innerHTML = skeleton('Loading your match center…',true);
    $('fixtures').setAttribute('aria-busy','true');
    (async () => { try { await Promise.all([loadIdentity(),loadLeagues()]); await Promise.all([loadFixtures(),loadPrivate()]); } catch(error) { notice(error.message,true); if (!$('fixtures').querySelector('.load-error')) { $('fixtures').innerHTML=empty('Unable to load the workspace','Reload the page to try again.'); $('fixtures').setAttribute('aria-busy','false'); } } })();
    setInterval(poll,4000);
})();
