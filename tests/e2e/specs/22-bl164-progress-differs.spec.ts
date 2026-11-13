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
 * BL-164: Verschilt per ruimte (3 kamers) — voortgang % stijgt monotoon en
 * blijft onder ~90% zolang kamerfoto’s open zijn (geen high-water-sprong naar 97%).
 */
test.describe('BL-164 progress differs-per-room', () => {
  test('percent stays under 90% at Kamer 1 photos; monotone through differs', async ({
    page,
    request,
  }) => {
    test.setTimeout(240_000);

    const payload = await createScenario(request, 'differs-per-room-floors');
    await openCustomer(page, payload);

    const readPercent = async (): Promise<number> => {
      const text = ((await page.getByTestId('progress-percent').textContent()) ?? '0').trim();

      return Number.parseInt(text.replace('%', ''), 10);
    };

    const openPercents: number[] = [await readPercent()];
    const recordOpen = async (): Promise<number> => {
      if (await page.getByTestId('customer-thank-you').count()) {
        return 100;
      }
      const p = await readPercent();
      openPercents.push(p);
      expect(p).toBeLessThan(90);

      return p;
    };

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      const before = await headingText(page);
      await confirm.first().click();
      await waitForStepChange(page, before).catch(() => undefined);
      await recordOpen();
    }

    // Reach shared floor + choose Verschilt per ruimte.
    for (let i = 0; i < 20; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }
      if (/verdieping/i.test(await headingText(page)) && (await page.getByTestId('shared-differs').count())) {
        break;
      }
      await answerCurrentStep(page, { preferSkipPhotos: true });
      await recordOpen();
    }

    await expect(page.getByTestId('shared-differs')).toBeVisible();
    const beforeDiffers = await headingText(page);
    const livewire = waitForLivewire(page).catch(() => undefined);
    await page.getByTestId('shared-differs').click();
    await livewire;
    await waitForStepChange(page, beforeDiffers).catch(() => undefined);
    await recordOpen();
    await expect(page.getByTestId('shared-differs')).toHaveCount(0);

    // Answer per-room floors / sun-differs until the first required room photo.
    let hitRoomPhoto = false;
    for (let i = 0; i < 50; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }

      if (await isPhotoStep(page)) {
        const skip = page.getByTestId('photo-skip');
        if (await skip.count()) {
          const before = await headingText(page);
          const lw = waitForLivewire(page).catch(() => undefined);
          await skip.first().click();
          await lw;
          await waitForStepChange(page, before).catch(() => undefined);
          await recordOpen();
          continue;
        }
        hitRoomPhoto = true;
        break;
      }

      if ((await page.getByTestId('shared-differs').count()) > 0) {
        const before = await headingText(page);
        const lw = waitForLivewire(page).catch(() => undefined);
        await page.getByTestId('shared-differs').click();
        await lw;
        await waitForStepChange(page, before).catch(() => undefined);
        await recordOpen();
        continue;
      }

      if (/verdieping|zon/i.test(await headingText(page))) {
        if ((await page.getByRole('radio').count()) > 0) {
          await selectFirstRadioAndSave(page);
          await clickNext(page);
          await recordOpen();
          continue;
        }
      }

      await answerCurrentStep(page, { preferSkipPhotos: true });
      await recordOpen();
    }

    expect(hitRoomPhoto).toBeTruthy();
    const atPhoto = await recordOpen();
    expect(atPhoto).toBeLessThan(90);

    // Eyebrow / body should still show room context (Kamer 1…).
    const body = ((await page.locator('body').innerText()) ?? '').toLowerCase();
    expect(/kamer|slaapkamer|ruimte|foto/.test(body)).toBeTruthy();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
      path: '/opt/cursor/artifacts/bl164-kamer1-foto-progress-390.png',
      fullPage: true,
    });

    for (let i = 0; i < 80; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }
      const missing = page.getByTestId('step-missing-alert');
      if (await missing.count()) {
        const links = missing.locator('button');
        if ((await links.count()) > 0) {
          const beforeLink = await headingText(page);
          await links.first().click();
          await waitForLivewire(page).catch(() => undefined);
          await waitForStepChange(page, beforeLink).catch(() => undefined);
        }
      }
      await answerCurrentStep(page, { preferSkipPhotos: true });
    }

    await expect(page.getByTestId('customer-thank-you')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('progress-percent')).toHaveText('100%');

    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
      path: '/opt/cursor/artifacts/bl164-afronding-progress-390.png',
      fullPage: true,
    });

    for (let i = 1; i < openPercents.length; i++) {
      expect(openPercents[i]).toBeGreaterThanOrEqual(openPercents[i - 1]!);
    }
    expect(Math.max(...openPercents)).toBeLessThan(90);
  });
});
