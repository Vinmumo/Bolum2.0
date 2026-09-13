import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

// Each scenario keeps real rate limiting but starts with its own request budget.
// This is the temporary database created by playwright.config.js, not the app DB.
test.beforeEach(() => {
    if (!process.env.BOLUM_BROWSER_DATABASE) throw new Error('Temporary browser database required.');
    execFileSync('php',['artisan','cache:clear'],{env:{...process.env,APP_ENV:'local',DB_CONNECTION:'sqlite',DB_DATABASE:process.env.BOLUM_BROWSER_DATABASE,CACHE_STORE:'database'}});
});

test('single imported league stays selected while all match statuses remain available', async ({ page }) => {
    await page.route('**/api/v1/leagues?*', route => route.fulfill({json:{data:[
        {id:1,name:'Demo Premier League',country:'England',source:'local'},
        {id:2,name:'Premier League',country:'England',source:'football-data'},
    ]}}));
    const requests = [];
    await page.route('**/api/v1/fixtures?*', route => {
        requests.push(new URL(route.request().url()));
        return route.fulfill({json:{data:[{
            id:100,league:{id:2,name:'Premier League'},home_team:{name:'Arsenal FC'},away_team:{name:'Liverpool FC'},
            kickoff_at:new Date(Date.now()+86400000).toISOString(),status:'scheduled',is_finished:false,matchday:4,source:'football-data',score:{home:null,away:null},
        }],meta:{total:1,current_page:1,last_page:1},links:{prev:null,next:null}}});
    });
    await page.goto('/');
    await expect(page.locator('#league-filter')).toHaveValue('2');
    await expect(page.locator('#status-filter')).toHaveValue('upcoming');
    await expect(page.locator('.fixture-card')).toContainText('Arsenal FC');
    await expect(page.locator('#fixture-context')).toContainText('Real fixtures from football-data.org');
    expect(requests[0].searchParams.get('league_id')).toBe('2');
    expect(requests[0].searchParams.get('upcoming')).toBe('1');
    expect(requests[0].searchParams.has('status')).toBe(false);
    await expect(page.locator('#league-filter')).toBeDisabled();
    await page.locator('#status-filter').selectOption('');
    await page.getByRole('button', {name:'Apply filters'}).click();
    await expect(page.locator('#fixture-context')).toContainText('Real fixtures');
    expect(requests.at(-1).searchParams.get('league_id')).toBe('2');
    expect(requests.at(-1).searchParams.has('upcoming')).toBe(false);
});

async function signIn(page, email = 'admin@bolum.test') {
    await page.goto('/');
    await page.getByRole('button', { name: 'Sign in', exact: false }).first().click();
    await page.locator('#login-form input[name=email]').fill(email);
    await page.locator('#login-form input[name=password]').fill('password123');
    await page.locator('#login-form button[type=submit]').click();
    await expect(page.locator('#auth-dialog')).not.toBeVisible();
    await expect(page.locator('#company')).toBeVisible();
}

test('official crests load on cards and details while broken images retain initials', async ({ page }) => {
    const fixture = {
        id:100,league:{id:1,name:'Premier League'},
        home_team:{name:'Arsenal FC',crest_url:'https://crests.football-data.org/1.svg'},
        away_team:{name:'Liverpool FC',crest_url:'https://crests.football-data.org/2.png'},
        kickoff_at:new Date(Date.now()+86400000).toISOString(),status:'scheduled',is_finished:false,matchday:4,source:'football-data',score:{home:null,away:null},
    };
    await page.route('https://crests.football-data.org/1.svg', route => route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><circle cx="24" cy="24" r="20" fill="red"/></svg>'}));
    await page.route('https://crests.football-data.org/2.png', route => route.abort());
    await page.route('**/api/v1/fixtures?*', route => route.fulfill({json:{data:[fixture],meta:{total:1,current_page:1,last_page:1},links:{prev:null,next:null}}}));
    await page.route('**/api/v1/fixtures/100', route => route.fulfill({json:{data:fixture}}));
    await page.goto('/');
    await expect(page.locator('#fixtures .team-crest.loaded')).toHaveCount(1);
    await expect(page.locator('#fixtures .team-crest')).toHaveAttribute('referrerpolicy','no-referrer');
    await expect(page.locator('#fixtures .crest-fallback').last()).toBeVisible();
    await expect(page.locator('#fixtures .crest-fallback').last()).toHaveText('LF');
    await page.locator('[data-fixture]').click();
    await expect(page.locator('#detail-body .team-crest.loaded')).toHaveCount(1);
    await expect(page.locator('#detail-body .crest-fallback').last()).toBeVisible();
    await expect(page.locator('#detail-body')).toContainText('Arsenal FC');
});

test('dark theme is the default and the explicit light preference survives reloads', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    await expect(page.getByRole('button',{name:'Switch to light theme'})).toBeVisible();
    await page.getByRole('button',{name:'Switch to light theme'}).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme','light');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme','light');
    await page.getByRole('button',{name:'Switch to dark theme'}).click();
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme','dark');
    expect(await page.evaluate(() => Object.keys(localStorage))).toEqual(['bolum.theme']);
});

test('match shortcuts and reset keep the visible filters consistent', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    await page.getByRole('button',{name:'Results',exact:true}).click();
    await expect(page.locator('#status-filter')).toHaveValue('finished');
    await expect(page.getByRole('button',{name:'Results',exact:true})).toHaveAttribute('aria-pressed','true');
    await expect(page.locator('#fixtures')).toContainText('No fixtures found');
    await page.getByRole('button',{name:'Reset',exact:true}).click();
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    await expect(page.getByRole('button',{name:'Upcoming',exact:true})).toHaveAttribute('aria-pressed','true');
    await expect(page.locator('#status-filter')).toHaveValue('upcoming');
});

test('both themes keep key text readable and mobile cards usable', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    for (const theme of ['dark','light']) {
        if (theme === 'light') await page.getByRole('button',{name:'Switch to light theme'}).click();
        const ratios = await page.evaluate(() => {
            const luminance = color => {
                const values = color.match(/[\d.]+/g).slice(0,3).map(Number).map(n => { const c=n/255; return c<=.04045 ? c/12.92 : ((c+.055)/1.055)**2.4; });
                return values[0]*.2126 + values[1]*.7152 + values[2]*.0722;
            };
            return ['.team-name','.match-date','.league-name','.card-bottom button','.metric small','.status.scheduled'].map(selector => {
                const el=document.querySelector(selector); let parent=el, bg;
                while (parent) { bg=getComputedStyle(parent).backgroundColor; if (bg!=='rgba(0, 0, 0, 0)' && bg!=='transparent') break; parent=parent.parentElement; }
                const a=luminance(getComputedStyle(el).color), b=luminance(bg);
                return {selector,ratio:(Math.max(a,b)+.05)/(Math.min(a,b)+.05)};
            });
        });
        for (const result of ratios) expect(result.ratio,`${theme} ${result.selector}`).toBeGreaterThanOrEqual(4.5);
        await page.setViewportSize({width:390,height:844});
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        expect(await page.locator('.fixture-card').first().evaluate(el=>el.getBoundingClientRect().width)).toBeGreaterThan(300);
        expect(await page.locator('#league-filter').evaluate(el=>el.getBoundingClientRect().width)).toBeGreaterThan(140);
        await page.screenshot({path:`/tmp/bolum-${theme}-mobile.png`,fullPage:true});
        await page.setViewportSize({width:1440,height:1000});
    }
});

test('keyboard users can skip navigation and switch theme without storage access', async ({ page }) => {
    await page.addInitScript(() => {
        Storage.prototype.getItem = () => { throw new Error('Storage unavailable'); };
        Storage.prototype.setItem = () => { throw new Error('Storage unavailable'); };
    });
    await page.goto('/');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link',{name:'Skip to content'})).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
    await page.getByRole('button',{name:'Switch to light theme'}).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('html')).toHaveAttribute('data-theme','light');
});

test('fixture loading holds its layout, recovers after errors, and respects reduced motion', async ({ page }) => {
    let release;
    const gate = new Promise(resolve => release = resolve);
    let attempt = 0;
    await page.route('**/api/v1/fixtures?*', async route => {
        attempt++;
        if (attempt === 1) { await gate; return route.continue(); }
        if (attempt === 2) return route.fulfill({status:503,json:{message:'Fixture feed temporarily unavailable.'}});
        return route.continue();
    });
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.goto('/');
    await expect(page.locator('#fixtures')).toHaveAttribute('aria-busy','true');
    await expect(page.locator('.skeleton-card')).toHaveCount(6);
    await expect(page.locator('#network-status')).toBeVisible();
    expect(await page.locator('.skeleton-line').first().evaluate(el => getComputedStyle(el).animationName)).toBe('none');
    await page.screenshot({path:'/tmp/bolum-loading-desktop.png',fullPage:true});
    release();
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    await expect(page.locator('#fixtures')).toHaveAttribute('aria-busy','false');
    await page.getByRole('button',{name:'Apply filters'}).click();
    await expect(page.locator('#fixtures')).toContainText('Fixture feed temporarily unavailable.');
    await expect(page.locator('#fixtures')).toHaveAttribute('aria-busy','false');
    await expect(page.locator('#network-status')).not.toBeVisible();
    await page.locator('#fixtures [data-retry]').click();
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    await expect(page.getByRole('button',{name:'Apply filters'})).toBeEnabled();
});

test('match details provide a recoverable loading state', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    let release;
    const gate = new Promise(resolve => release = resolve);
    let attempt = 0;
    await page.route(/\/api\/v1\/fixtures\/\d+$/, async route => {
        if (++attempt === 1) { await gate; return route.fulfill({status:503,json:{message:'Match details temporarily unavailable.'}}); }
        return route.continue();
    });
    await page.locator('[data-fixture]').first().click();
    await expect(page.locator('#detail-body')).toHaveAttribute('aria-busy','true');
    await expect(page.locator('#detail-body')).toContainText('Loading match analysis');
    release();
    await expect(page.locator('#detail-body')).toContainText('Match details temporarily unavailable.');
    await expect(page.locator('#detail-body')).toHaveAttribute('aria-busy','false');
    await page.locator('#detail-body [data-retry]').click();
    await expect(page.locator('#generate')).toBeVisible();
    await expect(page.locator('#detail-body .skeleton-row')).toHaveCount(0);
});

test('public match browser and responsive navigation', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/');
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    await page.locator('#search').fill('North London');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page.locator('.fixture-card')).toHaveCount(2);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: /Performance/ }).click();
    await expect(page.locator('#performance')).toContainText('Sign in');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(errors).toEqual([]);
});

test('cookie login requires CSRF and logout revokes session access', async ({ page }) => {
    await page.goto('/');
    const forged = await page.request.post('/session/login', { headers: {'Accept':'application/json','Sec-Fetch-Site':'cross-site','Origin':'https://untrusted.example'}, data:{email:'admin@bolum.test',password:'password123'} });
    const rejected = forged.status();
    expect(rejected).toBe(419);
    await signIn(page);
    expect(await page.evaluate(() => localStorage.length)).toBe(0);
    const me = await page.evaluate(async () => (await fetch('/api/v1/auth/me', { headers:{Accept:'application/json'} })).status);
    expect(me).toBe(200);
    await page.locator('#logout').click();
    await expect(page.locator('#account')).toContainText('Sign in');
    const after = await page.evaluate(async () => (await fetch('/api/v1/auth/me', { headers:{Accept:'application/json'} })).status);
    expect(after).toBe(401);
});

test('generate a prediction, process the worker and inspect source results', async ({ page }) => {
    await signIn(page);
    await page.locator('[data-fixture]').first().click();
    await expect(page.locator('#generate')).toBeVisible();
    await page.locator('#generate').click();
    await expect(page.locator('#detail-body')).toContainText('Your prediction is queued');
    execFileSync('php', ['artisan','queue:work','--once','--tries=3'], {
        env: {...process.env, APP_ENV:'local', DB_CONNECTION:'sqlite', DB_DATABASE:process.env.BOLUM_BROWSER_DATABASE, QUEUE_CONNECTION:'database', CACHE_STORE:'database'},
    });
    await expect(page.locator('#detail-body')).toContainText('By data source', { timeout:15000 });
    await expect(page.locator('#detail-body')).toContainText('Sample Football');
    await expect(page.locator('#detail-body')).toContainText('Goal probabilities');
    await expect(page.locator('#credit-count')).toHaveText('9');
    await page.screenshot({path:'/tmp/bolum-dashboard-result.png',fullPage:true});
    await page.locator('#detail-dialog [data-close]').click();
    await page.evaluate(() => window.scrollTo(0,0));
    await page.screenshot({path:'/tmp/bolum-dashboard-desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:'/tmp/bolum-dashboard-mobile.png',fullPage:true});
});

test('member permissions, owner top-up, and missing provider configuration', async ({ page }) => {
    await signIn(page, 'member@bolum.test');
    await expect(page.locator('[data-tab=providers]')).not.toBeVisible();
    await expect(page.locator('#topup-button')).not.toBeVisible();
    await page.locator('#logout').click();
    await signIn(page);
    await expect(page.locator('#credit-count')).toHaveText(/^\d+$/);
    const original = Number(await page.locator('#credit-count').textContent());
    await page.locator('#topup-button').click();
    await page.locator('#topup-form input[name=amount]').fill('5');
    await page.locator('#topup-form button.primary').click();
    await expect(page.locator('#credit-count')).toHaveText(String(original + 5));
    await page.locator('[data-tab=providers]').click();
    await expect(page.locator('#providers')).toContainText('Sample Football');
    await expect(page.locator('#admin-overview')).toContainText('Pending predictions');
    await expect(page.locator('body')).toHaveClass(/operations-mode/);
    await page.screenshot({path:'/tmp/bolum-operations-desktop.png',fullPage:true});
    await page.locator('#sync-fixtures').click();
    await expect(page.locator('#notice')).toContainText('Configure the football-data.org token');
});

test('company switching clears private history and browser registration creates a workspace', async ({ page }) => {
    execFileSync('php', ['-r', `$pdo = new PDO('sqlite:'.getenv('BOLUM_BROWSER_DATABASE')); $pdo->exec("INSERT INTO company_user (company_id,user_id,role,created_at,updated_at) SELECT companies.id,users.id,'member',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM companies CROSS JOIN users WHERE companies.name='Rival Analytics' AND users.email='admin@bolum.test'");`], { env: process.env });
    await signIn(page);
    const rival = await page.locator('#company option').filter({hasText:'Rival Analytics'}).getAttribute('value');
    await page.locator('[data-tab=predictions]').click();
    await page.locator('#company').selectOption(rival);
    await expect(page.locator('#predictions')).toContainText('No predictions yet');
    await expect(page.locator('#credit-count')).toHaveText('0');
    await expect(page.locator('#topup-button')).not.toBeVisible();
    await page.locator('#logout').click();
    await expect(page.locator('#account')).toContainText('Sign in');
    await expect(page.locator('#account')).toBeEnabled();
    await page.locator('#account').click();
    await page.locator('#show-register').click();
    const form = page.locator('#register-form');
    await form.locator('[name=name]').fill('New Analyst');
    await form.locator('[name=company_name]').fill('Fresh Analytics');
    await form.locator('[name=email]').fill('fresh@example.test');
    await form.locator('[name=password]').fill('long-password');
    await form.locator('[name=password_confirmation]').fill('long-password');
    await form.locator('button[type=submit]').click();
    await expect(page.locator('#register-dialog')).not.toBeVisible();
    await expect(page.locator('#company')).toContainText('Fresh Analytics');
    await expect(page.locator('#credit-count')).toHaveText('10');
    await expect(page.locator('#predictions')).toContainText('No predictions yet');
});

test('league standings show loading, recover from errors, and fit mobile screens', async ({ page }) => {
    await page.route('**/api/v1/leagues?*', route => route.fulfill({json:{data:[{id:2,name:'Premier League',source:'football-data'}]}}));
    let release;
    const gate = new Promise(resolve => release = resolve);
    let attempts = 0;
    await page.route('**/api/v1/leagues/2/standings', async route => {
        if (++attempts === 1) { await gate; return route.fulfill({status:503,json:{message:'League standings are temporarily unavailable.'}}); }
        return route.fulfill({json:{data:{league_name:'Premier League',season:2026,source:'football-data',fetched_at:new Date().toISOString(),rows:[
            {position:1,team:{name:'Arsenal FC',crest_url:null},played:3,won:2,drawn:1,lost:0,goals_for:6,goals_against:2,goal_difference:4,points:7},
            {position:2,team:{name:'Liverpool FC',crest_url:null},played:3,won:2,drawn:0,lost:1,goals_for:5,goals_against:3,goal_difference:2,points:6},
        ]}}});
    });
    await page.goto('/');
    await expect(page.locator('#standings-league')).toHaveValue('2');
    await page.locator('[data-tab=standings]').click();
    await expect(page.locator('#standings')).toHaveAttribute('aria-busy','true');
    await expect(page.locator('#standings')).toContainText('Fetching the league table');
    release();
    await expect(page.locator('#standings')).toContainText('temporarily unavailable');
    await page.locator('#standings [data-retry]').click();
    await expect(page.locator('.standings-table tbody tr')).toHaveCount(2);
    await expect(page.locator('.standings-table tbody tr').first()).toContainText('Arsenal FC');
    await expect(page.locator('.table-points').first()).toHaveText('7');
    await expect(page.locator('#notice')).not.toBeVisible();
    await expect(page.locator('#standings')).toContainText('2026/27');
    await page.screenshot({path:'/tmp/bolum-standings-desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await expect(page.locator('.standings-wrap')).toHaveAttribute('tabindex','0');
    await page.screenshot({path:'/tmp/bolum-standings-mobile.png',fullPage:true});
    await page.getByRole('button',{name:'Switch to light theme'}).click();
    await expect(page.locator('.standings-table')).toBeVisible();
});

test('match form and forecast provenance explain real results without claiming shot xG', async ({ page }) => {
    const fixture = {id:100,league:{id:2,name:'Premier League'},home_team:{name:'Arsenal FC'},away_team:{name:'Liverpool FC'},kickoff_at:new Date(Date.now()+86400000).toISOString(),status:'scheduled',is_finished:false,source:'football-data',score:{home:null,away:null}};
    const fixtureResponse = {data:fixture,form:{cutoff_at:new Date().toISOString(),home:[{fixture_id:90,opponent:'Chelsea FC',venue:'home',goals_for:2,goals_against:0,outcome:'W',kickoff_at:new Date(Date.now()-86400000).toISOString()}],away:[]}};
    const result = {model:'independent-poisson',data_quality:'external',expected_goals:{home:1.6,away:1.1},most_likely_score:{home:1,away:1},probabilities:{home_win:.5,draw:.25,away_win:.25},sources:[{name:'Match Results Model',driver:'results',weight:1,expected_goals:{home:1.6,away:1.1},probabilities:{home_win:.5,draw:.25,away_win:.25},evidence:{league_matches:30,home_matches:3,away_matches:3,home_venue_matches:2,away_venue_matches:1,limited_sample:true,model_version:'results-rates-v1',cutoff_at:new Date().toISOString()}}]};
    await page.route('**/api/v1/fixtures?*', route => route.fulfill({json:{data:[fixture],meta:{total:1,current_page:1,last_page:1},links:{prev:null,next:null}}}));
    await page.route('**/api/v1/fixtures/100', route => route.fulfill({json:fixtureResponse}));
    await page.route('**/api/v1/companies/*/fixtures/100/predictions?*', route => route.fulfill({json:{data:[{id:100,status:'completed',created_at:new Date().toISOString(),result}]}}));
    await signIn(page);
    await page.locator('[data-fixture]').first().click();
    await expect(page.locator('#detail-body')).toContainText('Real match data inputs');
    await expect(page.locator('.model-evidence')).toContainText('30 league matches');
    await expect(page.locator('.model-evidence')).toContainText('Limited venue history');
    await expect(page.locator('.model-evidence')).toContainText('does not use shot-based xG');
    await expect(page.locator('.recent-form')).toContainText('Chelsea FC');
    await expect(page.locator('.form-outcome')).toHaveAttribute('aria-label','Win');
    await expect(page.locator('.recent-form')).toContainText('No eligible results recorded yet');
    await page.setViewportSize({width:390,height:844});
    expect(await page.locator('#detail-body').evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
    await page.screenshot({path:'/tmp/bolum-results-form-mobile.png',fullPage:true});
});


test('registration validates fields and displays server validation beside inputs', async ({page})=>{
    await page.goto('/');
    await page.locator('#account').click();
    await page.locator('#show-register').click();
    const form=page.locator('#register-form');
    await form.locator('[type=submit]').click();
    await expect(form.locator('[name=name]')).toHaveAttribute('aria-invalid','true');
    await expect(form.locator('[name=name]')).toBeFocused();
    await form.locator('[name=name]').fill('Test User');
    await form.locator('[name=company_name]').fill('Test Workspace');
    await form.locator('[name=email]').fill('admin@bolum.test');
    await form.locator('[name=password]').fill('new-password');
    await form.locator('[name=password_confirmation]').fill('different-password');
    await form.locator('[type=submit]').click();
    await expect(form.locator('#register-form-password_confirmation-error')).toHaveText('Passwords do not match.');
    await form.locator('[name=password_confirmation]').fill('new-password');
    await form.locator('[type=submit]').click();
    await expect(form.locator('[name=email]')).toHaveAttribute('aria-invalid','true');
    await expect(form.locator('#register-form-email-error')).toContainText('already been taken');
});

test('profile persists name and avatar and password change requires signing in again',async({page})=>{
    await signIn(page);
    await page.locator('#account').click();
    await expect(page).toHaveURL(/\/profile$/);
    const form=page.locator('#profile-form');
    await form.locator('[name=name]').fill('Bolum Captain');
    await page.locator('[data-avatar=captain]').click();
    await form.getByRole('button',{name:'Save changes'}).click();
    await expect(page.locator('.profile-identity')).toContainText('Bolum Captain');
    await page.reload();
    await expect(page.locator('[data-avatar=captain]')).toHaveAttribute('aria-pressed','true');
    await page.screenshot({path:'/tmp/bolum-profile-desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.screenshot({path:'/tmp/bolum-profile-mobile.png',fullPage:true});
    const security=page.locator('#password-form');
    await security.locator('[name=current_password]').fill('password123');
    await security.locator('[name=password]').fill('updated-password');
    await security.locator('[name=password_confirmation]').fill('updated-password');
    await security.getByRole('button',{name:'Change password'}).click();
    await expect(page.locator('#auth-dialog')).toBeVisible();
    await page.locator('#login-form [name=email]').fill('admin@bolum.test');
    await page.locator('#login-form [name=password]').fill('updated-password');
    await page.locator('#login-form [type=submit]').click();
    await expect(page.locator('#auth-dialog')).not.toBeVisible();
    await expect(page.locator('#account')).toContainText('Bolum Captain');
    execFileSync('php',['-r',`$pdo=new PDO('sqlite:'.getenv('BOLUM_BROWSER_DATABASE')); $query=$pdo->prepare('UPDATE users SET password=? WHERE email=?'); $query->execute([password_hash('password123', PASSWORD_BCRYPT), 'admin@bolum.test']);`],{env:process.env});
});

test('prediction requests show progress and an inline recoverable failure',async({page})=>{
    await signIn(page,'member@bolum.test');
    await page.locator('[data-fixture]').first().click();
    let release;
    const gate=new Promise(resolve=>release=resolve);
    await page.route('**/api/v1/companies/*/fixtures/*/predictions',async route=>{
        if(route.request().method()!=='POST') return route.continue();
        await gate; return route.fulfill({status:409,json:{message:'Not enough recorded results for this fixture.'}});
    });
    await page.locator('#generate').click();
    await expect(page.locator('#prediction-feedback')).toContainText('Checking match data');
    await expect(page.locator('#generate')).toBeDisabled();
    release();
    await expect(page.locator('#prediction-feedback [role=alert]')).toContainText('Not enough recorded results');
    await expect(page.locator('#generate')).toBeEnabled();
});


test('sidebar expands by default and remembers a keyboard-accessible collapsed preference',async({page})=>{
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-sidebar','expanded');
    await page.getByRole('button',{name:'Collapse sidebar',exact:true}).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('html')).toHaveAttribute('data-sidebar','collapsed');
    await page.reload();
    await expect(page.getByRole('button',{name:'Expand sidebar',exact:true})).toHaveAttribute('aria-expanded','false');
    await page.getByRole('button',{name:'Expand sidebar',exact:true}).click();
    await page.setViewportSize({width:390,height:844});
    await page.getByRole('button',{name:'Collapse sidebar',exact:true}).click();
    await expect(page.locator('#main-navigation')).not.toBeVisible();
    await page.getByRole('button',{name:'Expand sidebar',exact:true}).click();
    await expect(page.locator('#main-navigation')).toBeVisible();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
});

test('search is debounced and a slow previous search cannot replace the latest results',async({page})=>{
    await page.goto('/');
    await expect(page.locator('.fixture-card')).toHaveCount(4);
    let release, oldStarted=false;
    const gate=new Promise(resolve=>release=resolve), queries=[];
    await page.route('**/api/v1/fixtures?*',async route=>{
        const q=new URL(route.request().url()).searchParams.get('q');queries.push(q);
        if(q==='North') {oldStarted=true;await gate;}
        return route.continue();
    });
    await page.locator('#search').fill('North');
    await expect.poll(()=>oldStarted).toBe(true);
    await page.locator('#search').fill('M');
    await page.locator('#search').fill('Man');
    await page.locator('#search').fill('Manchester');
    await expect(page.locator('.fixture-card')).toHaveCount(2);
    await expect(page.locator('.fixture-card').first()).toContainText('Manchester');
    release();
    await expect(page.locator('#search')).toHaveValue('Manchester');
    await expect(page.locator('.fixture-card').last()).toContainText('Manchester');
    expect(queries).toEqual(['North','Manchester']);
});

test('gameweek filters use available rounds and multiple imported leagues unlock selection',async({page})=>{
    await page.route('**/api/v1/leagues?*',route=>route.fulfill({json:{data:[{id:1,name:'Premier League',source:'football-data'},{id:2,name:'La Liga',source:'football-data'}]}}));
    await page.route('**/api/v1/fixtures/filter-options',route=>route.fulfill({json:{data:[{league_id:1,season:'2026',matchday:3,fixtures:10,finished:10},{league_id:1,season:'2026',matchday:4,fixtures:10,finished:0},{league_id:2,season:'2026',matchday:5,fixtures:10,finished:0}]}}));
    const requests=[];
    await page.route('**/api/v1/fixtures?*',route=>{requests.push(new URL(route.request().url()));return route.continue();});
    await page.goto('/');
    await expect(page.locator('#league-filter')).toBeEnabled();
    await page.locator('#matchday-filter').selectOption('4');
    await expect.poll(()=>requests.at(-1)?.searchParams.get('matchday')).toBe('4');
    expect(requests.at(-1).searchParams.get('season')).toBe('2026');
    await page.locator('#league-filter').selectOption('2');
    await expect(page.locator('#matchday-filter option')).toHaveCount(2);
    await expect(page.locator('#matchday-filter')).toHaveValue('');
    await expect.poll(()=>requests.at(-1)?.searchParams.get('league_id')).toBe('2');
});

test('admin gameweek track record distinguishes missing forecasts and the control-room theme',async({page})=>{
    await page.route('**/api/v1/fixtures/filter-options',route=>route.fulfill({json:{data:[{league_id:1,season:'2026',matchday:3,fixtures:3,finished:3}]}}));
    await page.route('**/api/v1/companies/*/track-record?*',route=>route.fulfill({json:{data:{fixtures:3,finished:3,evaluated:2,correct:1,accuracy:.5,missing_forecasts:1,method:'Latest saved pre-kickoff forecast per fixture.',rows:[
        {home_team:'Arsenal FC',away_team:'Chelsea FC',kickoff_at:'2026-09-01T12:00:00Z',score:{home:2,away:0},forecast:{pick:'home_win',created_at:'2026-08-31T12:00:00Z',probabilities:{home_win:.6,draw:.2,away_win:.2},score:{home:2,away:1}},correct:true},
        {home_team:'Liverpool FC',away_team:'Manchester City FC',kickoff_at:'2026-09-01T12:00:00Z',score:{home:1,away:3},forecast:{pick:'home_win',created_at:'2026-08-31T12:00:00Z',probabilities:{home_win:.6,draw:.2,away_win:.2}},correct:false},
        {home_team:'Fulham FC',away_team:'Everton FC',kickoff_at:'2026-09-01T12:00:00Z',score:{home:0,away:0},forecast:null,correct:null},
    ]}}}));
    await signIn(page,'member@bolum.test');
    await expect(page.locator('[data-tab=providers]')).not.toBeVisible();
    await page.locator('#logout').click();
    await signIn(page);
    await page.locator('[data-tab=providers]').click();
    await page.getByRole('button',{name:'Gameweek track record',exact:true}).click();
    await expect(page.locator('.track-table tbody tr')).toHaveCount(3);
    await expect(page.locator('.track-summary')).toContainText('50%');
    await expect(page.locator('.track-table')).toContainText('No saved pre-match forecast');
    await expect(page.locator('.track-status.correct')).toHaveCount(1);
    await expect(page.locator('.track-status.incorrect')).toHaveCount(1);
    expect(await page.locator('body').evaluate(el=>getComputedStyle(el).getPropertyValue('--accent').trim())).toBe('#f2c66d');
    await page.screenshot({path:'/tmp/bolum-track-record-desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.screenshot({path:'/tmp/bolum-track-record-mobile.png',fullPage:true});
});

test('club guide loads on demand with retry, attribution and escaped content', async ({ page }) => {
    const fixture = {id:900,league:{id:1,name:'Premier League'},home_team:{id:71,name:'Arsenal FC'},away_team:{id:72,name:'Liverpool FC'},kickoff_at:new Date(Date.now()+86400000).toISOString(),status:'scheduled',is_finished:false,source:'football-data',score:{home:null,away:null}};
    await page.route('**/api/v1/fixtures?*', route => route.fulfill({json:{data:[fixture],meta:{total:1,current_page:1,last_page:1},links:{prev:null,next:null}}}));
    await page.route('**/api/v1/fixtures/900', route => route.fulfill({json:{data:fixture}}));
    let calls=0, release;
    const gate=new Promise(resolve=>release=resolve);
    await page.route('**/api/v1/teams/71/profile', async route => {
        calls++;
        if(calls===1){await gate;return route.fulfill({status:503,json:{message:'Club information is temporarily unavailable.'}});}
        return route.fulfill({json:{data:{name:'Arsenal',stadium:'Emirates Stadium',location:'London',formed_year:1886,description:'<img src=x onerror=alert(1)>',source:'TheSportsDB',source_url:'https://www.thesportsdb.com/team/133604',fetched_at:new Date().toISOString()}}});
    });
    await page.goto('/');
    await page.locator('[data-fixture="900"]').click();
    await expect(page.getByRole('heading',{name:'Club guide'})).toBeVisible();
    expect(calls).toBe(0);
    await page.getByRole('button',{name:'Arsenal FC info'}).click();
    await expect(page.locator('#club-profile')).toContainText('Loading club information');
    release();
    await expect(page.locator('#club-profile')).toContainText('temporarily unavailable');
    await page.getByRole('button',{name:'Retry club information'}).click();
    await expect(page.locator('#club-profile')).toContainText('Emirates Stadium');
    await expect(page.locator('#club-profile')).toContainText('<img src=x onerror=alert(1)>');
    await expect(page.locator('#club-profile img')).toHaveCount(0);
    await expect(page.locator('#club-profile a')).toHaveAttribute('href','https://www.thesportsdb.com/team/133604');
    await expect(page.locator('#club-profile')).toContainText('Not used in prediction calculations');
});
