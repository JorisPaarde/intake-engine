/**
 * Fix bundle D: separate example dossier + bulk approval blockers.
 *
 * Bootstraps via `php tests/e2e/bootstrap-dossier-approval.php` (latest-template-only).
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
  sourceWorkspaceUrl: string;
  exampleWorkspaceUrl: string;
  approvalAllowed: boolean;
  approvalBlockers: string[];
};

function bootstrap(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-dossier-approval.php');
  const raw = execFileSync('php', [script], {
    encoding: 'utf8',
    env: {
      ...process.env,
      E2E_BASE_URL: process.env.E2E_BASE_URL ?? process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
    },
  });
  const line = raw.trim().split('\n').filter((l) => l.startsWith('{')).at(-1);
  if (!line) {
    throw new Error('Bootstrap produced no JSON: '+raw);
  }
  return JSON.parse(line) as Bootstrap;
}

async function login(page: import('@playwright/test').Page, data: Bootstrap): Promise<void> {
  await page.goto(data.baseUrl+'/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);
}

test.describe('Fix bundle D — approval + example dossier', () => {
  test('example dossier is a separate labelled intake with sample content', async ({ page }) => {
    const data = bootstrap();
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

  test('bulk approval shows blockers when uncertainty remains', async ({ page }) => {
    const data = bootstrap();
    expect(data.approvalAllowed).toBe(false);
    expect(data.approvalBlockers.length).toBeGreaterThan(0);

    await login(page, data);
    await page.goto(data.exampleWorkspaceUrl);

    await page.locator('#workspace-complete').scrollIntoViewIfNeeded();
    await expect(page.getByText('Nog niet klaar om goed te keuren')).toBeVisible();
    await expect(page.getByTestId('approval-blockers')).toBeVisible();
    await expect(page.getByTestId('approve-proposal')).toHaveCount(0);
  });
});
