import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    HARD_MAX_BYTES,
    HARD_MAX_MEGAPIXELS,
    JPEG_QUALITY,
    MAX_LONG_EDGE,
    TOO_LARGE_MESSAGE,
    aspectRatiosMatch,
    computeTargetSize,
    decodeWithCreateImageBitmap,
    exceedsHardByteLimit,
    exceedsHardMegapixelLimit,
    mustDownscaleOrFail,
    orientationSwapsAxes,
    preparePhotoForUpload,
    readImageSizeFromBytes,
    shouldDownscale,
    withTimeout,
} from './photo-prepare.js';
import {
    shouldCancelPollWhileUploadInFlight,
    shouldResumePollAfterUpload,
    selectReplayTargets,
} from './livewire-resilience.js';

/**
 * Stub OffscreenCanvas so preparePhotoForUpload can draw without DOM
 * (createCanvas is no longer injectable — review #11).
 *
 * @param {{ drawImage?: (...args: unknown[]) => void }} [overrides]
 */
function stubOffscreenCanvas(overrides = {}) {
    class FakeOffscreenCanvas {
        /**
         * @param {number} width
         * @param {number} height
         */
        constructor(width, height) {
            this.width = width;
            this.height = height;
        }

        getContext() {
            return {
                fillStyle: '',
                fillRect() {},
                drawImage: overrides.drawImage || (() => {}),
            };
        }
    }

    vi.stubGlobal('OffscreenCanvas', FakeOffscreenCanvas);
}

describe('photo-prepare hard limits + fail-closed (intake 82)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
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

    it('requires downscale for 2.2 MB phone JPEGs', () => {
        expect(shouldDownscale({ size: 2_200_000, type: 'image/jpeg' }, 3024, 4032)).toBe(true);
        expect(mustDownscaleOrFail({ size: 2_200_000, type: 'image/jpeg' })).toBe(true);
        expect(mustDownscaleOrFail({ size: 400_000, type: 'image/jpeg' })).toBe(false);
    });

    it('accepts 12 MP / 5 MB under hard limits and rejects above', () => {
        expect(HARD_MAX_BYTES).toBe(15 * 1024 * 1024);
        expect(HARD_MAX_MEGAPIXELS).toBe(24);
        expect(TOO_LARGE_MESSAGE).toBe('Deze foto is te groot. Probeer een andere foto of maak een nieuwe.');
        expect(exceedsHardByteLimit(5 * 1024 * 1024)).toBe(false);
        expect(exceedsHardMegapixelLimit(3024, 4032)).toBe(false);
        expect(exceedsHardByteLimit(15 * 1024 * 1024 + 1)).toBe(true);
        expect(exceedsHardMegapixelLimit(20000, 20000)).toBe(true);
    });

    it('withTimeout rejects after the deadline (fake timers)', async () => {
        const pending = withTimeout(new Promise(() => {}), 20_000, 'downscale-timeout');
        const assertion = expect(pending).rejects.toThrow('downscale-timeout');
        await vi.advanceTimersByTimeAsync(20_000);
        await assertion;
    });

    it('fails closed on large files when decode deps fail (no hanging original upload)', async () => {
        const original = new File([new Uint8Array(2_200_000)], 'meterkast.jpg', { type: 'image/jpeg' });
        Object.defineProperty(original, 'size', { value: 2_200_000 });

        const resultPromise = preparePhotoForUpload(original, {
            createBitmap: async () => {
                throw new Error('decode-failed');
            },
            loadImage: async () => {
                throw new Error('image-load-failed');
            },
        });

        await vi.advanceTimersByTimeAsync(0);
        const result = await resultPromise;

        expect(result.failed).toBe(true);
        expect(result.downscaled).toBe(false);
        expect(result.reason).toMatch(/failed|timeout|image-load/i);
    });

    it('maps follow-up wire:model to followUpPhotoClientOriginals', async () => {
        const { wireModelUploadTargets } = await import('./photo-prepare.js');
        expect(wireModelUploadTargets('photoFiles.fusebox_photo')).toEqual({
            filesPrefix: 'photoFiles.',
            originalsProperty: 'photoClientOriginals',
            composite: 'fusebox_photo',
        });
        expect(wireModelUploadTargets('followUpPhotoFiles.42')).toEqual({
            filesPrefix: 'followUpPhotoFiles.',
            originalsProperty: 'followUpPhotoClientOriginals',
            composite: '42',
        });
        expect(wireModelUploadTargets('followUpPhotoFiles.100')).toEqual({
            filesPrefix: 'followUpPhotoFiles.',
            originalsProperty: 'followUpPhotoClientOriginals',
            composite: '100',
        });
        expect(wireModelUploadTargets('other.thing')).toBeNull();
    });

    it('parameterizes originals property for both intake and follow-up paths', async () => {
        const { wireModelUploadTargets } = await import('./photo-prepare.js');
        const intake = wireModelUploadTargets('photoFiles.around_house');
        const followUp = wireModelUploadTargets('followUpPhotoFiles.7');

        expect(intake?.originalsProperty).toBe('photoClientOriginals');
        expect(followUp?.originalsProperty).toBe('followUpPhotoClientOriginals');
        expect(intake?.originalsProperty).not.toBe(followUp?.originalsProperty);
    });
});

describe('image header size reader', () => {
    it('reads PNG IHDR width/height', () => {
        const bytes = new Uint8Array([
            0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A,
            0x00, 0x00, 0x00, 0x0D, 0x49, 0x48, 0x44, 0x52,
            0x00, 0x00, 0x0F, 0xC0, // 4032
            0x00, 0x00, 0x0B, 0xD0, // 3024
            0x08, 0x02, 0x00, 0x00, 0x00,
        ]);
        expect(readImageSizeFromBytes(bytes)).toEqual({
            width: 4032,
            height: 3024,
            orientation: 1,
        });
    });

    it('reads JPEG SOF0 and swaps axes for EXIF orientation 6', () => {
        // Minimal JPEG: SOI + APP1(Exif orient 6) + SOF0 3024×4032 + EOI-ish padding
        const sof = [
            0xFF, 0xC0, 0x00, 0x0B, 0x08,
            0x0B, 0xD0, // height 3024
            0x0F, 0xC0, // width 4032
            0x03, 0x01, 0x22, 0x00,
        ];
        // APP1 with Exif orientation 6 (MM endian)
        const app1Body = [
            0x45, 0x78, 0x69, 0x66, 0x00, 0x00, // Exif\0\0
            0x4D, 0x4D, 0x00, 0x2A, // MM + magic
            0x00, 0x00, 0x00, 0x08, // IFD0 offset
            0x00, 0x01, // 1 entry
            0x01, 0x12, 0x00, 0x03, 0x00, 0x00, 0x00, 0x01, 0x00, 0x06, 0x00, 0x00, // tag 0x0112 = 6
        ];
        const app1 = [0xFF, 0xE1, (app1Body.length + 2) >> 8, (app1Body.length + 2) & 0xFF, ...app1Body];
        const bytes = new Uint8Array([0xFF, 0xD8, ...app1, ...sof]);
        const size = readImageSizeFromBytes(bytes);
        expect(orientationSwapsAxes(6)).toBe(true);
        expect(size).toEqual({ width: 3024, height: 4032, orientation: 6 });
    });

    it('reads WebP VP8X canvas size', () => {
        // RIFF + WEBP + VP8X with width-1=1919, height-1=1079 → 1920×1080
        const bytes = new Uint8Array([
            0x52, 0x49, 0x46, 0x46, 0x2A, 0x00, 0x00, 0x00,
            0x57, 0x45, 0x42, 0x50,
            0x56, 0x50, 0x38, 0x58, 0x0A, 0x00, 0x00, 0x00,
            0x00, 0x00, 0x00, 0x00,
            0x7F, 0x07, 0x00, // 1919 LE 24-bit
            0x37, 0x04, 0x00, // 1079 LE 24-bit
        ]);
        expect(readImageSizeFromBytes(bytes)).toEqual({
            width: 1920,
            height: 1080,
            orientation: 1,
        });
    });
});

describe('preparePhotoForUpload aspect ratio (demotest 8 okt)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        stubOffscreenCanvas();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    /**
     * Real browser behaviour: resizeWidth scales width only; resizeHeight scales height only.
     *
     * @param {number} srcW
     * @param {number} srcH
     * @param {{ width?: number, height?: number, longEdgeOnly?: boolean }} target
     */
    function browserLikeBitmap(srcW, srcH, target) {
        const tw = Math.round(Number(target.width) || 0);
        const th = Math.round(Number(target.height) || 0);
        let width = srcW;
        let height = srcH;

        if (target.longEdgeOnly) {
            const longEdge = Math.max(tw, th);
            if (th > tw && tw > 0) {
                // resizeHeight only
                const scale = longEdge / srcH;
                width = Math.round(srcW * scale);
                height = longEdge;
            } else if (longEdge > 0) {
                // resizeWidth only
                const scale = longEdge / srcW;
                width = longEdge;
                height = Math.round(srcH * scale);
            }
        } else if (tw > 0 && th > 0) {
            width = tw;
            height = th;
        }

        return {
            bitmap: { width, height, close() {} },
            originalWidth: width,
            originalHeight: height,
        };
    }

    /**
     * @param {number} srcW
     * @param {number} srcH
     * @param {string} name
     */
    async function assertDownscaleKeepsRatio(srcW, srcH, name) {
        const original = new File([new Uint8Array(2_200_000)], name, { type: 'image/jpeg' });
        Object.defineProperty(original, 'size', { value: 2_200_000 });

        const expected = computeTargetSize(srcW, srcH);

        const resultPromise = preparePhotoForUpload(original, {
            readSize: async () => ({ width: srcW, height: srcH, orientation: 1 }),
            createBitmap: async (_file, target) => {
                expect(target.longEdgeOnly).toBe(true);
                return browserLikeBitmap(srcW, srcH, target);
            },
            toJpeg: async (canvas) => {
                const w = canvas.width;
                const h = canvas.height;
                expect(aspectRatiosMatch(srcW, srcH, w, h)).toBe(true);
                return new File([new Uint8Array(50_000)], name.replace(/\.[^.]+$/, '.jpg'), {
                    type: 'image/jpeg',
                });
            },
        });

        await vi.advanceTimersByTimeAsync(0);
        const result = await resultPromise;

        expect(result.failed).toBe(false);
        expect(result.downscaled).toBe(true);
        expect(result.originalWidth).toBe(srcW);
        expect(result.originalHeight).toBe(srcH);
        expect(expected.width / expected.height).toBeCloseTo(srcW / srcH, 2);
    }

    it('keeps 4:3 (4032×3024)', async () => {
        await assertDownscaleKeepsRatio(4032, 3024, 'landscape.jpg');
    });

    it('keeps 3:4 (3024×4032)', async () => {
        await assertDownscaleKeepsRatio(3024, 4032, 'portrait.jpg');
    });

    it('keeps 16:9', async () => {
        await assertDownscaleKeepsRatio(3840, 2160, 'wide.jpg');
    });

    it('keeps 1:1', async () => {
        await assertDownscaleKeepsRatio(3000, 3000, 'square.jpg');
    });

    it('keeps panorama 3:1', async () => {
        await assertDownscaleKeepsRatio(6000, 2000, 'pano.jpg');
    });

    it('keeps EXIF 6 upright dims (header already swapped)', async () => {
        // Header reader swaps axes for orientation 6 → upright 3024×4032.
        await assertDownscaleKeepsRatio(3024, 4032, 'phone-exif6.jpg');
    });

    it('passes longEdgeOnly decode target (single resize edge, never 2000×2000 for 4:3)', async () => {
        const original = new File([new Uint8Array(2_200_000)], 'landscape.jpg', { type: 'image/jpeg' });
        Object.defineProperty(original, 'size', { value: 2_200_000 });
        /** @type {{ width?: number, height?: number, longEdgeOnly?: boolean }|null} */
        let seenTarget = null;

        const resultPromise = preparePhotoForUpload(original, {
            readSize: async () => ({ width: 4032, height: 3024, orientation: 1 }),
            createBitmap: async (_file, target) => {
                seenTarget = {
                    width: target.width,
                    height: target.height,
                    longEdgeOnly: target.longEdgeOnly,
                };
                return {
                    bitmap: {
                        width: 2000,
                        height: 1500,
                        close() {},
                    },
                    originalWidth: 2000,
                    originalHeight: 1500,
                };
            },
            toJpeg: async () => new File(
                [new Uint8Array(40_000)],
                'landscape.jpg',
                { type: 'image/jpeg' },
            ),
        });

        await vi.advanceTimersByTimeAsync(0);
        const result = await resultPromise;

        expect(result.failed).toBe(false);
        expect(seenTarget?.longEdgeOnly).toBe(true);
        expect(seenTarget?.width).toBe(2000);
        expect(seenTarget?.height).toBe(1500);
        expect(aspectRatiosMatch(4032, 3024, 2000, 1500)).toBe(true);
        expect(aspectRatiosMatch(4032, 3024, 2000, 2000)).toBe(false);
    });

    it('fails closed when bitmap aspect does not match header (wrong-aspect)', async () => {
        const original = new File([new Uint8Array(2_200_000)], 'stretched.jpg', { type: 'image/jpeg' });
        Object.defineProperty(original, 'size', { value: 2_200_000 });

        const resultPromise = preparePhotoForUpload(original, {
            readSize: async () => ({ width: 4032, height: 3024, orientation: 1 }),
            createBitmap: async () => ({
                // Buggy browser stretched to a square.
                bitmap: {
                    width: 2000,
                    height: 2000,
                    close() {},
                },
                originalWidth: 2000,
                originalHeight: 2000,
            }),
        });

        await vi.advanceTimersByTimeAsync(0);
        const result = await resultPromise;

        expect(result.failed).toBe(true);
        expect(result.downscaled).toBe(false);
        expect(result.reason).toBe('aspect-mismatch');
        expect(result.originalWidth).toBe(4032);
        expect(result.originalHeight).toBe(3024);
    });

    it('keeps tall panorama 1500×4500 via resizeHeight', async () => {
        await assertDownscaleKeepsRatio(1500, 4500, 'tall-pano.jpg');
    });
});

describe('decodeWithCreateImageBitmap longEdgeOnly axes', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('uses resizeHeight for portrait 3024×4032', async () => {
        /** @type {Record<string, number>|null} */
        let seen = null;
        vi.stubGlobal('createImageBitmap', async (_file, options) => {
            seen = options;
            return { width: 1500, height: 2000, close() {} };
        });

        const file = new File([new Uint8Array(100)], 'p.jpg', { type: 'image/jpeg' });
        await decodeWithCreateImageBitmap(file, { width: 1500, height: 2000, longEdgeOnly: true });

        expect(seen?.resizeHeight).toBe(2000);
        expect(seen?.resizeWidth).toBeUndefined();
    });

    it('uses resizeHeight for 1500×4500', async () => {
        /** @type {Record<string, number>|null} */
        let seen = null;
        vi.stubGlobal('createImageBitmap', async (_file, options) => {
            seen = options;
            return { width: 667, height: 2000, close() {} };
        });

        const file = new File([new Uint8Array(100)], 'tall.jpg', { type: 'image/jpeg' });
        await decodeWithCreateImageBitmap(file, { width: 667, height: 2000, longEdgeOnly: true });

        expect(seen?.resizeHeight).toBe(2000);
        expect(seen?.resizeWidth).toBeUndefined();
    });

    it('keeps resizeWidth when orientation is unknown (height 0)', async () => {
        /** @type {Record<string, number>|null} */
        let seen = null;
        vi.stubGlobal('createImageBitmap', async (_file, options) => {
            seen = options;
            return { width: 2000, height: 1500, close() {} };
        });

        const file = new File([new Uint8Array(100)], 'u.jpg', { type: 'image/jpeg' });
        await decodeWithCreateImageBitmap(file, { width: MAX_LONG_EDGE, height: 0, longEdgeOnly: true });

        expect(seen?.resizeWidth).toBe(MAX_LONG_EDGE);
        expect(seen?.resizeHeight).toBeUndefined();
    });
});

describe('livewire-resilience poll resume after upload (intake 82)', () => {
    it('cancels poll only while upload is in flight', () => {
        expect(shouldCancelPollWhileUploadInFlight(true, 'poll')).toBe(true);
        expect(shouldCancelPollWhileUploadInFlight(false, 'poll')).toBe(false);
        expect(shouldCancelPollWhileUploadInFlight(true, null)).toBe(false);
    });

    it('resumes poll after upload finishes', () => {
        expect(shouldResumePollAfterUpload(true)).toBe(false);
        expect(shouldResumePollAfterUpload(false)).toBe(true);
    });

    it('replays _finishUpload on update 503 and never polls', () => {
        const targets = selectReplayTargets([
            { name: 'pollPendingAssessments', metadataType: 'poll' },
            { name: '_finishUpload', metadataType: null, params: ['photoFiles.fusebox_photo', ['livewire-tmp/a.jpg'], true, true] },
            { name: '$set', metadataType: null },
        ]);

        expect(targets).toHaveLength(1);
        expect(targets[0].name).toBe('_finishUpload');
    });
});
