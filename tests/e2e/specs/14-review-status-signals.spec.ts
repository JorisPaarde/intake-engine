/**
 * N27: detail-page status signals after PrepareQuote review.
 * Bootstraps via `php tests/e2e/bootstrap-review-status-signals.php`.
 */
import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type ReviewBootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  intakeId: number;
  showUrl: string;
  workspaceUrl: string;
  decision: string;
  workflowMode: string;
  status: string;
};

function sleepMs(ms: number): void {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function bootstrapReviewSignals(): ReviewBootstrap {
  const script = path.resolve('tests/e2e/bootstrap-review-status-signals.php');
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
      return JSON.parse(line) as ReviewBootstrap;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
      sleepMs(500 * attempt);
    }
  }
  throw new Error('N27 bootstrap produced no JSON after retries: '+lastError);
}

test.describe('N27 detail status signals after PrepareQuote review', () => {
  test.describe.configure({ mode: 'serial' });

  let data: ReviewBootstrap;

  test.beforeAll(() => {
    data = bootstrapReviewSignals();
  });

  test('Wat nu te doen stays stable across reloads and labels werkwijze', async ({ page }) => {
    expect(data.decision).toBe('prepare_quote');
    expect(data.status).toBe('reviewed');
    expect(data.workflowMode).toBe('hybrid');

    await page.goto(data.baseUrl+'/login');
    await page.locator('#email').fill(data.email);
    await page.locator('#password').fill(data.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/dashboard|intakes|opname/);

    await page.goto(data.showUrl);

    const summary = page.getByTestId('detail-next-action-summary');
    await expect(summary).toBeVisible({ timeout: 15_000 });
    await expect(summary).toContainText('Je koos ‘Offerte voorbereiden’');
    await expect(summary).toContainText('onderdelen open');
    await expect(page.getByTestId('workflow-mode-label')).toContainText('Werkwijze:');
    await expect(page.getByTestId('workflow-mode-label')).toContainText('Samen met de klant');
    await expect(page.getByTestId('intake-status-badge')).toContainText('Beoordeeld');

    const firstSummary = (await summary.innerText()).trim();

    await page.reload();
    await expect(page.getByTestId('detail-next-action-summary')).toHaveText(firstSummary);
    await expect(page.getByTestId('workflow-mode-label')).toContainText('Werkwijze:');

    await page.reload();
    await expect(page.getByTestId('detail-next-action-summary')).toHaveText(firstSummary);
    await expect(page.getByTestId('quote-readiness-count')).toContainText('Klaar voor offerte:');
    await expect(page.getByTestId('quote-readiness-count')).toContainText('onderdelen');
  });
});

test('N52 review validation opens the form and focuses a visible error summary', async ({ page }) => {
  const data = bootstrapReviewSignals();
  await page.goto(data.baseUrl+'/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);
  await page.goto(data.showUrl);
  await page.getByTestId('review-details').locator('summary').click();
  await page.locator('#decision').selectOption('need_more_info');
  // Empty follow-up rows are filtered by the server, which rejects the review.
  await page.getByTestId('review-form').evaluate((form: HTMLFormElement) => form.submit());
  const errors = page.getByTestId('validation-error-summary');
  await expect(errors).toBeVisible();
  await expect(errors).toBeFocused();
  await expect(errors).toContainText(/aanvullende vragen/i);
  await expect(page.getByTestId('review-details')).toHaveAttribute('open', '');
  await expect(page.locator('#decision')).toHaveValue('need_more_info');
});
