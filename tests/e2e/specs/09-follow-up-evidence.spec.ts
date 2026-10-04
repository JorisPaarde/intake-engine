/**
 * Playwright UI checks for BL-146 follow-up evidence linking.
 *
 * Bootstraps via `php tests/e2e/bootstrap-follow-up-evidence.php` which prints
 * JSON with login email/password and workspace URL. Runs in the main E2E job
 * (`tests/e2e/playwright.config.ts` → specs/).
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  workspaceUrl: string;
  intakeId: number;
};

function bootstrap(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-follow-up-evidence.php');
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

test.describe('Follow-up evidence dossier linking (BL-146)', () => {
  test('workspace shows Nieuwe aanvulling ontvangen with review action and room proposal', async ({ page }) => {
    const data = bootstrap();

    await page.goto(data.baseUrl+'/login');
    await page.locator('#email').fill(data.email);
    await page.locator('#password').fill(data.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/dashboard|intakes|opname/);

    await page.goto(data.workspaceUrl);
    await expect(page.getByTestId('new-contribution-banner')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('new-contribution-banner')).toContainText('Nieuwe aanvulling ontvangen');
    await expect(page.getByTestId('contribution-review-action').first()).toBeVisible();
    await expect(page.getByText(/hoogste punt 2,6/i).first()).toBeVisible();
    await expect(page.getByText(/hoogste punt niet blind|Beoordeel de door de klant/i).first()).toBeVisible();
  });
});
