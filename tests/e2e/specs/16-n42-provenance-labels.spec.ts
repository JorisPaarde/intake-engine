/**
 * Brief D: N42 rapport-herkomstlabels + N43 aanvullingsfoto-captions.
 * Bootstraps via `php tests/e2e/bootstrap-n42-provenance-labels.php`.
 */
import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  intakeId: number;
  showUrl: string;
  reportUrl: string;
  followUpUploadId: number;
  expectedBagLabel: string;
  expectedYearLabel: string;
  expectedRequestLabel: string;
  expectedCustomerDrain: string;
  expectedGalleryCaption: string;
  reportOmitsAroundHouse: boolean;
  reportHasBag: boolean;
};

function sleepMs(ms: number): void {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-n42-provenance-labels.php');
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
  throw new Error('N42 bootstrap produced no JSON after retries: ' + lastError);
}

async function login(page: import('@playwright/test').Page, data: Bootstrap): Promise<void> {
  await page.goto(data.baseUrl + '/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);
}

test.describe('Brief D — N42 provenance labels + N43 gallery captions', () => {
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('N42: report shows BAG / bevestigde aanvraag / klantantwoord; no empty around_house', async ({ page }) => {
    expect(data.reportHasBag).toBeTruthy();
    expect(data.reportOmitsAroundHouse).toBeTruthy();

    await login(page, data);
    await page.goto(data.reportUrl);
    await expect(page.getByText('Pagina niet gevonden')).toHaveCount(0);

    await expect(page.getByText(data.expectedBagLabel, { exact: false })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(data.expectedYearLabel, { exact: false })).toBeVisible();
    await expect(page.getByText(data.expectedRequestLabel, { exact: false })).toBeVisible();
    await expect(page.getByText(data.expectedCustomerDrain, { exact: false })).toBeVisible();
    await expect(page.getByText('klantantwoord, niet aan de klant gevraagd')).toHaveCount(0);
    await expect(page.getByText('We zien de buitenkant nog niet goed')).toHaveCount(0);
  });

  test('N43: gallery caption includes area and prompt', async ({ page }) => {
    await login(page, data);
    await page.goto(data.showUrl);

    await page.getByText('Foto’s en bestanden', { exact: false }).first().click();
    const gallery = page.getByTestId(`gallery-upload-${data.followUpUploadId}`);
    await expect(gallery).toBeVisible({ timeout: 15_000 });
    await expect(gallery.getByText(data.expectedGalleryCaption, { exact: false })).toBeVisible();

    await gallery.getByRole('button', { name: /Foto vergroten/i }).click();
    const lightbox = page.locator('#photo-lightbox');
    await expect(lightbox).toBeVisible();
    await expect(lightbox.locator('[data-photo-lightbox-caption]')).toContainText(/Stroomtoevoer|Plek stroomkabel/);
  });
});
