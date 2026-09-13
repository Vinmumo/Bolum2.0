(() => {
    'use strict';
    const $ = (id) => document.getElementById(id);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const pct = (value) => `${Math.round(Number(value || 0) * 100)}%`;
    const number = (value, places = 2) => value == null ? '—' : Number(value).toFixed(places);
    const when = (date) => new Date(date).toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    const badge = (status) => `<span class="status ${esc(status)}">${esc(status)}</span>`;
    const initials = (name) => name.split(' ').slice(0,2).map(x => x[0]).join('').toUpperCase();
    const failedCrests = new Set();
    function teamBadge(team, away = false) {
        const url = typeof team.crest_url === 'string' && /^https:\/\/crests\.football-data\.org\/[a-z0-9_-]+\.(png|svg|webp)$/i.test(team.crest_url) ? team.crest_url : null;
        return `<span class="team-badge${away ? ' away' : ''}${url ? ' team-logo' : ''}" aria-hidden="true"><span class="crest-fallback">${esc(initials(team.name))}</span>${url && !failedCrests.has(url) ? `<img class="team-crest" src="${esc(url)}" alt="" width="48" height="48" loading="lazy" decoding="async" referrerpolicy="no-referrer">` : ''}</span>`;
    }
    document.addEventListener('load', event => {
        const img = event.target;
        if (!(img instanceof HTMLImageElement) || !img.classList.contains('team-crest')) return;
        img.classList.add('loaded'); img.previousElementSibling.hidden = true;
    }, true);
    document.addEventListener('error', event => {
        const img = event.target;
        if (!(img instanceof HTMLImageElement) || !img.classList.contains('team-crest')) return;
        failedCrests.add(img.getAttribute('src'));
        img.previousElementSibling.hidden = false; img.remove();
    }, true);

    const state = {user:null,companies:[],company:null,epoch:0,fixtures:[],leagues:[],predictions:[],providers:[],page:1,historyPage:1,tab:'matches',selected:null,prediction:null,detailVersion:0,keys:new Map(),polling:false};
    let csrf = document.querySelector('meta[name="csrf-token"]').content;
    let toastTimer;
    let fixtureRequest = 0, privateRequest = 0, performanceRequest = 0, providerRequest = 0, standingsRequest = 0, profileRequest = 0, trackRequest = 0;
    let searchTimer;
    let loadingVersion = 0, pendingRequests = 0, networkTimer;
    const buttonStates = new WeakMap();
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
        const previous = buttonStates.get(button);
        const entry = {html:previous?.html ?? button.innerHTML,disabled:previous?.disabled ?? button.disabled};
        buttonStates.set(button,entry);
        button.disabled = true; button.setAttribute('aria-busy','true'); button.innerHTML = `${spinner}${esc(label)}`;
        return () => {
            if (buttonStates.get(button) !== entry) return;
            button.innerHTML = entry.html; button.disabled = entry.disabled; button.removeAttribute('aria-busy'); buttonStates.delete(button);
        };
    }
    function updateMatchViews() {
        document.querySelectorAll('[data-match-view]').forEach(el => el.setAttribute('aria-pressed',String(el.dataset.matchView === $('status-filter').value)));
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
            const error = new Error(message); error.status = response.status; error.fields = body.errors || {}; throw error;
        }
        if (body.csrf_token) csrf = body.csrf_token;
        return body;
        } finally { clearTimeout(timeout); if (!background) networkBusy(-1); }
    }
    const avatarOptions = {football:['⚽','Football'],captain:['★','Captain'],keeper:['◉','Goalkeeper'],trophy:['♜','Champion'],stadium:['▥','Stadium'],lightning:['ϟ','Lightning']};
    function avatar(user, large = false) {
        const key = Object.hasOwn(avatarOptions,user?.avatar) ? user.avatar : 'football';
        return `<span class="profile-avatar avatar-${key}${large ? ' large' : ''}" aria-hidden="true">${avatarOptions[key][0]}</span>`;
    }
    function clearFieldError(field) {
        if (!field.name) return;
        const id = `${field.form.id}-${field.name}-error`;
        $(id)?.remove(); field.removeAttribute('aria-invalid');
        const remaining = (field.getAttribute('aria-describedby') || '').split(' ').filter(x=>x && x!==id);
        if (remaining.length) field.setAttribute('aria-describedby',remaining.join(' ')); else field.removeAttribute('aria-describedby');
    }
    function fieldError(field,message) {
        clearFieldError(field);
        const error = document.createElement('small'); error.id = `${field.form.id}-${field.name}-error`; error.className='field-error'; error.textContent=message;
        field.insertAdjacentElement('afterend',error); field.setAttribute('aria-invalid','true');
        field.setAttribute('aria-describedby',[field.getAttribute('aria-describedby'),error.id].filter(Boolean).join(' '));
    }
    function fieldMessage(field) {
        if (field.disabled || field.type === 'hidden' || !field.willValidate) return '';
        if (!['password','checkbox','radio'].includes(field.type)) field.value = field.value.trim();
        const validity=field.validity;
        if (validity.valueMissing) return 'This field is required.';
        if (validity.typeMismatch && field.type==='email') return 'Enter a valid email address.';
        if (field.value && field.minLength>0 && field.value.length<field.minLength) return `Use at least ${field.minLength} characters.`;
        if (field.maxLength>0 && field.value.length>field.maxLength) return `Use no more than ${field.maxLength} characters.`;
        if (field.name==='password_confirmation' && field.value!==field.form.elements.password.value) return 'Passwords do not match.';
        if (field.name==='away_team_id' && field.value===field.form.elements.home_team_id.value) return 'Choose a different away team.';
        if (validity.rangeUnderflow) return `Enter ${field.min} or more.`;
        if (validity.rangeOverflow) return `Enter ${field.max} or less.`;
        if (validity.stepMismatch) return field.step==='0.001' ? 'Use up to three decimal places.' : 'Enter a whole number.';
        if (validity.badInput || !validity.valid) return 'Check this value and try again.';
        return '';
    }
    function validateForm(form) {
        let first;
        for (const field of form.elements) {
            if (!field.name) continue;
            clearFieldError(field); const message=fieldMessage(field);
            if (message) {fieldError(field,message); first ||= field;}
        }
        if (first) {form.querySelector('.form-error').textContent='Please check the highlighted fields.'; first.focus(); return false;}
        return true;
    }
    function setupValidation(form) {
        form.noValidate=true;
        form.addEventListener('input',event=>{if(event.target.name) clearFieldError(event.target);});
        form.addEventListener('focusout',event=>{
            const field=event.target; if(!field.name || !field.willValidate) return;
            const message=fieldMessage(field); if(message) fieldError(field,message); else clearFieldError(field);
        });
        form.addEventListener('reset',()=>{for(const field of form.elements) clearFieldError(field); form.querySelector('.form-error').textContent='';});
    }
    function showFormError(form,error) {
        form.querySelector('.form-error').textContent=error.message;
        let first;
        for(const [name,messages] of Object.entries(error.fields || {})) {
            const field=form.elements.namedItem(name);
            if (field instanceof HTMLElement) {fieldError(field,messages[0]); first ||= field;}
        }
        first?.focus();
    }
    async function loadProfile() {
        const epoch=state.epoch, requestId=++profileRequest;
        if(!state.user) {$('profile').innerHTML=empty('Your profile','Sign in to manage your account and preferences.',true); return;}
        await withLoading(['profile'],'Loading your profile…',async()=>{
            const response=await api('/api/v1/profile'); if(epoch!==state.epoch || requestId!==profileRequest) return;
            state.user={...state.user,...response.data}; renderProfile();
        });
    }
    function renderProfile() {
        const user=state.user; if(!user) return;
        const joined=new Date(user.created_at).toLocaleDateString([],{year:'numeric',month:'short',day:'numeric'});
        $('profile').innerHTML=`<div class="profile-layout"><aside class="profile-summary">${avatar(user,true)}<h2>${esc(user.name)}</h2><dl><div><dt>Joined</dt><dd>${esc(joined)}</dd></div><div><dt>Workspaces</dt><dd>${state.companies.length}</dd></div><div><dt>Access</dt><dd>${user.is_admin?'Administrator':'Member'}</dd></div></dl><button class="profile-link selected" type="button">♙ &nbsp; Profile</button><button class="profile-link" data-tab="predictions">◈ &nbsp; Prediction history</button><button class="profile-link" data-tab="performance">↗ &nbsp; Performance</button><button class="profile-link danger" data-logout>↪ &nbsp; Sign out</button></aside><div class="profile-content"><h2>Profile</h2><div class="profile-identity">${avatar(user,true)}<div><h3>${esc(user.name)}</h3><p>Joined ${esc(joined)} · ${esc(user.email)}</p></div></div><form id="profile-form" class="profile-form"><div class="avatar-picker"><h3>Pick your avatar</h3><p>Choose a football identity for your account.</p><input type="hidden" name="avatar" value="${esc(user.avatar || 'football')}"><div class="avatar-options" role="group" aria-label="Choose an avatar">${Object.entries(avatarOptions).map(([key,option])=>`<button type="button" data-avatar="${key}" aria-label="${option[1]}" aria-pressed="${key===(user.avatar || 'football')}">${avatar({avatar:key})}</button>`).join('')}</div><small class="field-hint">Saved when you select Save changes.</small></div><h3 class="profile-section-title">Account</h3><label class="profile-field"><span>Email address</span><div><input type="email" value="${esc(user.email)}" readonly aria-describedby="email-help"><small id="email-help" class="field-hint">Email cannot be changed here.</small></div></label><label class="profile-field"><span>Display name</span><input name="name" value="${esc(user.name)}" required maxlength="100" autocomplete="name"></label><div class="form-error" role="alert"></div><button class="button primary" type="submit">Save changes</button></form><form id="password-form" class="profile-form"><h3 class="profile-section-title">Security</h3><p>Updating your password signs you out. Use your new password to sign in again.</p><label class="profile-field"><span>Current password</span><input name="current_password" type="password" required autocomplete="current-password"></label><label class="profile-field"><span>New password</span><div><input name="password" type="password" required minlength="10" autocomplete="new-password"><small class="field-hint">Use at least 10 characters.</small></div></label><label class="profile-field"><span>Confirm password</span><input name="password_confirmation" type="password" required autocomplete="new-password"></label><div class="form-error" role="alert"></div><button class="button secondary" type="submit">Change password</button></form></div></div>`;
        bindForm('profile-form',async form=>{
            const epoch=state.epoch, response=await api('/api/v1/profile',{method:'PATCH',data:Object.fromEntries(new FormData(form))});
            if(epoch!==state.epoch) return; state.user={...state.user,...response.data}; renderIdentity(); renderProfile(); notice('Your profile has been saved.');
        });
        bindForm('password-form',async form=>{
            await api('/api/v1/profile/password',{method:'PUT',data:Object.fromEntries(new FormData(form))});
            clearIdentity(); await loadPrivate(); setTab('profile'); notice('Password changed. Sign in with your new password.'); $('auth-dialog').showModal();
        });
    }
    function clearIdentity() {
        state.user=null; state.companies=[]; state.company=null; state.epoch++; state.keys.clear(); state.detailVersion++;
        $('detail-dialog').close(); renderIdentity();
        for(const id of ['providers','provider-usage','admin-overview','track-record','performance','profile']) $(id).innerHTML='';
    }
    async function signOut(button) {
        const restore=buttonLoading(button,'Signing out…');
        try {await api('/session/logout',{method:'POST',data:{}}); clearIdentity(); setTab('matches'); await loadPrivate(); notice('You are signed out.');}
        catch(error) {notice(error.message,true);} finally {restore();}
    }
    function companyPath(path) { return `/api/v1/companies/${state.company}${path}`; }
    function empty(title, description, signin = false) { return `<div class="empty"><strong>${esc(title)}</strong>${esc(description)}${signin ? '<br><button class="button primary" data-signin>Sign in →</button>' : ''}</div>`; }
    function probability(probs) {
        if (!probs) return '';
        return `<div class="probability-bar" role="img" aria-label="Home ${pct(probs.home_win)}, draw ${pct(probs.draw)}, away ${pct(probs.away_win)}">${['home_win','draw','away_win'].map((key,i) => `<span class="${['home','draw','away'][i]}" style="width:${Math.max(0,Math.min(100,Number(probs[key])*100))}%"></span>`).join('')}</div><div class="probability-labels"><span>Home ${pct(probs.home_win)}</span><span>Draw ${pct(probs.draw)}</span><span>Away ${pct(probs.away_win)}</span></div>`;
    }
    function setTab(tab, navigate = true) {
        if (!['matches','predictions','performance','standings','providers','profile'].includes(tab)) tab='matches';
        if (tab === 'providers' && !state.user?.is_admin) return;
        state.tab = tab;
        document.body.classList.toggle('operations-mode',tab==='providers');
        if(navigate) history.pushState(null,'',tab==='profile'?'/profile':tab==='matches'?'/':`/#${tab}`);
        document.querySelector('.metrics[aria-label]').hidden = ['profile','providers'].includes(tab);
        document.querySelector('.page-heading').hidden = tab==='profile';
        document.querySelectorAll('[data-tab]').forEach(el => { const active = el.dataset.tab === tab; el.classList.toggle('active',active); el.setAttribute('aria-current',active ? 'page' : 'false'); el.setAttribute('aria-controls',`${el.dataset.tab}-panel`); });
        document.querySelectorAll('.tab-panel').forEach(el => el.hidden = el.id !== `${tab}-panel`);
        const headings = {profile:['Profile','Your space at Bolum','Manage your account.'],standings:['League table','The season in perspective','Follow the standings, then explore each match in context.'],matches:['Match center','A better view of the game','Explore the fixtures. Find the probabilities. Follow the results.'],predictions:['Predictions','Every prediction. One place','A clear record of your workspace’s requests and outcomes.'],performance:['Performance','Let the results speak','Evaluate what was predicted before the final whistle.'],providers:['Operations','Keep Bolum running','Monitor processing, maintain fixtures and manage the data sources.']};
        $('page-label').textContent = headings[tab][0]; $('page-title').innerHTML = `${headings[tab][1]}<span>.</span>`; $('page-description').textContent = headings[tab][2];
        if (tab === 'profile') loadProfile().catch(error => notice(error.message,true));
        if (tab === 'standings') loadStandings().catch(error => notice(error.message,true));
        if (tab === 'performance') loadPerformance().catch(error => notice(error.message,true));
        if (tab === 'providers') {loadProviders().catch(error => notice(error.message,true)); if(state.adminView==='record') loadTrackRecord().catch(error=>notice(error.message,true));}
    }
    function renderIdentity() {
        $('account').innerHTML = state.user ? `${esc(state.user.name)} ${avatar(state.user)}` : 'Sign in <span class="avatar">→</span>';
        $('account').title = state.user ? 'Open your profile' : 'Sign in';
        $('logout').hidden = !state.user;
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
            return `<article class="fixture-card"><div class="card-top"><span class="league-name">${esc(f.league.name)}${f.matchday ? ` · GW ${f.matchday}` : ''}</span>${badge(f.status)}</div><div class="teams"><div>${teamBadge(f.home_team)}<span class="team-name">${esc(f.home_team.name)}</span></div><div class="versus">${f.is_finished && f.score.home !== null ? `<strong>${f.score.home} : ${f.score.away}</strong>` : 'VS'}</div><div>${teamBadge(f.away_team,true)}<span class="team-name">${esc(f.away_team.name)}</span></div></div><div class="match-date">${esc(when(f.kickoff_at))} · ${f.source === 'local' ? 'Local fixture' : 'Synced fixture'}</div>${prediction?.result ? `<div class="prediction-preview">${probability(prediction.result.probabilities)}</div>` : ''}<div class="card-bottom"><span>${prediction ? `${esc(prediction.status)} prediction` : 'Explore the numbers'}</span><button data-fixture="${f.id}">View match <span>↗</span></button></div></article>`;
        }).join('') || empty('No fixtures found','Try another league or status filter.');
    }
    async function loadFixtures() {
        const requestId = ++fixtureRequest;
        updateMatchViews();
        $('fixtures-prev').disabled = $('fixtures-next').disabled = true;
        $('fixture-page-label').textContent = 'Loading fixtures…'; $('fixture-count').textContent = '…';
        return withLoading(['fixtures'], 'Finding fixtures…', async () => {
        const params = new URLSearchParams(new FormData($('filters')));
        if($('league-filter').value) params.set('league_id',$('league-filter').value); [...params.keys()].forEach(key => { if (!params.get(key)) params.delete(key); });
        if (params.get('status') === 'upcoming') { params.delete('status'); params.set('upcoming','1'); }
        params.set('page',state.page); params.set('per_page',9);
        const page = state.page, response = await api(`/api/v1/fixtures?${params}`).catch(error=>{if(requestId===fixtureRequest) throw error; return null;});
        if (!response || page !== state.page || requestId !== fixtureRequest) return;
        state.fixtures = response.data; renderFixtures(true); $('fixture-count').textContent = response.meta.total;
        const league = state.leagues.find(l => String(l.id) === $('league-filter').value);
        $('fixture-context').textContent = league?.source === 'football-data' ? 'Real fixtures from football-data.org. Kickoff times are shown in your local time.' : 'Browse imported and local fixtures. Local sample schedules are for demonstration.';
        $('fixture-page-label').textContent = `${response.meta.total} fixtures · Page ${response.meta.current_page} of ${response.meta.last_page}`;
        $('fixtures-prev').disabled = !response.links.prev; $('fixtures-next').disabled = !response.links.next;
        }).finally(() => { if (requestId === fixtureRequest && $('fixtures').querySelector('.load-error')) { $('fixture-count').textContent = '—'; $('fixture-page-label').textContent = 'Fixtures could not be loaded.'; } });
    }
    async function loadLeagues() {
        const previous=$('league-filter').value;
        const [response,optionsResponse]=await Promise.all([api('/api/v1/leagues?per_page=100'),api('/api/v1/fixtures/filter-options')]);
        state.leagues=response.data; state.rounds=optionsResponse.data;
        const imported=state.leagues.filter(l=>l.source==='football-data'), available=imported.length?imported:state.leagues;
        const options=available.map(l=>`<option value="${l.id}">${esc(l.name)}</option>`).join('');
        $('fixture-league').innerHTML=state.leagues.map(l=>`<option value="${l.id}">${esc(l.name)}</option>`).join('');
        $('league-filter').innerHTML=options;
        $('league-filter').disabled=available.length<=1;
        if(available.some(l=>String(l.id)===previous)) $('league-filter').value=previous;
        $('league-filter-hint').textContent=imported.length===1?`${imported[0].name} is the only imported league. League selection unlocks when another league is added.`:available.length===1?'One league is available. League selection unlocks when another is added.':'';
        $('record-league').innerHTML=options; $('record-league').disabled=available.length<=1;
        $('standings-league').innerHTML=imported.map(l=>`<option value="${l.id}">${esc(l.name)}</option>`).join('') || '<option value="">No imported leagues</option>';
        matchRounds(); recordRounds(); state.catalogLoaded=true;
        if(state.tab==='standings') await loadStandings();
    }
    function populateRounds(league, season, matchday, past = false) {
        const rows=(state.rounds || []).filter(r=>String(r.league_id)===league.value && (!past || r.finished>0));
        const seasons=[...new Set(rows.map(r=>String(r.season)))].sort().reverse();
        const previousSeason=season.value, previousRound=matchday.value;
        season.innerHTML=seasons.map(value=>`<option value="${esc(value)}">${esc(value)}/${String(Number(value)+1).slice(-2)}</option>`).join('') || '<option value="">No season data</option>';
        if(seasons.includes(previousSeason)) season.value=previousSeason;
        season.disabled=!seasons.length;
        const rounds=rows.filter(r=>String(r.season)===season.value).sort((a,b)=>a.matchday-b.matchday);
        matchday.innerHTML=(past?'':'<option value="">All gameweeks</option>')+rounds.map(r=>`<option value="${r.matchday}">Gameweek ${r.matchday}${past?` · ${r.finished}/${r.fixtures} final`:''}</option>`).join('');
        if(!rounds.length && past) matchday.innerHTML='<option value="">No results yet</option>';
        matchday.disabled=!rounds.length;
        if(rounds.some(r=>String(r.matchday)===previousRound)) matchday.value=previousRound;
        else matchday.value=past?String(rounds.at(-1)?.matchday || ''):'';
    }
    function matchRounds() {populateRounds($('league-filter'),$('season-filter'),$('matchday-filter'));}
    function recordRounds() {populateRounds($('record-league'),$('record-season'),$('record-matchday'),true);}
    function setAdminView(view) {
        state.adminView=view;
        $('operations-overview').hidden=view!=='overview'; $('operations-record').hidden=view!=='record';
        document.querySelectorAll('[data-admin-view]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.adminView===view)));
        if(view==='record') loadTrackRecord().catch(error=>notice(error.message,true));
    }
    async function loadTrackRecord() {
        const epoch=state.epoch, requestId=++trackRequest;
        if(!state.company || !state.user?.is_admin) {$('track-record').innerHTML='';return;}
        if(!$('record-season').value || !$('record-matchday').value) {$('track-record').innerHTML=empty('No completed gameweeks yet','Import results to browse a gameweek’s track record.');return;}
        const params=new URLSearchParams(new FormData($('record-filters')));
        params.set('league_id',$('record-league').value);
        await withLoading(['track-record'],'Comparing saved forecasts with results…',async()=>{
            const response=await api(companyPath(`/track-record?${params}`)); if(epoch!==state.epoch || requestId!==trackRequest) return;
            const r=response.data, pick={home_win:'Home win',draw:'Draw',away_win:'Away win'};
            $('track-record').innerHTML=`<div class="track-summary"><article><small>Finished fixtures</small><strong>${r.finished} / ${r.fixtures}</strong></article><article><small>Evaluated forecasts</small><strong>${r.evaluated}</strong></article><article><small>Correct outcomes</small><strong>${r.correct} / ${r.evaluated}</strong></article><article><small>Outcome accuracy</small><strong>${r.accuracy===null?'—':pct(r.accuracy)}</strong></article></div><p class="panel-note">${esc(r.method)} ${r.missing_forecasts} fixtures have no eligible saved forecast. Missing forecasts are excluded from accuracy.</p><div class="table-wrap" role="region" aria-label="Gameweek prediction track record" tabindex="0"><table class="track-table"><thead><tr><th scope="col">Fixture</th><th scope="col">Final score</th><th scope="col">Bolum forecast</th><th scope="col">Outcome</th></tr></thead><tbody>${r.rows.map(row=>`<tr><td><strong>${esc(row.home_team)} vs ${esc(row.away_team)}</strong><small>${esc(when(row.kickoff_at))}</small></td><td>${row.score?`${row.score.home} – ${row.score.away}`:esc(row.status)}</td><td>${row.forecast?`<strong>${pick[row.forecast.pick]}</strong>${row.forecast.score?` · ${row.forecast.score.home}–${row.forecast.score.away}`:''}<small>${pct(row.forecast.probabilities[row.forecast.pick])} probability · Saved ${esc(when(row.forecast.created_at))}</small>`:'No saved pre-match forecast'}</td><td><span class="track-status ${row.correct===null?'missing':row.correct?'correct':'incorrect'}">${row.correct===null?(row.forecast?'Awaiting final result':'Not evaluated'):row.correct?'Correct':'Incorrect'}</span></td></tr>`).join('') || '<tr><td colspan="4">No fixtures in this gameweek.</td></tr>'}</tbody></table></div><p class="panel-note">This is a record of forecasts actually saved before kickoff. A prediction calculated today for a past match would be a retrospective simulation, and is not included here.</p>`;
        });
    }
    async function loadStandings() {
        const requestId = ++standingsRequest, league = $('standings-league').value;
        if (!league) { $('standings').innerHTML = empty('No league table available','Import a league from football-data.org to view its standings.'); return; }
        return withLoading(['standings'], 'Fetching the league table…', async () => {
            const response = await api(`/api/v1/leagues/${league}/standings`);
            if (requestId !== standingsRequest || league !== $('standings-league').value) return;
            const r = response.data;
            $('standings').innerHTML = `<p class="panel-note">${esc(r.league_name)} · ${r.season}/${String(Number(r.season)+1).slice(-2)} · football-data.org · Fetched ${esc(when(r.fetched_at))}. Updates are cached for 10 minutes.</p><div class="table-wrap standings-wrap" tabindex="0" role="region" aria-label="League standings, scroll horizontally for all columns"><table class="standings-table"><caption class="sr-only">${esc(r.league_name)} standings</caption><thead><tr>${['Pos','Club','P','W','D','L','GF','GA','GD','Pts'].map(h => `<th scope="col">${h}</th>`).join('')}</tr></thead><tbody>${r.rows.map(row => `<tr><td class="table-position">${row.position}</td><th scope="row"><span class="table-club">${teamBadge(row.team)}<span>${esc(row.team.name)}</span></span></th>${['played','won','drawn','lost','goals_for','goals_against','goal_difference'].map(k => `<td>${row[k]}</td>`).join('')}<td class="table-points">${row.points}</td></tr>`).join('')}</tbody></table></div><p class="panel-note">P: played · W/D/L: wins, draws, losses · GF/GA: goals for/against · GD: goal difference · Pts: points. Positions and points follow the provider’s table.</p>`;
        });
    }
    function sourceEvidence(source) {
        const e = source.evidence;
        if (!e) return '';
        return `<div class="model-evidence"><p><strong>Based on recorded results</strong> · ${e.league_matches} league matches in the last year. Home team: ${e.home_matches} matches (${e.home_venue_matches} at home). Away team: ${e.away_matches} matches (${e.away_venue_matches} away).</p><p>Inputs saved ${esc(when(e.cutoff_at))} · ${esc(e.model_version)}. Recent matches carry more weight; small samples are smoothed toward league averages.</p>${e.limited_sample ? '<p class="sample-caution">Limited venue history: fewer than five matches for at least one team.</p>' : ''}<p>This score-based estimate does not use shot-based xG, injuries or lineups. Forecasting accuracy has not yet been established.</p></div>`;
    }
    function recentForm(fixture) {
        if (fixture.source !== 'football-data' || !state.form) return '';
        return `<div class="detail-block recent-form"><h3>Recent form</h3><p>Latest recorded league matches first · before ${esc(when(state.form.cutoff_at))}.</p><div class="form-grid">${['home','away'].map(side => `<section><h4>${esc(fixture[side+'_team'].name)}</h4>${state.form[side]?.length ? `<ol class="form-results">${state.form[side].map(m => `<li><span class="form-outcome outcome-${esc(m.outcome)}" aria-label="${{W:'Win',D:'Draw',L:'Loss'}[m.outcome]}">${esc(m.outcome)}</span><div><strong>${m.goals_for}–${m.goals_against}</strong> vs ${esc(m.opponent)}<small>${esc(m.venue)} · ${esc(when(m.kickoff_at))}</small></div></li>`).join('')}</ol>` : '<p>No eligible results recorded yet. Form appears as results are imported.</p>'}</section>`).join('')}</div></div>`;
    }
    async function loadPrivate({quiet = false} = {}) {
        if (quiet && $('predictions').getAttribute('aria-busy') === 'true') return;
        const requestId = ++privateRequest;
        const epoch = state.epoch;
        if (!state.company) {
            state.predictions = []; $('prediction-count').textContent = '—'; $('credit-count').textContent = '—';
            $('predictions').innerHTML = empty('Your predictions belong here','Sign in to view your workspace’s prediction history.',true);
            $('ledger').innerHTML = empty('A clear credit trail','Sign in to see grants, debits and refunds.',true);
            $('prediction-page-label').textContent = ''; $('predictions-prev').disabled = $('predictions-next').disabled = true; renderFixtures(); return;
        }
        if (!quiet) { $('prediction-count').textContent = $('credit-count').textContent = '…'; $('predictions-prev').disabled = $('predictions-next').disabled = true; $('prediction-page-label').textContent = 'Loading history…'; }
        return withLoading(['predictions','ledger'], 'Loading your workspace activity…', async () => {
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
        const version = ++state.detailVersion; state.selected = null; state.prediction = null; state.detailTarget = {id,predictionId}; state.club = null; $('detail-title').textContent = 'Loading fixture…';
        if (!$('detail-dialog').open) $('detail-dialog').showModal();
        return withLoading(['detail-body'], 'Loading match analysis…', async () => {
        const fixture = await api(`/api/v1/fixtures/${id}`); if (version !== state.detailVersion) return;
        state.selected = fixture.data; state.form = fixture.form;
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
        let html = `<div class="detail-clubs"><div>${teamBadge(f.home_team)}<strong>${esc(f.home_team.name)}</strong></div><span class="versus">VS</span><div>${teamBadge(f.away_team,true)}<strong>${esc(f.away_team.name)}</strong></div></div><div class="detail-meta">${esc(f.league.name)} · ${esc(when(f.kickoff_at))} ${badge(f.status)}</div>`;
        html += '<div id="prediction-feedback" aria-live="polite"></div>';
        if (p) html += `<p>Prediction #${p.id} · ${esc(when(p.created_at))} ${badge(p.status)}</p>`;
        if (p?.status === 'pending') html += `<div class="notice queued-state" role="status">${spinner}<div><strong>Your prediction is queued.</strong><br>Waiting for a worker to calculate your result. This page updates automatically.</div></div><div class="prediction-progress" aria-busy="true"><div class="prediction-steps"><span>✓ Request accepted</span><span class="current">◌ Awaiting result</span><span>Analysis ready</span></div><div class="skeleton-line"></div><div class="skeleton-line short"></div><p id="prediction-sync">Checking for your result every few seconds…</p></div>`;
        if (p?.status === 'failed') html += `<div class="notice error">${esc(p.error)}</div>`;
        if (p?.result) {
            const r = p.result;
            html += `<p>${r.data_quality === 'sample' ? 'Synthetic sample inputs · for exploring the workflow.' : r.data_quality === 'mixed' ? 'Mixed sample and external inputs.' : 'Real match data inputs.'} Model: ${esc(r.model)}.</p><div class="detail-score"><div><span>Home expected goals</span><strong>${number(r.expected_goals.home)}</strong></div><div><span>Most likely score</span><strong>${r.most_likely_score.home} : ${r.most_likely_score.away}</strong></div><div><span>Away expected goals</span><strong>${number(r.expected_goals.away)}</strong></div></div><div class="detail-block"><h3>Match outcome</h3>${probability(r.probabilities)}</div>`;
            if (r.totals) html += `<div class="detail-block"><h3>Goal probabilities</h3><div class="total-grid">${[['over_1_5','Over 1.5'],['over_2_5','Over 2.5'],['over_3_5','Over 3.5'],['both_teams_score','Both teams score']].map(([key,label]) => `<div>${label}<strong>${pct(r.totals[key])}</strong></div>`).join('')}</div></div>`;
            if (r.scorelines) html += `<div class="detail-block"><h3>Likely scorelines</h3><div class="scorelines">${r.scorelines.map(s => `<div>${s.home} : ${s.away}<small>${pct(s.probability)}</small></div>`).join('')}</div></div>`;
            html += `<div class="detail-block"><h3>By data source</h3>${r.sources?.map(s => `<div class="source-row"><div><strong>${esc(s.name)}</strong><small>Weight ${number(s.weight)} · Expected goals ${number(s.expected_goals.home)} / ${number(s.expected_goals.away)}</small></div>${probability(s.probabilities)}${sourceEvidence(s)}</div>`).join('') || '<p>This older prediction does not include a source breakdown.</p>'}</div>`;
        }
        if (canPredict && p?.status !== 'pending') html += `<button id="generate" class="button primary full">${state.user ? (p ? 'Generate a new prediction · 1 credit' : 'Generate prediction · 1 credit') : 'Sign in to generate a prediction →'}</button><p>Repeated delivery of the same request never spends another credit.</p>`;
        if (!p && !canPredict) html += '<p>New predictions are available only before a scheduled fixture starts.</p>';
        html += recentForm(f);
        if (f.source === 'football-data' && f.home_team.id && f.away_team.id) html += `<section class="detail-block club-guide"><h3>Club guide</h3><p class="panel-note">Explore the clubs and their stadiums.</p><div class="club-guide-buttons">${[f.home_team,f.away_team].map(t=>`<button class="button secondary" data-club="${t.id}">${esc(t.name)} info</button>`).join('')}</div><div id="club-profile" aria-live="polite">${clubMarkup()}</div></section>`;
        if (state.user?.is_admin && new Date(f.kickoff_at) <= new Date() && !['postponed','cancelled'].includes(f.status)) html += `<div class="detail-block"><h3>Record final score</h3><form id="result-form" class="result-form"><label>Home<input name="home_goals" type="number" min="0" max="100" value="${f.score.home ?? 0}" required></label><label>Away<input name="away_goals" type="number" min="0" max="100" value="${f.score.away ?? 0}" required></label><button class="button primary">Save result</button><div class="form-error" role="alert"></div></form></div>`;
        $('detail-body').innerHTML = html;
        if($('result-form')) setupValidation($('result-form'));
    }
    function clubMarkup() {
        const club = state.club;
        if (!club) return '<p class="panel-note">Choose a club to load information from TheSportsDB.</p>';
        if (club.status === 'loading') return `<div class="notice" role="status" aria-busy="true">${spinner} Loading club information…</div>`;
        if (club.status === 'error') return `<div class="notice error" role="alert">${esc(club.error)} <button class="button secondary" data-club="${club.teamId}">Retry club information</button></div>`;
        const p = club.data;
        const sourceUrl = /^https:\/\/www\.thesportsdb\.com\/team\/[0-9]+$/.test(p.source_url || '') ? p.source_url : 'https://www.thesportsdb.com';
        return `<article class="club-profile-card"><h4>${esc(p.name)}</h4><dl><div><dt>Stadium</dt><dd>${esc(p.stadium || 'Not provided')}</dd></div><div><dt>Location</dt><dd>${esc(p.location || 'Not provided')}</dd></div><div><dt>Founded</dt><dd>${esc(p.formed_year || 'Not provided')}</dd></div></dl>${p.description ? `<p>${esc(p.description)}</p>` : ''}<small>Club information from <a href="${esc(sourceUrl)}" target="_blank" rel="noopener noreferrer">TheSportsDB ↗</a> · Retrieved ${esc(when(p.fetched_at))}. Not used in prediction calculations.</small></article>`;
    }
    async function loadClubProfile(button) {
        const teamId = Number(button.dataset.club), version = state.detailVersion;
        if (!Number.isInteger(teamId) || !state.selected || ![state.selected.home_team.id,state.selected.away_team.id].includes(teamId)) return;
        const request = {teamId,status:'loading'}; state.club = request;
        $('club-profile').innerHTML = clubMarkup();
        const restore = buttonLoading(button,'Loading club…');
        try {
            const response = await api(`/api/v1/teams/${teamId}/profile`);
            if (version !== state.detailVersion || state.club !== request) return;
            state.club = {teamId,status:'ready',data:response.data};
        } catch (error) {
            if (version !== state.detailVersion || state.club !== request) return;
            state.club = {teamId,status:'error',error:error.message};
        } finally {
            restore();
            if (version === state.detailVersion && state.club?.teamId === teamId && $('club-profile')) $('club-profile').innerHTML = clubMarkup();
        }
    }
    async function generate(button) {
        if (!state.user) { $('detail-dialog').close(); $('auth-dialog').showModal(); return; }
        const epoch = state.epoch, version = state.detailVersion, f = state.selected, keyId = `${state.company}:${f.id}`;
        const key = state.keys.get(keyId) || crypto.randomUUID(); state.keys.set(keyId,key);
        if(button.disabled) return;
        buttonLoading(button, 'Requesting prediction…');
        $('prediction-feedback').innerHTML=`<div class="prediction-requesting" role="status">${spinner}<div><strong>Requesting your prediction</strong><p>Checking match data and available credits…</p></div></div>`;
        try {
            const response = await api(companyPath(`/fixtures/${f.id}/predictions`),{method:'POST',data:{},headers:{'Idempotency-Key':key}});
            state.keys.delete(keyId); if (epoch !== state.epoch) return;
            if (version === state.detailVersion && state.selected?.id === f.id) { state.prediction = response.data; renderDetail(); } await loadPrivate(); notice('Prediction requested. Your result will appear here.');
        } catch(error) { if(epoch!==state.epoch || version!==state.detailVersion) return; button.disabled = false; button.removeAttribute('aria-busy'); button.textContent = 'Retry prediction request · 1 credit'; $('prediction-feedback').innerHTML=`<div class="notice error" role="alert">${esc(error.message)}</div>`; }
    }
    async function loadPerformance() {
        const requestId = ++performanceRequest;
        if (!state.company) { $('performance').innerHTML = empty('Measure your predictions','Sign in to view your workspace’s performance.',true); return; }
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
        return withLoading(['providers','provider-usage','admin-overview'], 'Loading data sources…', async () => {
        const [providers,usage,overview] = await Promise.all([api('/api/v1/providers?per_page=100',{background:quiet}),api('/api/v1/providers/usage',{background:quiet}),api('/api/v1/admin/overview',{background:quiet})]);
        if (epoch !== state.epoch || !state.user?.is_admin || requestId !== providerRequest) return;
        state.providers = providers.data;
        const o=overview.data;
        $('admin-overview').innerHTML=`<div class="operations-stats">${[['Active providers',o.active_providers],['Pending predictions',o.pending_predictions],['Waiting over 5 minutes',o.stale_predictions],['Failed predictions · 24h',o.failed_predictions_24h],['Provider errors · 24h',o.provider_errors_24h],['Imported fixtures',o.imported_fixtures]].map(([label,count])=>`<article><small>${label}</small><strong>${count}</strong></article>`).join('')}</div><p class="panel-note">Fixtures last updated: ${o.fixtures_updated_at?esc(when(o.fixtures_updated_at)):'No imported fixtures yet'}. Pending counts show recorded work, not whether a worker is online. Failed predictions are refunded; they remain in history.</p>`;
        $('providers').innerHTML = providers.data.map(p => `<article class="fixture-card provider-card">${badge(p.is_active ? 'active' : 'paused')}<h3>${esc(p.name)}</h3><p>${p.driver === 'sample' ? 'Synthetic sample inputs' : p.driver === 'results' ? 'Recorded match results · local model' : 'Server-configured HTTP gateway'} · Weight ${esc(p.weight)}</p><button class="button secondary" data-provider="${p.id}">Configure ↗</button></article>`).join('') || empty('No providers configured','Add a match-results, sample or HTTP provider.');
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
        setupValidation($(id));
        $(id).addEventListener('submit',async event => {
            event.preventDefault(); const form = event.currentTarget, button = form.querySelector('button[type=submit], button.primary');
            const errorEl = form.querySelector('.form-error'); errorEl.textContent = '';
            if(form.dataset.submitting==='true' || !validateForm(form)) return;
            form.dataset.submitting='true';
            const restore = buttonLoading(button, id === 'login-form' ? 'Signing in…' : id === 'register-form' ? 'Creating workspace…' : 'Saving…');
            try { await handler(form); } catch(error) { showFormError(form,error); } finally { form.dataset.submitting='false'; restore(); }
        });
    }
    bindForm('login-form',async form => {
        await api('/session/login',{method:'POST',data:Object.fromEntries(new FormData(form))}); form.reset(); $('auth-dialog').close(); await loadIdentity(); await loadPrivate(); if(state.tab==='performance') await loadPerformance(); if(state.tab==='profile') await loadProfile(); notice('Welcome back. Your workspace is ready.');
    });
    bindForm('register-form',async form => {
        await api('/session/register',{method:'POST',data:Object.fromEntries(new FormData(form))}); form.reset(); $('register-dialog').close(); await loadIdentity(); await loadPrivate(); if(state.tab==='profile') await loadProfile(); notice('Workspace created with 10 demo credits.');
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
            else if (target.hasAttribute('data-logout')) await signOut(target);
            else if (target.dataset.avatar) { $('profile-form').elements.avatar.value=target.dataset.avatar; document.querySelectorAll('[data-avatar]').forEach(button=>button.setAttribute('aria-pressed',String(button===target))); }
            else if (target.dataset.adminView) setAdminView(target.dataset.adminView);
            else if (target.dataset.tab) setTab(target.dataset.tab);
            else if (target.hasAttribute('data-match-view')) { $('status-filter').value = target.dataset.matchView; $('filters').requestSubmit(); }
            else if (target.dataset.retry) {
                const retries = {'track-record':loadTrackRecord,profile:loadProfile,'admin-overview':loadProviders,standings:loadStandings,fixtures:loadFixtures,predictions:loadPrivate,ledger:loadPrivate,performance:loadPerformance,providers:loadProviders,'provider-usage':loadProviders,'detail-body':() => openFixture(state.detailTarget.id,state.detailTarget.predictionId)};
                await retries[target.dataset.retry]?.(); $('notice').hidden = true;
            }
            else if (target.hasAttribute('data-signin')) $('auth-dialog').showModal();
            else if (target.dataset.club) await loadClubProfile(target);
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
        const form = event.target, button = form.querySelector('button'); if(!validateForm(form)) return; const restore=buttonLoading(button,'Saving score…');
        try { await api(`/api/v1/fixtures/${state.selected.id}/result`,{method:'PUT',data:Object.fromEntries(new FormData(form))}); await openFixture(state.selected.id); await loadFixtures(); notice('Final score recorded.'); }
        catch(error) { showFormError(form,error); } finally {restore();}
    });
    $('account').onclick = () => state.user ? setTab('profile') : $('auth-dialog').showModal();
    $('logout').onclick = () => signOut($('logout'));
    $('show-register').onclick = () => { $('auth-dialog').close(); $('register-dialog').showModal(); };
    $('company').onchange = async () => { state.company=Number($('company').value); state.epoch++; state.historyPage=1; state.predictions=[]; state.detailVersion++; $('detail-dialog').close(); renderIdentity(); $('prediction-count').textContent = $('credit-count').textContent = '…'; $('predictions').innerHTML = $('ledger').innerHTML = empty('Loading workspace data…',''); $('performance').innerHTML = ''; $('track-record').innerHTML = ''; renderFixtures(); try { await loadPrivate(); if(state.tab==='performance') await loadPerformance(); if(state.tab==='providers' && state.adminView==='record') await loadTrackRecord(); } catch(error) { notice(error.message,true); } };
    $('reset-filters').onclick = () => { $('search').value = ''; $('league-filter').selectedIndex=0; $('season-filter').value=''; matchRounds(); $('matchday-filter').value=''; $('status-filter').value = 'upcoming'; $('filters').requestSubmit(); };
    $('search').addEventListener('input',()=>{clearTimeout(searchTimer); fixtureRequest++; state.page=1; searchTimer=setTimeout(()=>$('filters').requestSubmit(),300);});
    for(const id of ['league-filter','season-filter','matchday-filter','status-filter']) $(id).addEventListener('change',()=>{
        if(['league-filter','season-filter'].includes(id)) { $('matchday-filter').value=''; matchRounds(); }
        $('filters').requestSubmit();
    });
    $('record-filters').onsubmit=async event=>{event.preventDefault();const restore=buttonLoading($('record-filters').querySelector('button'),'Loading report…');try{await loadTrackRecord();}catch(error){notice(error.message,true);}finally{restore();}};
    for(const id of ['record-league','record-season','record-matchday','record-quality']) $(id).onchange=()=>{if(['record-league','record-season'].includes(id)){$('record-matchday').value='';recordRounds();}loadTrackRecord().catch(error=>notice(error.message,true));};
    $('filters').onsubmit = async event => { event.preventDefault(); clearTimeout(searchTimer); state.page=1; const restore=buttonLoading($('filters').querySelector('button'),'Finding matches…'); try { await loadFixtures(); } catch(error) { notice(error.message,true); } finally { restore(); } };
    for (const [id,delta] of [['fixtures-prev',-1],['fixtures-next',1]]) $(id).onclick = () => { state.page+=delta; loadFixtures().catch(error => notice(error.message,true)); };
    for (const [id,delta] of [['predictions-prev',-1],['predictions-next',1]]) $(id).onclick = () => { state.historyPage+=delta; loadPrivate().catch(error => notice(error.message,true)); };
    $('refresh-predictions').onclick = async () => { const restore=buttonLoading($('refresh-predictions'),'Refreshing…'); try { await loadPrivate(); } catch(error) { notice(error.message,true); } finally { restore(); } };
    $('standings-league').onchange = () => loadStandings().catch(error => notice(error.message,true));
    $('refresh-standings').onclick = async () => { const restore = buttonLoading($('refresh-standings'),'Refreshing…'); try { await loadStandings(); } catch(error) { notice(error.message,true); } finally { restore(); } };
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
            if (state.tab==='providers' && state.adminView!=='record') await loadProviders({quiet:true});
        } catch(error) { if($('prediction-sync')) $('prediction-sync').textContent='Unable to refresh the result. Retrying automatically…'; if(error.status===401) { notice('Your session expired. Sign in again.',true); state.company=null; } }
        finally { state.polling=false; }
    }
    document.querySelectorAll('.sidebar [data-tab]').forEach(button=>button.title=button.textContent.replace(/\d+/g,'').trim());
    document.querySelector('[data-tab=matches]').setAttribute('aria-current','page');
    $('fixtures').innerHTML = skeleton('Loading your match center…',true);
    $('fixtures').setAttribute('aria-busy','true');
    function currentTab() {return location.pathname==='/profile'?'profile':location.hash.slice(1)||'matches';}
    addEventListener('popstate',()=>setTab(currentTab(),false));
    (async () => { try { await Promise.all([loadIdentity(),loadLeagues()]); setTab(currentTab(),false); await Promise.all([loadFixtures(),loadPrivate()]); } catch(error) { notice(error.message,true); if (!$('fixtures').querySelector('.load-error')) { $('fixtures').innerHTML=empty('Unable to load the workspace','Reload the page to try again.'); $('fixtures').setAttribute('aria-busy','false'); } } })();
    setInterval(poll,4000);
})();
