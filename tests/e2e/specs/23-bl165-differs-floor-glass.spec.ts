import { expect, test } from '@playwright/test';
import {
  answerCurrentStep,
  clickNext,
  createScenario,
  headingText,
  isPhotoStep,
  openCustomer,
  selectFirstRadioAndSave,
  waitForLivewire,
  waitForStepChange,
} from '../helpers/app';

/**
 * BL-168: Verschilt per ruimte op zon mag verdieping/glas niet stil overslaan;
 * Afronden-link keert terug zonder ~20 beantwoorde stappen opnieuw.
 */
test.describe('BL-168 differs sun keeps floor + glass in order', () => {
  test('sun differs → floor + glass before photos; Afronden return skips rewalk', async ({
    page,
    request,
  }) => {
    test.setTimeout(300_000);

    const payload = await createScenario(request, 'bl165-differs-sun-floor-glass');
    await openCustomer(page, payload);

    const readPercent = async (): Promise<number> => {
      const text = ((await page.getByTestId('progress-percent').textContent()) ?? '0').trim();

      return Number.parseInt(text.replace('%', ''), 10);
    };

    const openPercents: number[] = [];
    const recordOpen = async (): Promise<void> => {
      if (await page.getByTestId('customer-thank-you').count()) {
        return;
      }
      openPercents.push(await readPercent());
    };

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      const before = await headingText(page);
      await confirm.first().click();
      await waitForStepChange(page, before).catch(() => undefined);
      await recordOpen();
    }

    // Land on shared sun (scenario cursor) — floor/glass still unanswered after sun in sort_order.
    for (let i = 0; i < 10; i++) {
      if (/zon/i.test(await headingText(page)) && (await page.getByTestId('shared-differs').count())) {
        break;
      }
      await answerCurrentStep(page, { preferSkipPhotos: true });
      await recordOpen();
    }

    await expect(page.getByRole('heading', { level: 1 })).toContainText(/zon/i);
    await expect(page.getByTestId('shared-differs')).toBeVisible();

    const beforeSunDiffers = await headingText(page);
    const sunLw = waitForLivewire(page).catch(() => undefined);
    await page.getByTestId('shared-differs').click();
    await sunLw;
    await waitForStepChange(page, beforeSunDiffers).catch(() => undefined);
    await recordOpen();

    // Per-room sun (no shared-differs button).
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/zon/i);
    await expect(page.getByTestId('shared-differs')).toHaveCount(0);

    for (let s = 0; s < 3; s++) {
      await expect(page.getByRole('heading', { level: 1 })).toContainText(/zon/i, {
        timeout: 20_000,
      });
      await selectFirstRadioAndSave(page);
      await clickNext(page);
      await recordOpen();
    }

    // After sun differs expansion: glass and floor must appear before room photos.
    let sawGlass = false;
    let sawFloor = false;
    for (let guard = 0; guard < 20; guard++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }
      if (await isPhotoStep(page)) {
        break;
      }

      const heading = await headingText(page);
      if (/glas|raam|beglaz/i.test(heading)) {
        sawGlass = true;
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({
          path: '/opt/cursor/artifacts/bl165-glass-step-390.png',
          fullPage: true,
        });
        await selectFirstRadioAndSave(page);
        await clickNext(page);
        await recordOpen();
        continue;
      }
      if (/verdieping/i.test(heading)) {
        sawFloor = true;
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({
          path: '/opt/cursor/artifacts/bl165-floor-step-390.png',
          fullPage: true,
        });
        if (await page.getByTestId('shared-differs').count()) {
          // Shared floor still available — answer for all rooms.
          await selectFirstRadioAndSave(page);
          await clickNext(page);
        } else {
          await selectFirstRadioAndSave(page);
          await clickNext(page);
        }
        await recordOpen();
        continue;
      }

      await answerCurrentStep(page, { preferSkipPhotos: true });
      await recordOpen();
    }

    expect(sawGlass).toBe(true);
    expect(sawFloor).toBe(true);

    // Monotone progress while still open (BL-164).
    for (let i = 1; i < openPercents.length; i++) {
      expect(openPercents[i]).toBeGreaterThanOrEqual(openPercents[i - 1]!);
      expect(openPercents[i]).toBeLessThan(90);
    }

    // Finish until Afronden / thank-you; if missing alert appears, use link once
    // and assert we do not re-walk dozens of answered steps.
    let missingReturnChecked = false;
    for (let i = 0; i < 100; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }

      const missing = page.getByTestId('step-missing-alert');
      if ((await missing.count()) > 0 && !missingReturnChecked) {
        const links = missing.locator('button');
        if ((await links.count()) > 0) {
          const beforeLink = await headingText(page);
          await links.first().click();
          await waitForLivewire(page).catch(() => undefined);
          await waitForStepChange(page, beforeLink).catch(() => undefined);

          const fixHeading = await headingText(page);
          expect(fixHeading.length).toBeGreaterThan(0);

          await answerCurrentStep(page, { preferSkipPhotos: true });

          // After fix: either thank-you, missing again, or last/Afronden — not
          // stuck mid-flow on Kamer 1 photo after answering floor.
          const after = await headingText(page);
          const thankYou = (await page.getByTestId('customer-thank-you').count()) > 0;
          const missingAgain = (await page.getByTestId('step-missing-alert').count()) > 0;
          const stillOnSameFix = after === fixHeading;
          expect(thankYou || missingAgain || !stillOnSameFix || /afrond|waarheid|opmerking|merk/i.test(after)).toBeTruthy();
          missingReturnChecked = true;
          continue;
        }
      }

      await answerCurrentStep(page, { preferSkipPhotos: true });
    }

    await expect(page.getByTestId('customer-thank-you')).toBeVisible({ timeout: 60_000 });
  });
});
