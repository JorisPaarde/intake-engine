import { expect, test } from '@playwright/test';
import {
  createScenario,
  headingText,
  openCustomer,
  waitForLivewire,
} from '../helpers/app';

test.describe('Resume after known-summary Wijzigen', () => {
  test('finding: Wijzigen + refresh lands on known-summary, not past unanswered required', async ({
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

    // Root fix: persisted cursor is known-summary, so refresh returns there.
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Dit hebben we al uit je aanvraag/i);
    const heading = await headingText(page);
    expect(heading.toLowerCase()).not.toMatch(/foto|overzichtsfoto/);

    // Klopt, verder → first unanswered required (cooling_heating), never skipped.
    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    const livewireNext = waitForLivewire(page).catch(() => undefined);
    await confirm.click();
    await livewireNext;

    await expect(page.getByRole('heading', { level: 1 })).toContainText(/koelen|verwarmen|beide/i);
  });
});
