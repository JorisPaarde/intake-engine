import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  clickNext,
  createScenario,
  headingText,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Wizard happy path', () => {
  test('finding: empty living room reaches thank-you without raw keys or extra socket question', async ({
    page,
    request,
  }) => {
    test.setTimeout(180_000);

    const payload = await createScenario(request, 'wizard-happy-path');
    await openCustomer(page, payload);

    const confirm = page.getByRole('button', { name: /Klopt, verder/i });
    if (await confirm.count()) {
      await confirm.first().click();
    }

    await advanceUntil(page, /ruimte|woonkamer|overzicht|foto van/i, 20);

    if (await page.locator('input[type="file"]').count()) {
      await uploadPhoto(page, 'room-overview-good.jpg');
      await waitForPhotoAssessed(page);
      await clickNext(page);
    }

    for (let i = 0; i < 45; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }

      const heading = (await headingText(page)).toLowerCase();
      expect(heading).not.toMatch(/ontbrekende wand|extra foto.*deur|stopcontactfoto|foto van (het )?stopcontact/);

      // Closing wishes: multi-field screen — pick "Geen voorkeur" + first planning radio.
      if (/merk|planning|opmerkingen/i.test(heading)) {
        const geen = page.getByLabel(/Geen voorkeur/i);
        if (await geen.count()) {
          await geen.first().check();
        }
        const plan = page.locator('input[type="radio"]:visible').first();
        if (await plan.count()) {
          await plan.check();
        }
        await clickNext(page);
        continue;
      }

      if (await page.locator('input[type="file"]').count()) {
        const skip = page.getByTestId('photo-skip');
        if (await skip.count()) {
          await skip.first().click({ timeout: 5_000 }).catch(() => undefined);
          await page.waitForTimeout(400);
          continue;
        }
        const file = heading.includes('meter') ? 'fusebox-12mp-progressive.jpg' : 'facade-around-house.jpg';
        await uploadPhoto(page, file);
        await waitForPhotoAssessed(page).catch(() => undefined);
        await clickNext(page);
        continue;
      }

      if (await page.locator('input[type="checkbox"]:visible').count()) {
        for (const box of await page.locator('input[type="checkbox"]:visible').all()) {
          if (!(await box.isChecked())) {
            await box.check();
          }
        }
      }

      if (await page.locator('input[type="radio"]:visible').count()) {
        await page.locator('label').filter({ has: page.locator('input[type="radio"]') }).first().click();
        await page.waitForTimeout(150);
      }

      if (await page.locator('textarea:visible, input[type="text"]:visible, input[type="number"]:visible').count()) {
        const field = page.locator('textarea:visible, input[type="text"]:visible, input[type="number"]:visible').first();
        const type = await field.getAttribute('type');
        await field.fill(type === 'number' ? '3' : 'n.v.t.');
        await field.blur();
        await page.waitForTimeout(150);
      }

      if (await page.getByRole('button', { name: /^Afronden$/ }).count()) {
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
    await expect(page.getByTestId('customer-thank-you')).not.toContainText(
      /outdoor_mount_type|room_type|wall_outlet_photo|pipe_route_photos/i,
    );
  });
});
