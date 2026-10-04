import { expect, test } from '@playwright/test';
import {
  answerCurrentStep,
  advanceUntil,
  createScenario,
  headingText,
  isPhotoStep,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
  clickNext,
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
      const before = await headingText(page);
      await confirm.first().click();
      await page.waitForFunction(
        (prev) => {
          const h1 = document.querySelector('h1');
          const text = (h1?.textContent ?? '').replace(/\s+/g, ' ').trim();

          return text !== '' && text !== prev;
        },
        before,
        { timeout: 15_000 },
      ).catch(() => undefined);
    }

    // Reach the first room / overview photo when present; otherwise continue answering.
    await advanceUntil(page, /ruimte|woonkamer|overzicht|foto van|Ik bevestig/i, 25).catch(async () => {
      // If the seeded intake already skipped past overview copy, keep going from here.
    });

    if (await isPhotoStep(page)) {
      await uploadPhoto(page, 'room-overview-good.jpg');
      await waitForPhotoAssessed(page);
      await clickNext(page);
    }

    for (let i = 0; i < 50; i++) {
      if (await page.getByTestId('customer-thank-you').count()) {
        break;
      }

      const heading = (await headingText(page)).toLowerCase();
      expect(heading).not.toMatch(/ontbrekende wand|extra foto.*deur|stopcontactfoto|foto van (het )?stopcontact/);

      await answerCurrentStep(page, { preferSkipPhotos: true });
    }

    await expect(page.getByTestId('customer-thank-you')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('customer-thank-you')).toContainText(/Jouw deel is compleet/i);
    await expect(page.getByTestId('customer-thank-you')).not.toContainText(
      /outdoor_mount_type|room_type|wall_outlet_photo|pipe_route_photos/i,
    );
  });
});
