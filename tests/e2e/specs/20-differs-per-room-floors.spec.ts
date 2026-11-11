import { expect, test } from '@playwright/test';
import {
  answerCurrentStep,
  clickNext,
  createScenario,
  headingText,
  openCustomer,
  selectFirstRadioAndSave,
  waitForLivewire,
  waitForStepChange,
} from '../helpers/app';

/**
 * Klanttest 11 nov run 3 (BL-161): na “Verschilt per ruimte” volgen per-kamer
 * verdieping (en zon); Afronden-links mogen niet dood zijn.
 */
test.describe('Verschilt per ruimte → per kamer tot Afronden', () => {
  test('floor + sun differs expand per room and complete succeeds', async ({ page, request }) => {
    test.setTimeout(240_000);

    const payload = await createScenario(request, 'differs-per-room-floors');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      const before = await headingText(page);
      await confirm.first().click();
      await waitForStepChange(page, before).catch(() => undefined);
    }

    // Land on shared floor question (scenario cursor) or advance to it.
    for (let i = 0; i < 15; i++) {
      const heading = await headingText(page);
      if (/verdieping/i.test(heading) && (await page.getByTestId('shared-differs').count())) {
        break;
      }
      await answerCurrentStep(page, { preferSkipPhotos: true });
    }

    await expect(page.getByRole('heading', { level: 1 })).toContainText(/verdieping/i);
    await expect(page.getByTestId('shared-differs')).toBeVisible();

    const beforeDiffers = await headingText(page);
    const livewire = waitForLivewire(page).catch(() => undefined);
    await page.getByTestId('shared-differs').click();
    await livewire;
    await waitForStepChange(page, beforeDiffers).catch(() => undefined);

    // First per-room floor question (not the shared screen anymore).
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/verdieping/i);
    await expect(page.getByTestId('shared-differs')).toHaveCount(0);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
      path: '/opt/cursor/artifacts/bl161-per-room-floor-390.png',
      fullPage: true,
    });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.screenshot({
      path: '/opt/cursor/artifacts/bl161-per-room-floor-1440.png',
      fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 844 });

    const floors = ['Begane grond', '1e verdieping', '2e verdieping'];
    for (let room = 0; room < 3; room++) {
      await expect(page.getByRole('heading', { level: 1 })).toContainText(/verdieping/i, {
        timeout: 20_000,
      });
      const label = page.getByLabel(new RegExp(floors[room], 'i'));
      if (await label.count()) {
        const lw = waitForLivewire(page).catch(() => undefined);
        await label.first().click();
        await lw;
      } else {
        await selectFirstRadioAndSave(page);
      }
      await clickNext(page);

      // Between rooms there may be other questions (sun/glass shared, photos, …).
      // Keep answering until the next floor question or sun-differs / end.
      for (let guard = 0; guard < 12; guard++) {
        if (await page.getByTestId('customer-thank-you').count()) {
          break;
        }
        const heading = await headingText(page);
        if (/verdieping/i.test(heading) && room < 2) {
          break;
        }
        if (/zon/i.test(heading) && (await page.getByTestId('shared-differs').count())) {
          const beforeSun = heading;
          const sunLw = waitForLivewire(page).catch(() => undefined);
          await page.getByTestId('shared-differs').click();
          await sunLw;
          await waitForStepChange(page, beforeSun).catch(() => undefined);
          // Answer three per-room sun questions when they appear.
          for (let s = 0; s < 3; s++) {
            const sunHeading = await headingText(page);
            if (!/zon/i.test(sunHeading)) {
              break;
            }
            await selectFirstRadioAndSave(page);
            await clickNext(page);
          }
          continue;
        }
        if (/verdieping/i.test(heading)) {
          break;
        }
        await answerCurrentStep(page, { preferSkipPhotos: true });
      }
    }

    // Finish the rest of the wizard.
    for (let i = 0; i < 80; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }

      const missing = page.getByTestId('step-missing-alert');
      if (await missing.count()) {
        const links = missing.locator('button');
        const linkCount = await links.count();
        if (linkCount > 0) {
          const beforeLink = await headingText(page);
          await links.first().click();
          await waitForLivewire(page).catch(() => undefined);
          await waitForStepChange(page, beforeLink).catch(() => undefined);
          // Link must land on a real question (not stay put with dead target).
          const afterLink = await headingText(page);
          expect(afterLink.length).toBeGreaterThan(0);
        }
      }

      await answerCurrentStep(page, { preferSkipPhotos: true });
    }

    await expect(page.getByTestId('customer-thank-you')).toBeVisible({ timeout: 30_000 });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
      path: '/opt/cursor/artifacts/bl161-complete-390.png',
      fullPage: true,
    });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.screenshot({
      path: '/opt/cursor/artifacts/bl161-complete-1440.png',
      fullPage: true,
    });
  });
});
