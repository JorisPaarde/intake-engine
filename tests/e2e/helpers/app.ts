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
  if (! response.ok()) {
    const body = await response.text();
    throw new Error(`create scenario ${scenario}: ${response.status()} ${body.slice(0, 400)}`);
  }
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

export async function headingText(page: Page): Promise<string> {
  return ((await page.locator('h1').first().textContent()) ?? '').replace(/\s+/g, ' ').trim();
}

/** Wait for the next successful Livewire POST (wire:model.live / navigation). */
export async function waitForLivewire(page: Page, timeoutMs = 15_000): Promise<void> {
  await page.waitForResponse(
    (response) => {
      if (response.request().method() !== 'POST') {
        return false;
      }
      const url = response.url();

      return (url.includes('/livewire/') || url.includes('/livewire')) && response.ok();
    },
    { timeout: timeoutMs },
  );
}

/** Livewire wire:model.live / blur save confirmation (text may already be present from a prior step). */
export async function waitForSaved(page: Page, timeoutMs = 15_000): Promise<void> {
  await waitForLivewire(page, timeoutMs).catch(async () => {
    await page.getByText('Opgeslagen').first().waitFor({ state: 'visible', timeout: 3_000 }).catch(() => undefined);
  });
}

/**
 * Wait until the wizard leaves the current h1 (or reaches thank-you).
 * Prefer this over fixed timeouts after Volgende / skip / Klopt verder.
 */
export async function waitForStepChange(page: Page, previousHeading: string, timeoutMs = 20_000): Promise<void> {
  await page.waitForFunction(
    (prev) => {
      if (document.querySelector('[data-testid="customer-thank-you"]')) {
        return true;
      }
      const h1 = document.querySelector('h1');
      const text = (h1?.textContent ?? '').replace(/\s+/g, ' ').trim();

      return text !== '' && text !== prev;
    },
    previousHeading,
    { timeout: timeoutMs },
  );
}

export async function clickNext(page: Page): Promise<void> {
  const before = await headingText(page);
  const next = page.getByRole('button', { name: /^(Volgende|Klopt, verder|Afronden|Aanvulling versturen)$/i }).first();
  const livewire = waitForLivewire(page).catch(() => undefined);
  await next.click();
  await livewire;
  await waitForStepChange(page, before).catch(() => undefined);
}

export async function fillTextAndBlur(page: Page, value: string): Promise<void> {
  const input = page.locator('textarea:visible, input[type="text"]:visible, input[type="number"]:visible').first();
  await input.fill(value);
  const livewire = waitForLivewire(page).catch(() => undefined);
  await input.blur();
  await livewire;
}

/**
 * Photo steps render either the intake upload zone (`data-upload-timing="1"`)
 * or the follow-up picker (`follow-up-photo-input-*` / “Foto's maken of kiezen”).
 * Do NOT use bare `input[type=file]`: sr-only/stale morph nodes cause false positives.
 */
export async function isPhotoStep(page: Page): Promise<boolean> {
  if (await page.locator('[data-upload-timing="1"]').count()) {
    return true;
  }
  if (await page.getByTestId('photo-skip').count()) {
    return true;
  }
  if (await page.getByText(/Foto's maken of kiezen/i).count()) {
    return true;
  }
  if (await page.locator('input[id^="photo-input-"], input[id^="follow-up-photo-input-"]').count()) {
    return true;
  }

  return false;
}

export function photoFileInput(page: Page) {
  return page.locator(
    [
      '[data-upload-timing="1"] input[type="file"]',
      'input[id^="follow-up-photo-input-"]',
      'input[id^="photo-input-"]',
      'label:has-text("Foto\'s maken of kiezen") input[type="file"]',
    ].join(', '),
  ).first();
}


export async function uploadPhoto(page: Page, fileName: string): Promise<void> {
  const fileInput = photoFileInput(page);
  await expect(fileInput).toBeAttached({ timeout: 10_000 });
  await fileInput.setInputFiles(fixture(fileName));
}

export async function waitForPhotoAssessed(page: Page, timeoutMs = 90_000): Promise<void> {
  const receipt = page.getByTestId('photo-receipt-status');
  const followUp = page.getByTestId('follow-up-item-status');
  if (await receipt.count()) {
    await expect(receipt).toContainText('Beoordeeld', { timeout: timeoutMs });

    return;
  }
  if (await followUp.count()) {
    await expect(followUp).toContainText('Beoordeeld', { timeout: timeoutMs });

    return;
  }
  await expect(page.getByText(/Status:\s*Beoordeeld/i)).toBeVisible({ timeout: timeoutMs });
}

export async function selectFirstRadioAndSave(page: Page): Promise<void> {
  const radio = page.getByRole('radio').first();
  await expect(radio).toBeVisible({ timeout: 10_000 });
  const livewire = waitForLivewire(page).catch(() => undefined);
  await radio.check({ force: true });
  await livewire;
  await expect(radio).toBeChecked({ timeout: 5_000 });
}


export async function answerCurrentStep(page: Page, options?: { preferSkipPhotos?: boolean }): Promise<void> {
  const preferSkip = options?.preferSkipPhotos ?? true;
  const before = await headingText(page);

  if (await page.getByTestId('customer-thank-you').count()) {
    return;
  }

  const confirm = page.getByRole('button', { name: /^Klopt, verder$/i });
  if (await confirm.count()) {
    await confirm.first().click();
    await waitForStepChange(page, before).catch(() => undefined);

    return;
  }

  // Closing wishes: multi-field screen.
  if (/merk|planning|opmerkingen/i.test(before)) {
    const geen = page.getByLabel(/Geen voorkeur/i);
    if (await geen.count()) {
      const livewire = waitForLivewire(page).catch(() => undefined);
      await geen.first().check();
      await livewire;
    }
    const plan = page.locator('label').filter({ has: page.locator('input[type="radio"]') }).first();
    if (await plan.count()) {
      const livewire = waitForLivewire(page).catch(() => undefined);
      await plan.click();
      await livewire;
    }
    await clickNext(page);

    return;
  }

  if (await isPhotoStep(page)) {
    const skip = page.getByTestId('photo-skip');
    if (preferSkip && (await skip.count())) {
      const livewire = waitForLivewire(page).catch(() => undefined);
      await skip.first().click();
      await livewire;
      await waitForStepChange(page, before);

      return;
    }

    const skipLabel = page.getByRole('button', { name: /Weet ik niet|sla over|Overslaan/i });
    if (preferSkip && (await skipLabel.count())) {
      const livewire = waitForLivewire(page).catch(() => undefined);
      await skipLabel.first().click();
      await livewire;
      await waitForStepChange(page, before);

      return;
    }

    const heading = before.toLowerCase();
    const file = heading.includes('meter')
      ? 'fusebox-12mp-progressive.jpg'
      : heading.includes('ruimte') || heading.includes('woonkamer') || heading.includes('overzicht')
        ? 'room-overview-good.jpg'
        : 'facade-around-house.jpg';

    await uploadPhoto(page, file);
    await waitForPhotoAssessed(page).catch(() => undefined);
    await clickNext(page);

    return;
  }

  if (await page.locator('input[type="checkbox"]:visible').count()) {
    for (const box of await page.locator('input[type="checkbox"]:visible').all()) {
      if (! (await box.isChecked())) {
        const livewire = waitForLivewire(page).catch(() => undefined);
        await box.check();
        await livewire;
      }
    }
  }

  if (await page.locator('input[type="radio"]:visible').count()) {
    await selectFirstRadioAndSave(page);
  }

  if (await page.locator('textarea:visible, input[type="text"]:visible, input[type="number"]:visible').count()) {
    const field = page.locator('textarea:visible, input[type="text"]:visible, input[type="number"]:visible').first();
    const type = await field.getAttribute('type');
    await field.fill(type === 'number' ? '3' : 'E2E antwoord');
    const livewire = waitForLivewire(page).catch(() => undefined);
    await field.blur();
    await livewire;
  }

  if (await page.getByRole('button', { name: /^Afronden$/ }).count()) {
    await page.getByRole('button', { name: /^Afronden$/ }).click();
    await waitForStepChange(page, before).catch(() => undefined);

    return;
  }

  if (await page.getByRole('button', { name: /^Volgende$/ }).count()) {
    await clickNext(page);

    return;
  }

  throw new Error(`Stuck advancing wizard at: ${before}`);
}

/**
 * Advance until the current h1 matches `match`.
 * Fills simple fields / skips optional photos along the way.
 */
export async function advanceUntil(
  page: Page,
  match: RegExp,
  maxSteps = 40,
): Promise<void> {
  for (let i = 0; i < maxSteps; i++) {
    if (await page.getByTestId('customer-thank-you').count()) {
      throw new Error(`Reached thank-you before matching ${match}`);
    }

    const text = await headingText(page);
    if (match.test(text)) {
      return;
    }

    await answerCurrentStep(page, { preferSkipPhotos: true });
  }
  throw new Error(`Did not reach step matching ${match} within ${maxSteps} steps (last: ${await headingText(page)})`);
}
