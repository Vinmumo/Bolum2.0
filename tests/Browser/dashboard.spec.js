import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test('imported league opens on upcoming fixtures and all matches remains available', async ({ page }) => {
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
    await page.locator('#league-filter').selectOption('');
    await page.locator('#status-filter').selectOption('');
    await page.getByRole('button', {name:'Apply filters'}).click();
    await expect(page.locator('#fixture-context')).toContainText('imported and local');
    expect(requests.at(-1).searchParams.has('league_id')).toBe(false);
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
    await page.locator('#account').click();
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
    await page.locator('#account').click();
    await signIn(page);
    await expect(page.locator('#credit-count')).toHaveText(/^\d+$/);
    const original = Number(await page.locator('#credit-count').textContent());
    await page.locator('#topup-button').click();
    await page.locator('#topup-form input[name=amount]').fill('5');
    await page.locator('#topup-form button.primary').click();
    await expect(page.locator('#credit-count')).toHaveText(String(original + 5));
    await page.locator('[data-tab=providers]').click();
    await expect(page.locator('#providers')).toContainText('Sample Football');
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
    await page.locator('#account').click();
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
