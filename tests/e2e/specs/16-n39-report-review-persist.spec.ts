/**
 * Brief A / N39: after a follow-up round, /rapport keeps the Beoordeling block
 * (decision + summary + stale-before-round marker) while next-step asks reassess.
 * Bootstraps via `php tests/e2e/bootstrap-n39-report-review-persist.php`.
 */
import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  intakeId: number;
  reportUrl: string;
  showUrl: string;
  reviewedAtNull: boolean;
  decisionLabel: string;
  summary: string;
  staleNotice: string;
  reportContainsBeoordeling: boolean;
  reportContainsDecision: boolean;
  reportContainsSummary: boolean;
  reportContainsStaleNotice: boolean;
  reportContainsNextStep: boolean;
};

function sleepMs(ms: number): void {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-n39-report-review-persist.php');
  let lastError = '';
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const raw = execFileSync('php', [script], {
        encoding: 'utf8',
        env: {
          ...process.env,
          E2E_BASE_URL: process.env.E2E_BASE_URL ?? process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
        },
      });
      const line = raw.trim().split('\n').filter((l) => l.startsWith('{')).at(-1);
      if (!line) {
        lastError = raw || 'bootstrap produced no JSON';
        sleepMs(500 * attempt);
        continue;
      }
      return JSON.parse(line) as Bootstrap;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
      sleepMs(500 * attempt);
    }
  }
  throw new Error('N39 bootstrap produced no JSON after retries: '+lastError);
}

async function login(page: import('@playwright/test').Page, data: Bootstrap): Promise<void> {
  await page.goto(data.baseUrl+'/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);
}

test.describe('Brief A — N39 report review persists after follow-up', () => {
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('bootstrap cleared reviewed_at and stored review in report HTML', () => {
    expect(data.reviewedAtNull).toBeTruthy();
    expect(data.reportContainsBeoordeling).toBeTruthy();
    expect(data.reportContainsDecision).toBeTruthy();
    expect(data.reportContainsSummary).toBeTruthy();
    expect(data.reportContainsStaleNotice).toBeTruthy();
    expect(data.reportContainsNextStep).toBeTruthy();
  });

  test('N39: /rapport shows Beoordeling block with stale-before-round marker', async ({ page }) => {
    await login(page, data);
    await page.goto(data.reportUrl);
    await expect(page.getByText('Pagina niet gevonden')).toHaveCount(0);

    await expect(page.getByRole('heading', { name: 'Beoordeling' })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(data.decisionLabel)).toBeVisible();
    await expect(page.getByText(data.summary)).toBeVisible();
    await expect(page.getByTestId('review-stale-before-round')).toHaveText(data.staleNotice);
    await expect(page.getByText('Beoordeel de aangeleverde aanvulling')).toBeVisible();
  });
});
