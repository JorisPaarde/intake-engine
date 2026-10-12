/**
 * Brief C — N37: Wijzigen bij bekend oppervlak (m²) opent matenscherm met waarde.
 * Scenario via createScenario (retry bij flaky sqlite).
 */

import { expect, test } from '@playwright/test';
import {
  clickNext,
  createScenario,
  headingText,
  openCustomer,
  waitForLivewire,
  waitForSaved,
} from '../helpers/app';

test.describe('N37 Wijzigen bekend oppervlak', () => {
  test.describe.configure({ mode: 'serial' });

  test('Wijzigen Oppervlak opent m²-veld met 30, wijziging terug naar overzicht', async ({
    page,
    request,
  }) => {
    let payload = null as Awaited<ReturnType<typeof createScenario>> | null;
    let lastError = '';
    for (let attempt = 1; attempt <= 3; attempt++) {
      try {
        payload = await createScenario(request, 'n37-edit-known-area');
        break;
      } catch (error) {
        lastError = error instanceof Error ? error.message : String(error);
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 500 * attempt);
      }
    }
    if (!payload) {
      throw new Error('createScenario n37-edit-known-area failed after retries: ' + lastError);
    }

    await openCustomer(page, payload);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
      /Dit hebben we al uit je aanvraag/i,
    );

    const areaRow = page
      .locator('li')
      .filter({ hasText: /Oppervlak \(m²\)/i })
      .filter({ has: page.getByRole('button', { name: /Wijzigen/i }) })
      .first();
    await expect(areaRow).toBeVisible();
    await expect(areaRow).toContainText(/30/);

    const livewire = waitForLivewire(page).catch(() => undefined);
    await areaRow.getByRole('button', { name: /Wijzigen/i }).click();
    await livewire;

    await expect(page.getByTestId('dimensions-group')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('area-only-field')).toBeVisible();
    const areaInput = page.getByTestId('area-only-field').locator('input');
    await expect(areaInput).toHaveValue(/30/);

    await areaInput.fill('28');
    await areaInput.blur();
    await waitForSaved(page).catch(() => undefined);

    await clickNext(page);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
      /Dit hebben we al uit je aanvraag/i,
      { timeout: 10_000 },
    );

    const heading = await headingText(page);
    expect(heading.toLowerCase()).toMatch(/aanvraag/);

    // Gewijzigde m² is klantantwoord (geen prefill meer) → niet meer in known-summary;
    // wel opgeslagen. Assert via E2E answers-API.
    const answersRes = await request.get(`/__e2e__/intakes/${payload.intake_id}/answers`);
    expect(answersRes.ok()).toBeTruthy();
    const body = (await answersRes.json()) as {
      answers: Array<{ question_key: string; section_instance_key: string | null; value: { number?: number } }>;
    };
    const area = body.answers.find(
      (a) => a.question_key === 'room_area_m2' && a.section_instance_key === 'room-1',
    );
    expect(area).toBeTruthy();
    expect(Number(area?.value?.number)).toBe(28);
  });
});
