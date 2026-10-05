/**
 * Fix bundle D: separate example dossier + bulk approval blockers.
 *
 * Bootstraps via `php tests/e2e/bootstrap-dossier-approval.php` (latest-template-only).
 * Bulk approval asserts against a FakeAi *synthesis* intake (Candidate options —
 * same state as a real dossier), not only the auto-selected demo example.
 * Runs with `npm run test:e2e` (tests/e2e/playwright.config.ts → specs/).
 */

import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  sourceIntakeId: number;
  exampleIntakeId: number;
  synthesisIntakeId: number;
  sourceWorkspaceUrl: string;
  exampleWorkspaceUrl: string;
  synthesisWorkspaceUrl: string;
  synthesisShowUrl: string;
  approvalAllowed: boolean;
  approvalBlockers: string[];
  optionStatus: string;
  optionSource: string;
};

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-dossier-approval.php');
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
      // SQLite lock under parallel e2e workers — brief backoff then retry.
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

test.describe('Fix bundle D — approval + example dossier', () => {
  // One shared bootstrap; serial to avoid sqlite lock with parallel wizard workers.
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('example dossier is a separate labelled intake with sample content', async ({ page }) => {
    expect(data.exampleIntakeId).not.toBe(data.sourceIntakeId);

    await login(page, data);
    await page.goto(data.exampleWorkspaceUrl);

    await expect(page.getByRole('heading', { name: 'Voorbeelddossier (demo)' })).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByText(/apart gelabeld voorbeelddossier/i)).toBeVisible();

    // Source intake stays separate (no sample mix).
    await page.goto(data.sourceWorkspaceUrl);
    await expect(page.getByTestId('load-example-dossier')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('heading', { name: 'Voorbeelddossier (demo)' })).toHaveCount(0);
  });

  test('bulk approval shows blockers after real synthesis without auto-select', async ({ page }) => {
    expect(data.optionStatus).toBe('candidate');
    expect(data.optionSource).toBe('ai');
    expect(data.approvalAllowed).toBe(false);
    expect(data.approvalBlockers.length).toBeGreaterThan(0);
    expect(data.approvalBlockers.some((b) => /onzekerheid/i.test(b))).toBe(true);

    await login(page, data);

    // Dossier overview (show) surfaces the same blockers — not only per-item Accepteren/Verwijderen.
    await page.goto(data.synthesisShowUrl);
    await expect(page.getByTestId('approval-blocked-panel')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('approval-blockers')).toBeVisible();
    await expect(page.getByTestId('approval-blockers')).toContainText(/onzekerheid/i);

    // Workspace Voorstel afronden: blocked CTA + blockers for Candidate AI proposals.
    await page.goto(data.synthesisWorkspaceUrl);
    await page.locator('#workspace-complete').scrollIntoViewIfNeeded();
    await expect(page.getByTestId('approval-not-ready')).toBeVisible();
    await expect(page.getByText('Nog niet klaar om goed te keuren')).toBeVisible();
    await expect(page.getByTestId('approval-blockers')).toBeVisible();
    await expect(page.getByTestId('approve-proposal')).toHaveCount(0);
  });
});
