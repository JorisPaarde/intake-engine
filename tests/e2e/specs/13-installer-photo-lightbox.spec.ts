/**
 * N23: installer gallery thumbnails use object-contain; click opens accessible lightbox.
 *
 * Bootstraps via `php tests/e2e/bootstrap-photo-lightbox.php`.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  showUrl: string;
  intakeId: number;
  portraitUploadId: number;
  landscapeUploadId: number;
};

function bootstrap(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-photo-lightbox.php');
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

async function loginAndOpenGallery(page: Page, data: Bootstrap): Promise<void> {
  await page.goto(data.baseUrl + '/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);

  await page.goto(data.showUrl);
  await page.getByText('Foto’s en bestanden', { exact: false }).first().click();
  await expect(page.getByTestId(`gallery-upload-${data.portraitUploadId}`)).toBeVisible({ timeout: 10_000 });
}

async function assertNoHorizontalOverflow(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => {
    const doc = document.documentElement;
    return doc.scrollWidth > doc.clientWidth + 1;
  });
  expect(overflow, 'page should not overflow horizontally').toBe(false);
}

for (const viewport of [
  { name: 'desktop', width: 1366, height: 900 },
  { name: 'mobile', width: 375, height: 812 },
] as const) {
  test.describe(`Installer photo lightbox (${viewport.name})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test('contain thumbnail, open/close lightbox, keyboard Enter/Esc, focus return', async ({ page }) => {
      const data = bootstrap();
      await loginAndOpenGallery(page, data);

      const figure = page.getByTestId(`gallery-upload-${data.portraitUploadId}`);
      const trigger = figure.getByRole('button', { name: /Foto vergroten/i });
      await expect(trigger).toBeVisible();

      const thumb = figure.locator('img').first();
      await expect(thumb).toHaveCSS('object-fit', 'contain');

      await trigger.click();
      const dialog = page.locator('#photo-lightbox');
      await expect(dialog).toBeVisible();
      await expect(dialog.getByRole('heading')).toBeVisible();
      await expect(dialog.getByText('Open origineel')).toBeVisible();
      await expect(dialog.locator('[data-photo-lightbox-image]')).toBeVisible();

      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(trigger).toBeFocused();

      await trigger.focus();
      await page.keyboard.press('Enter');
      await expect(dialog).toBeVisible();
      await dialog.getByRole('button', { name: /^Sluiten$/ }).click();
      await expect(dialog).toBeHidden();
      await expect(trigger).toBeFocused();

      await assertNoHorizontalOverflow(page);
    });
  });
}
