import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    JPEG_QUALITY,
    MAX_LONG_EDGE,
    computeTargetSize,
    preparePhotoForUpload,
    shouldDownscale,
    withTimeout,
} from './photo-prepare.js';

describe('photo-prepare (BL-143)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('targets max long edge 2000 at jpeg quality 0.85', () => {
        expect(MAX_LONG_EDGE).toBe(2000);
        expect(JPEG_QUALITY).toBe(0.85);
        expect(computeTargetSize(3024, 4032)).toEqual({
            width: 1500,
            height: 2000,
            scale: 2000 / 4032,
        });
    });

    it('keeps files that are already small enough', () => {
        const file = { size: 100_000, type: 'image/jpeg' };
        expect(shouldDownscale(file, 1200, 900)).toBe(false);
        expect(shouldDownscale({ size: 2_200_000, type: 'image/jpeg' }, 3024, 4032)).toBe(true);
    });

    it('withTimeout rejects after the deadline (fake timers)', async () => {
        const pending = withTimeout(new Promise(() => {}), 8_000, 'downscale-timeout');
        const assertion = expect(pending).rejects.toThrow('downscale-timeout');
        await vi.advanceTimersByTimeAsync(8_000);
        await assertion;
    });

    it('falls back to the original file when decode deps fail', async () => {
        const original = new File([new Uint8Array([1, 2, 3])], 'meterkast.jpg', { type: 'image/jpeg' });
        const resultPromise = preparePhotoForUpload(original, {
            createBitmap: async () => {
                throw new Error('decode-failed');
            },
            loadImage: async () => {
                throw new Error('image-load-failed');
            },
        });

        // preparePhotoForUpload wraps work in withTimeout(8s); advance so the outer timeout is not needed.
        await vi.advanceTimersByTimeAsync(0);
        const result = await resultPromise;

        expect(result.file).toBe(original);
        expect(result.downscaled).toBe(false);
        expect(result.reason).toMatch(/failed|timeout|image-load/i);
    });
});
