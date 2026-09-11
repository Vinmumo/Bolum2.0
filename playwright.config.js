import { defineConfig } from '@playwright/test';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// The destructive test setup is confined to a newly created temporary SQLite file.
process.env.BOLUM_BROWSER_DATABASE ||= join(mkdtempSync(join(tmpdir(), 'bolum-browser-')), 'database.sqlite');
writeFileSync(process.env.BOLUM_BROWSER_DATABASE, '', { flag: 'a' });
const port = process.env.BOLUM_BROWSER_PORT || '8012';
export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    timeout: 30000,
    reporter: 'list',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        launchOptions: process.env.BOLUM_CHROME_PATH ? { executablePath: process.env.BOLUM_CHROME_PATH, args: ['--no-sandbox'] } : {},
    },
    webServer: {
        command: `php artisan migrate:fresh --force --seed && php artisan serve --host=127.0.0.1 --port=${port}`,
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: false,
        timeout: 60000,
        env: {
            APP_ENV: 'local',
            APP_KEY: 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: process.env.BOLUM_BROWSER_DATABASE,
            QUEUE_CONNECTION: 'database',
            CACHE_STORE: 'database',
            SESSION_DRIVER: 'database',
            SESSION_COOKIE: 'bolum_browser_tests',
            SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${port}`,
            FOOTBALL_DATA_TOKEN: '',
            FOOTBALL_GATEWAY_TOKEN: '',
            FOOTBALL_SYNC_ENABLED: 'false',
        },
    },
});
