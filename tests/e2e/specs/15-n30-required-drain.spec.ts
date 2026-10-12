/**
 * Brief C — N30 bereikbaarheid catch-up + N3 afvoerkeuze bij directe upload.
 * Bootstrap via php tests/e2e/bootstrap-n30-wizard.php (retry zoals spec 10).
 */

import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import {
  clickNext,
  headingText,
  openCustomer,
  uploadPhoto,
  waitForLivewire,
  waitForPhotoAssessed,
  waitForSaved,
  type E2eScenarioPayload,
} from '../helpers/app';

type Bootstrap = {
  baseUrl: string;
  n30: E2eScenarioPayload;
  n3: E2eScenarioPayload;
};

function bootstrapOnce(): Bootstrap {
  const script = path.resolve('tests/e2e/bootstrap-n30-wizard.php');
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
      const line = raw
        .trim()
        .split('\n')
        .filter((l) => l.startsWith('{'))
        .at(-1);
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

async function listAnswers(
  request: import('@playwright/test').APIRequestContext,
  intakeId: number,
): Promise<Array<Record<string, unknown>>> {
  const response = await request.get(`/__e2e__/intakes/${intakeId}/answers`);
  expect(response.ok()).toBeTruthy();
  const body = (await response.json()) as { answers: Array<Record<string, unknown>> };
  return body.answers;
}

async function runN30Catchup(page: Page, payload: E2eScenarioPayload): Promise<void> {
  await openCustomer(page, payload);

  const confirm = page.getByRole('button', { name: /Klopt, verder/i });
  if (await confirm.count()) {
    await confirm.first().click();
  }

  await expect(page.getByRole('heading', { level: 1 })).toContainText(/geluidgevoelig/i, {
    timeout: 20_000,
  });

  const yes = page.getByRole('radio', { name: /^Ja$/i });
  await expect(yes).toBeVisible();
  const livewire = waitForLivewire(page).catch(() => undefined);
  await yes.check({ force: true });
  await livewire;
  await waitForSaved(page).catch(() => undefined);

  // Simuleer foto-invalidate catch-up-markering (bootstrap wist de AI-prefill buiten Livewire).
  await page.evaluate(() => {
    const root = document.querySelector('[wire\\:id]');
    const id = root?.getAttribute('wire:id');
    if (!id || !window.Livewire) {
      throw new Error('Livewire root not found');
    }
    window.Livewire.find(id)?.set('catchUpQuestionKeys', { outdoor_accessibility: true });
  });

  const before = await headingText(page);
  await clickNext(page);
  await page.waitForFunction(
    (prev) => {
      const h1 = document.querySelector('h1');
      const text = (h1?.textContent ?? '').replace(/\s+/g, ' ').trim();
      return text !== '' && text !== prev;
    },
    before,
    { timeout: 20_000 },
  );

  const after = await headingText(page);
  expect(after).toMatch(/bereikbaar/i);
  expect(after.toLowerCase()).not.toMatch(/koop|huur/);

  // Teller in de vraagkop (eyebrow) moet bij de zichtbare stap horen.
  const headingCounter = page.getByText(/Vraag\s+\d+\s+van\s+\d+/i).first();
  await expect(headingCounter).toBeVisible();
  const headingLabel = ((await headingCounter.textContent()) ?? '').trim();
  const match = /Vraag\s+(\d+)\s+van\s+(\d+)/i.exec(headingLabel);
  expect(match).not.toBeNull();
  expect(Number(match![1])).toBeGreaterThanOrEqual(1);
  expect(Number(match![1])).toBeLessThanOrEqual(Number(match![2]));
}

async function runN3DrainChoice(page: Page, request: import('@playwright/test').APIRequestContext, payload: E2eScenarioPayload): Promise<void> {
  await openCustomer(page, payload);

  const confirm = page.getByRole('button', { name: /Klopt, verder/i });
  if (await confirm.count()) {
    await confirm.first().click();
  }

  await expect(page.getByRole('heading', { level: 1 })).toContainText(/Afvoer in de buurt/i, {
    timeout: 20_000,
  });

  const choice = page.getByLabel(/Regenpijp of putje buiten in de buurt/i);
  await expect(choice).toBeVisible();
  const livewire = waitForLivewire(page).catch(() => undefined);
  await choice.check({ force: true });
  await livewire;

  await uploadPhoto(page, 'facade-around-house.jpg');
  await waitForPhotoAssessed(page);

  await expect(choice).toBeChecked();

  await clickNext(page);

  const answers = await listAnswers(request, payload.intake_id);
  const drain = answers.find((row) => row.question_key === 'drain_location');
  expect(drain).toBeTruthy();
  expect(drain?.value).toEqual({ value: 'outside_nearby' });
  expect(drain?.prefill_source ?? null).toBeNull();
}

test.describe('N30 bereikbaarheid + N3 afvoerkeuze', () => {
  test.describe.configure({ mode: 'serial' });

  let data: Bootstrap;

  test.beforeAll(() => {
    data = bootstrapOnce();
  });

  test('N30 desktop: Volgende springt terug naar bereikbaarheid', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 1366, height: 900 });
    await runN30Catchup(page, data.n30);
  });

  test('N30 mobile: Volgende springt terug naar bereikbaarheid', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 375, height: 812 });
    // Fresh intake for mobile so cursor state is clean.
    data = bootstrapOnce();
    await runN30Catchup(page, data.n30);
  });

  test('N3 desktop: keuze + directe upload bewaart drain_location', async ({ page, request }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 1366, height: 900 });
    data = bootstrapOnce();
    await runN3DrainChoice(page, request, data.n3);
  });

  test('N3 mobile: keuze + directe upload bewaart drain_location', async ({ page, request }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 375, height: 812 });
    data = bootstrapOnce();
    await runN3DrainChoice(page, request, data.n3);
  });
});
