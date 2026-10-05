/**
 * Playwright UI checks: readable evidence labels + superseded gallery marking.
 *
 * Bootstraps via `php tests/e2e/bootstrap-evidence-labels.php`.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  showUrl: string;
  workspaceUrl: string;
  intakeId: number;
  oldUploadId: number;
  newUploadId: number;
  rawReference: string;
};

function bootstrap(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-evidence-labels.php');
  const raw = execFileSync('php', [script], {
    encoding: 'utf8',
    env: {
      ...process.env,
      E2E_BASE_URL: process.env.E2E_BASE_URL ?? process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
    },
  });
  const line = raw.trim().split('\n').filter((l) => l.startsWith('{')).at(-1);
  if (!line) {
    throw new Error('Bootstrap produced no JSON: ' + raw);
  }
  return JSON.parse(line) as Bootstrap;
}

test.describe('Installer evidence labels and superseded gallery', () => {
  test('show page hides raw keys, shows Dutch citation, marks replaced fusebox photo', async ({ page }) => {
    const data = bootstrap();

    await page.goto(data.baseUrl + '/login');
    await page.locator('#email').fill(data.email);
    await page.locator('#password').fill(data.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/dashboard|intakes|opname/);

    await page.goto(data.showUrl);
    await expect(page.getByRole('heading', { name: /Aandachtspunten/i })).toBeVisible({ timeout: 15_000 });

    await expect(page.getByTestId('evidence-citations')).toBeVisible();
    await expect(page.getByTestId('evidence-citation').first()).toBeVisible();
    await expect(page.getByTestId('evidence-citation').first()).toContainText(/meterkast/i);
    await expect(page.locator('body')).not.toContainText(data.rawReference);
    await expect(page.locator('body')).not.toContainText('fusebox_photo_assessment@fact:');
    await expect(page.locator('body')).not.toContainText('outdoor_location_photos');
    await expect(page.locator('body')).not.toContainText('Automatische beoordeling van outdoor_location_photos');

    // No snake_case question/section keys in visible installer copy (whitelist known-safe tokens).
    await assertNoSnakeCaseKeys(page);

    // Gallery lives in a collapsed <details> on the show page.
    await page.getByText('Foto’s en bestanden', { exact: false }).first().click();
    const oldGallery = page.getByTestId(`gallery-upload-${data.oldUploadId}`);
    await expect(oldGallery).toBeVisible({ timeout: 10_000 });
    await expect(oldGallery).toHaveAttribute('data-superseded', '1');
    await expect(oldGallery.getByTestId('gallery-superseded')).toContainText(/Vervangen door ronde 1/i);

    const newGallery = page.getByTestId(`gallery-upload-${data.newUploadId}`);
    await expect(newGallery).toBeVisible();
    await expect(newGallery).not.toHaveAttribute('data-superseded', '1');

    await page.goto(data.workspaceUrl);
    await page.getByRole('heading', { name: /Foto/i }).first().click();
    const workspaceOld = page.getByTestId(`gallery-upload-${data.oldUploadId}`);
    await expect(workspaceOld).toBeVisible({ timeout: 15_000 });
    await expect(workspaceOld).toHaveAttribute('data-superseded', '1');
    await expect(workspaceOld.getByTestId('gallery-superseded')).toContainText(/Vervangen door ronde 1/i);
    await expect(page.locator('body')).not.toContainText('outdoor_location_photos');
    await assertNoSnakeCaseKeys(page);
  });
});

/** Tokens that may legitimately appear with underscores in installer-visible text. */
const SNAKE_CASE_WHITELIST = new Set([
  // Opaque citation hashes use type_prefix + hex (never shown as primary labels, but be safe).
]);

async function assertNoSnakeCaseKeys(page: import('@playwright/test').Page): Promise<void> {
  // Prefer the outermost page main; nested <main> exists inside the content column.
  const text = await page.locator('body').innerText();
  const matches = text.match(/[a-z]+_[a-z0-9_]+/g) ?? [];
  const offenders = [...new Set(matches)].filter((token) => !SNAKE_CASE_WHITELIST.has(token));
  expect(offenders, `Unexpected snake_case in installer UI: ${offenders.join(', ')}`).toEqual([]);
}