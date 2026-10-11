import { expect, test } from '@playwright/test';
import {
  createScenario,
  headingText,
  openCustomer,
  waitForLivewire,
  waitForSaved,
} from '../helpers/app';

test.describe('Resume after known-summary Wijzigen', () => {
  test('finding: Wijzigen + refresh lands on first unanswered required, not past it', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'resume-after-known-edit');
    await openCustomer(page, payload);

    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Dit hebben we al uit je aanvraag/i);

    // Floor row specifically ("Op welke verdieping"), not room_type whose instance label also mentions verdieping.
    const floorRow = page
      .locator('li')
      .filter({ hasText: /Op welke verdieping/i })
      .filter({ has: page.getByRole('button', { name: /Wijzigen/i }) })
      .first();
    await expect(floorRow).toBeVisible();
    const livewire = waitForLivewire(page).catch(() => undefined);
    await floorRow.getByRole('button', { name: /Wijzigen/i }).click();
    await livewire;

    await expect(page.getByRole('heading', { level: 1 })).toContainText(/verdieping/i, { timeout: 10_000 });

    await page.reload();
    await expect(page.getByText(/Digitale Opname/i).first()).toBeVisible();

    const heading = await headingText(page);
    expect(heading.toLowerCase()).not.toMatch(/foto|overzichtsfoto/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/koelen|verwarmen|beide/i);
  });

  test('finding: Afronden missing link → answer → jumps to next missing, not room photos', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'resume-afronden-return');
    await openCustomer(page, payload);

    // Scenario starts on privacy (late exact cursor) so Afronden is available.
    const afronden = page.getByRole('button', { name: /^Afronden$/i });
    await expect(afronden).toBeVisible({ timeout: 15_000 });
    const livewireComplete = waitForLivewire(page).catch(() => undefined);
    await afronden.click();
    await livewireComplete;

    await expect(page.getByTestId('step-missing-alert')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/Nog niet alles is ingevuld/i)).toBeVisible();

    const missingLink = page
      .getByTestId('step-missing-alert')
      .getByRole('button', { name: /koelen|verwarmen|beide/i });
    await expect(missingLink).toBeVisible();
    const livewireMissing = waitForLivewire(page).catch(() => undefined);
    await missingLink.click();
    await livewireMissing;

    await expect(page.getByRole('heading', { level: 1 })).toContainText(/koelen|verwarmen|beide/i);

    const choice = page.locator('label').filter({ hasText: /Beide|Koelen en verwarmen|Koelen/i }).first();
    await choice.click();
    await waitForSaved(page).catch(() => undefined);

    const beforeNext = await headingText(page);
    const nextBtn = page.getByRole('button', { name: /^(Volgende|Afronden)$/i }).first();
    const livewireNext = waitForLivewire(page).catch(() => undefined);
    await nextBtn.click();
    await livewireNext;

    await page.waitForFunction(
      (prev) => {
        const h1 = document.querySelector('h1');
        const text = (h1?.textContent ?? '').replace(/\s+/g, ' ').trim();

        return text !== '' && text !== prev;
      },
      beforeNext,
      { timeout: 15_000 },
    ).catch(() => undefined);

    // Back at the late/Afronden step (privacy), not walking room-photo questions.
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/toestemming/i);
    await expect(page.getByRole('button', { name: /^Afronden$/i })).toBeVisible();
  });
});
