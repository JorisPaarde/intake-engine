/**
 * N29: installer route approval + note + feasible survive AI rebuild
 * on a Candidate option (before "Deze keuze").
 *
 * Bootstraps via `php tests/e2e/bootstrap-n29-route-decisions.php`.
 */

import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  intakeId: number;
  workspaceUrl: string;
  noteText: string;
  connectionStatus: string;
  optionFeasibility: string;
};

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-n29-route-decisions.php');
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
        lastError = raw;
        continue;
      }
      return JSON.parse(line) as Bootstrap;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 500 * attempt);
    }
  }
  throw new Error('Bootstrap produced no JSON after retries: '+lastError);
}

async function login(page: import('@playwright/test').Page, data: Bootstrap): Promise<void> {
  await page.goto(data.baseUrl+'/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);
}

test.describe('N29 — route decisions survive AI rebuild', () => {
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('approved route and note remain visible after rebuild without Deze keuze', async ({ page }) => {
    expect(data.connectionStatus).toBe('approved');
    expect(data.optionFeasibility).toBe('feasible');

    await login(page, data);
    await page.goto(data.workspaceUrl);

    await expect(page.getByTestId('connection-route-decision').first()).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByText('Goedgekeurd').first()).toBeVisible();
    await expect(page.getByText(data.noteText)).toBeVisible();
    await expect(page.getByTestId('connection-proposal-updated-notice')).toBeVisible();
    await expect(page.getByTestId('connection-proposal-updated-notice')).toContainText(
      'Je besluit blijft staan. Het voorstel is daarna bijgewerkt.',
    );
    await expect(page.getByText('Haalbaar').first()).toBeVisible();
  });
});
