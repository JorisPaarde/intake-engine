import { expect, type APIRequestContext, type Page } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export const fixturesDir = path.resolve(__dirname, '../fixtures');

export type E2eScenarioPayload = {
  scenario: string;
  intake_id: number;
  access_token: string;
  customer_url: string;
  ai_scenario?: string;
  target_question_key?: string;
  follow_up_item_ids?: number[];
};

export async function createScenario(
  request: APIRequestContext,
  scenario: string,
): Promise<E2eScenarioPayload> {
  const response = await request.post(`/__e2e__/scenarios/${scenario}`);
  expect(response.ok(), `create scenario ${scenario}: ${response.status()}`).toBeTruthy();
  return (await response.json()) as E2eScenarioPayload;
}

export async function setAiScenario(request: APIRequestContext, scenario: string): Promise<void> {
  const response = await request.post('/__e2e__/ai-scenario', {
    data: { scenario },
  });
  expect(response.ok()).toBeTruthy();
}

export async function listUploads(
  request: APIRequestContext,
  intakeId: number,
): Promise<Array<Record<string, unknown>>> {
  const response = await request.get(`/__e2e__/intakes/${intakeId}/uploads`);
  expect(response.ok()).toBeTruthy();
  const body = (await response.json()) as { uploads: Array<Record<string, unknown>> };
  return body.uploads;
}

export function fixture(name: string): string {
  return path.join(fixturesDir, name);
}

export async function openCustomer(page: Page, payload: E2eScenarioPayload): Promise<void> {
  await page.goto(payload.customer_url);
  await expect(page.getByText(/Digitale Opname|Aanvulling voor/i).first()).toBeVisible();
}

export async function clickNext(page: Page): Promise<void> {
  const next = page.getByRole('button', { name: /Volgende|Klopt, verder|Afronden|Aanvulling versturen/i }).first();
  await next.click();
}

export async function chooseRadioByLabel(page: Page, label: string | RegExp): Promise<void> {
  const option = page.locator('label').filter({ hasText: label }).first();
  await option.click();
  await page.getByText('Opgeslagen').first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined);
}

export async function fillTextAndBlur(page: Page, value: string): Promise<void> {
  const input = page.locator('input[type="text"], textarea, input[type="number"]').first();
  await input.fill(value);
  await input.blur();
  await page.getByText('Opgeslagen').first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined);
}

export async function uploadPhoto(page: Page, fileName: string): Promise<void> {
  const fileInput = page.locator('input[type="file"]').first();
  await fileInput.setInputFiles(fixture(fileName));
}

export async function waitForPhotoAssessed(page: Page, timeoutMs = 90_000): Promise<void> {
  await expect(page.getByTestId('photo-receipt-status')).toContainText(/Beoordeeld|Ontvangen/, {
    timeout: timeoutMs,
  });
  // Prefer terminal Beoordeeld when fake AI + sync/queue worker are running.
  await expect(page.getByTestId('photo-receipt-status')).toContainText('Beoordeeld', {
    timeout: timeoutMs,
  });
}

/**
 * Advance the wizard until the page title/heading matches, or maxSteps is hit.
 */
export async function advanceUntil(
  page: Page,
  match: RegExp,
  maxSteps = 40,
): Promise<void> {
  for (let i = 0; i < maxSteps; i++) {
    const heading = page.locator('h1').first();
    const text = ((await heading.textContent()) ?? '').trim();
    if (match.test(text)) {
      return;
    }

    // Optional photo skip
    const skip = page.getByRole('button', { name: /Weet ik niet|sla over|Overslaan/i });
    if (await skip.count()) {
      await skip.first().click();
      await page.waitForTimeout(300);
      continue;
    }

    // Photo step without upload yet — leave for caller
    if (await page.locator('input[type="file"]').count()) {
      if (match.test('foto') || /foto|meterkast|gevel|ruimte|overzicht/i.test(text)) {
        // If looking for a photo question and we are on one, stop when heading matches.
        return;
      }
    }

    const next = page.getByRole('button', { name: /^Volgende$|^Klopt, verder$|^Afronden$/ });
    if (!(await next.count())) {
      throw new Error(`Stuck advancing wizard at: ${text}`);
    }
    await next.first().click();
    await page.waitForTimeout(250);
  }
  throw new Error(`Did not reach step matching ${match} within ${maxSteps} steps`);
}
