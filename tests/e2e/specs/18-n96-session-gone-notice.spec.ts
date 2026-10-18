import { expect, test } from '@playwright/test';
import { createScenario, openCustomer } from '../helpers/app';

/**
 * N96: once the demo session (or customer link) is gone, Livewire updates
 * answer 404/410. The wizard must show a visible notice instead of staying
 * silently on the same question when the customer clicks Volgende.
 */
for (const status of [404, 410]) {
  test(`Livewire ${status} on Volgende shows a visible session notice`, async ({ page, request }) => {
    test.setTimeout(90_000);

    const payload = await createScenario(request, 'wizard-happy-path');
    await openCustomer(page, payload);

    const notice = page.getByTestId('customer-session-notice');
    await expect(notice).toBeHidden();

    await page.route(
      (url) => url.pathname.toLowerCase().includes('/livewire') && url.pathname.toLowerCase().endsWith('/update'),
      async (route) => {
        if (route.request().method() !== 'POST') {
          return route.continue();
        }

        return route.fulfill({ status, contentType: 'text/html', body: '<html><body>gone</body></html>' });
      },
    );

    await page
      .getByRole('button', { name: /^(Volgende|Klopt, verder)$/i })
      .first()
      .click();

    await expect(notice).toBeVisible({ timeout: 15_000 });
    await expect(notice).toContainText(/demosessie verlopen of beëindigd/i);
    await expect(notice.getByRole('button', { name: 'Pagina vernieuwen' })).toBeVisible();
  });
}
