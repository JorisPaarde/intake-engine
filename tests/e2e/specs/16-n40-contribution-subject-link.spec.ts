/**
 * Brief B / N40: contribution evidence follows decision_area_key after onderdeel change.
 *
 * Bootstrap rematches stroom-subject → condens and completes a photo follow-up.
 * UI must show “Nieuwe aanvulling bij dit onderdeel” under Condensafvoer, not Stroomtoevoer.
 */

import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

type Bootstrap = {
  baseUrl: string;
  email: string;
  password: string;
  workspaceUrl: string;
  intakeId: number;
  powerConnectionId: number;
  condensateConnectionId: number;
  taskSubjectId: number;
  condensateSubjectId: number;
  powerSubjectId: number;
};

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-n40-contribution-subject-link.php');
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

test.describe('N40 contribution subject link (stroom→condens)', () => {
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('bootstrap rematched task subject onto condensate', () => {
    expect(data.taskSubjectId).toBe(data.condensateSubjectId);
    expect(data.taskSubjectId).not.toBe(data.powerSubjectId);
  });

  test('workspace shows aanvulling under Condensafvoer, not Stroomtoevoer', async ({ page }) => {
    await login(page, data);
    await page.goto(data.workspaceUrl);

    // Area contribution sits inside a closed <details> unless that area is the
    // first open action — open Condensafvoer before asserting visibility.
    const condensateArea = page.locator('#dossier-area-condensate');
    await condensateArea.scrollIntoViewIfNeeded();
    await condensateArea.evaluate((el) => {
      (el as HTMLDetailsElement).open = true;
    });
    await expect(page.getByTestId('area-contribution-condensate')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId('area-contribution-power')).toHaveCount(0);

    const condensateCard = page.locator(`#connection-${data.condensateConnectionId}`);
    await condensateCard.scrollIntoViewIfNeeded();
    await expect(condensateCard.getByTestId('subject-contribution-evidence')).toBeVisible();
    await expect(condensateCard.getByText('Nieuwe aanvulling bij dit onderdeel')).toBeVisible();

    const powerCard = page.locator(`#connection-${data.powerConnectionId}`);
    await expect(powerCard.getByTestId('subject-contribution-evidence')).toHaveCount(0);
  });
});
