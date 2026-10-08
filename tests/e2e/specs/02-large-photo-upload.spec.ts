import { expect, test } from '@playwright/test';
import {
  advanceUntil,
  createScenario,
  listUploads,
  openCustomer,
  uploadPhoto,
  waitForPhotoAssessed,
} from '../helpers/app';

test.describe('Large phone photo upload', () => {
  test('finding: 12 MP progressive JPEG on meterkast completes and is client-downscaled', async ({
    page,
    request,
  }) => {
    const payload = await createScenario(request, 'fusebox-upload');
    await openCustomer(page, payload);

    // Jump forward toward meterkast; seed leaves current_question at fusebox_photo but
    // wizard still builds from visible steps — advance until meterkast heading.
    await advanceUntil(page, /meterkast|groepenkast|fusebox/i, 40);

    await uploadPhoto(page, 'fusebox-12mp-progressive.jpg');
    await expect(page.getByText(/Foto opgeslagen|Uploaden/i).first()).toBeVisible({ timeout: 60_000 });
    await waitForPhotoAssessed(page, 120_000);

    const uploads = await listUploads(request, payload.intake_id);
    const fusebox = uploads.filter((u) => u.question_key === 'fusebox_photo');
    expect(fusebox.length).toBeGreaterThan(0);

    const stored = fusebox[fusebox.length - 1]!;
    const width = Number(stored.width ?? 0);
    const height = Number(stored.height ?? 0);
    const longEdge = Math.max(width, height);

    // Client downscale ≤2000 (BL-143); dossier max remains 2048.
    expect(longEdge).toBeGreaterThan(0);
    expect(longEdge).toBeLessThanOrEqual(2048);
    // Demotest 8 okt: keep source aspect (12 MP fixture is 4032×3024 → 4:3), not a square.
    expect(width).toBeGreaterThan(0);
    expect(height).toBeGreaterThan(0);
    expect(Math.abs(width / height - 4032 / 3024)).toBeLessThan(0.02);
    expect(stored.assessment_status).toBe('assessed');
  });
});
