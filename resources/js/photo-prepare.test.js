import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    HARD_MAX_BYTES,
    HARD_MAX_MEGAPIXELS,
    JPEG_QUALITY,
    MAX_LONG_EDGE,
    TOO_LARGE_MESSAGE,
    computeTargetSize,
    exceedsHardByteLimit,
    exceedsHardMegapixelLimit,
    mustDownscaleOrFail,
    preparePhotoForUpload,
    shouldDownscale,
    withTimeout,
} from './photo-prepare.js';
import {
    shouldCancelPollWhileUploadInFlight,
    shouldResumePollAfterUpload,
    selectReplayTargets,
} from './livewire-resilience.js';

describe('photo-prepare hard limits + fail-closed (intake 82)', () => {
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
