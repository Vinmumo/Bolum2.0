import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

async function signIn(page, email = 'admin@bolum.test') {
    await page.goto('/');
    await page.getByRole('button', { name: 'Sign in', exact: false }).first().click();
    await page.locator('#login-form input[name=email]').fill(email);
    await page.locator('#login-form input[name=password]').fill('password123');
    await page.locator('#login-form button[type=submit]').click();
    await expect(page.locator('#auth-dialog')).not.toBeVisible();
    await expect(page.locator('#company')).toBeVisible();
}

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
