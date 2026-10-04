import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  clickNext,
  createScenario,
  fillTextAndBlur,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Wizard happy path', () => {
  test('finding: empty living room reaches thank-you without raw keys or extra socket question', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'wizard-happy-path');
    await openCustomer(page, payload);

    // Known-details / known-summary after request text + prefilled rooms.
    await advanceUntil(page, /Klopt dit|bekend|samenvatting|Wat we al weten|controleren/i, 8).catch(async () => {
      // Some templates land on request_reason first.
      if (await page.locator('textarea, input[type="text"]').count()) {
        await fillTextAndBlur(page, 'Airco in de woonkamer voor koelen en verwarmen.');
        await clickNext(page);
      }
    });

    // Confirm known summary when present.
    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    // Room photo — good overview of empty living room (FakeAi: outlet present, no extra overview).
    await advanceUntil(page, /ruimte|woonkamer|overzicht|foto/i, 15);
    if (await page.locator('input[type="file"]').count()) {
      await uploadPhoto(page, 'room-overview-good.jpg');
      await waitForPhotoAssessed(page);
      await expect(page.getByText(/stopcontact/i)).toHaveCount(0);
      await clickNext(page);
    }

    // Walk remaining steps with sensible defaults / skips. Cap to keep runtime reasonable.
    for (let i = 0; i < 35; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }

      const heading = ((await page.locator('h1').first().textContent()) ?? '').toLowerCase();

      // Never ask an always-on wall/door/socket extra photo for a usable empty living room.
      expect(heading).not.toMatch(/ontbrekende wand|extra foto.*deur|stopcontactfoto/i);

      if (await page.locator('input[type="file"]').count()) {
        const skip = page.getByRole('button', { name: /Weet ik niet|sla over|Overslaan/i });
        if (await skip.count()) {
          await skip.first().click();
          continue;
        }
        await uploadPhoto(page, heading.includes('meter') ? 'fusebox-12mp-progressive.jpg' : 'facade-around-house.jpg');
        await waitForPhotoAssessed(page).catch(() => undefined);
        await clickNext(page);
        continue;
      }

      if (await page.locator('input[type="radio"]').count()) {
        await page.locator('label').filter({ has: page.locator('input[type="radio"]') }).first().click();
        await page.waitForTimeout(200);
        await clickNext(page);
        continue;
      }

      if (await page.locator('input[type="number"], input[type="text"], textarea').count()) {
        const field = page.locator('input[type="number"], input[type="text"], textarea').first();
        const type = await field.getAttribute('type');
        await field.fill(type === 'number' ? '3' : 'n.v.t.');
        await field.blur();
        await page.waitForTimeout(200);
        await clickNext(page);
        continue;
      }

      if (await page.getByRole('button', { name: /^Afronden$/ }).count()) {
        // Consent / truth confirmation often need checkboxes.
        for (const box of await page.locator('input[type="checkbox"]').all()) {
          if (!(await box.isChecked())) {
            await box.check();
          }
        }
        await page.getByRole('button', { name: /^Afronden$/ }).click();
        continue;
      }

      if (await page.getByRole('button', { name: /^Volgende$/ }).count()) {
        await clickNext(page);
        continue;
      }

      break;
    }

    await expect(page.getByTestId('customer-thank-you')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('customer-thank-you')).toContainText(/Jouw deel is compleet/i);
    await expect(page.getByTestId('customer-thank-you')).not.toContainText(/outdoor_mount_type|room_type|wall_outlet|pipe_route/i);
    await expect(page.getByText(/stopcontactfoto|wall_outlet_photo/i)).toHaveCount(0);
  });
});
