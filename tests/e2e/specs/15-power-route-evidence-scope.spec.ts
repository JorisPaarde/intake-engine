/**
 * N28: power_route follow-up must not mark the wizard meterkastfoto as replaced.
 *
 * Bootstraps via `php tests/e2e/bootstrap-power-route-evidence-scope.php`
 * with the same retry pattern as spec 10.
 */
import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  showUrl: string;
  workspaceUrl: string;
  intakeId: number;
  fuseboxUploadId: number;
  routeUploadId: number;
  powerConnectionId: number;
};

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-power-route-evidence-scope.php');
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
  throw new Error('Bootstrap produced no JSON after retries: ' + lastError);
}

async function login(page: import('@playwright/test').Page, data: Bootstrap): Promise<void> {
  await page.goto(data.baseUrl + '/login');
  await page.locator('#email').fill(data.email);
  await page.locator('#password').fill(data.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/dashboard|intakes|opname/);
}

test.describe('N28 power_route evidence scope', () => {
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('show page: meterkastfoto not marked Vervangen door ronde after power_route', async ({ page }) => {
    await login(page, data);

    await page.goto(data.showUrl);
    await page.getByText('Foto’s en bestanden', { exact: false }).first().click();

    const fuseboxGallery = page.getByTestId(`gallery-upload-${data.fuseboxUploadId}`);
    await expect(fuseboxGallery).toBeVisible({ timeout: 15_000 });
    await expect(fuseboxGallery).not.toHaveAttribute('data-superseded', '1');
    await expect(fuseboxGallery.getByTestId('gallery-superseded')).toHaveCount(0);

    const routeGallery = page.getByTestId(`gallery-upload-${data.routeUploadId}`);
    await expect(routeGallery).toBeVisible();
    await expect(routeGallery).not.toHaveAttribute('data-superseded', '1');

    await expect(page.locator('body')).not.toContainText('Vervangen door ronde 1');
  });

  test('workspace gallery: meterkastfoto stays current after power_route round', async ({ page }) => {
    await login(page, data);

    await page.goto(data.workspaceUrl);
    await page.getByRole('heading', { name: /Foto/i }).first().click();

    const fuseboxGallery = page.getByTestId(`gallery-upload-${data.fuseboxUploadId}`);
    await expect(fuseboxGallery).toBeVisible({ timeout: 15_000 });
    await expect(fuseboxGallery).not.toHaveAttribute('data-superseded', '1');
    await expect(fuseboxGallery.getByTestId('gallery-superseded')).toHaveCount(0);
  });
  test('N71: installer defines the existing power route and then approves it', async ({ page }) => {
    await login(page, data);
    await page.goto(data.workspaceUrl + '#connection-' + data.powerConnectionId);
    const card = page.locator('#connection-' + data.powerConnectionId);
    await expect(card.getByText('Een bruikbare foto legt de stroomroute nog niet vast.', { exact: false })).toBeVisible();
    await expect(card.getByRole('button', { name: 'Route goedkeuren', exact: true })).toBeDisabled();
    await card.getByText('Stroomroute vastleggen', { exact: true }).click();
    await card.getByLabel('Route van stroomaansluiting naar aansluitpunt').fill('Meterkast via achtergevel naar buitenunit');
    await card.getByRole('button', { name: 'Route opslaan', exact: true }).click();
    await expect(card.getByRole('button', { name: 'Route goedkeuren', exact: true })).toBeEnabled();
    await expect(card.getByText('Meterkast via achtergevel naar buitenunit', { exact: true }).first()).toBeVisible();
    await card.getByRole('button', { name: 'Route goedkeuren', exact: true }).click();
    await expect(card.getByText('Goedgekeurd', { exact: true })).toBeVisible();
  });

});
