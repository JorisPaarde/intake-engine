/**
 * Brief D: N33 (AI exception → concept) + N35 (route status on report).
 * Bootstraps via `php tests/e2e/bootstrap-n35-report-labels.php`.
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
  reportUrl: string;
  exceptionLabel: string;
  reportContainsSiteVisit: boolean;
  reportContainsApproved: boolean;
};

function sleepMs(ms: number): void {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-n35-report-labels.php');
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
  throw new Error('N35 bootstrap produced no JSON after retries: '+lastError);
}

async function login(page: import('@playwright/test').Page, data: Bootstrap): Promise<void> {
  await page.goto(data.baseUrl+'/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);
}

test.describe('Brief D — N33 exception ask + N35 route report', () => {
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('N33: Vraag de klant on AI exception adds editable concept, does not activate', async ({ page }) => {
    await login(page, data);
    await page.goto(data.workspaceUrl);

    const exception = page.getByTestId('synthesis-exception').filter({ hasText: data.exceptionLabel });
    await expect(exception).toBeVisible({ timeout: 15_000 });

    const ask = exception.getByTestId('synthesis-exception-ask');
    await expect(ask).toBeVisible();
    await expect(ask.locator('form')).toHaveAttribute('action', /customer-tasks\/prepare/);

    await ask.getByRole('button', { name: 'Vraag de klant' }).click();
    await page.waitForURL(/opname/);

    await expect(page.getByText('Conceptlijst controleren en versturen')).toBeVisible({ timeout: 15_000 });
    const draftPrompt = page.getByTestId('customer-task-draft-prompt');
    await expect(draftPrompt).toBeVisible();
    const value = await draftPrompt.inputValue();
    expect(value).not.toBe(data.exceptionLabel);
    expect(value.toLowerCase()).not.toContain('afschot');
    expect(value.toLowerCase()).toContain('condens');
    // Exception label remains as installer context in the AI panel; task body is separate.
    await expect(page.getByTestId('synthesis-exception')).toContainText(data.exceptionLabel);
    // No active customer round yet.
    await expect(page.getByText(/Ronde 1|Opdracht 1 van/i)).toHaveCount(0);
  });

  test('N35: report shows route status Alleen te zien op locatie', async ({ page }) => {
    expect(data.reportContainsSiteVisit).toBeTruthy();
    expect(data.reportContainsApproved).toBeTruthy();

    await login(page, data);
    await page.goto(data.reportUrl);

    await expect(page.getByText('Verbindingen en routes')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText('Routebesluit per verbinding')).toBeVisible();
    await expect(page.getByText('Alleen te zien op locatie')).toBeVisible();
    await expect(page.getByText('Goedgekeurd')).toBeVisible();
    await expect(page.getByText('Condensroute zolder')).toBeVisible();
  });
});
