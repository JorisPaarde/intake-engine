import { describe, expect, it } from 'vitest';
import { selectReplayTargets } from './livewire-resilience.js';

describe('livewire-resilience (BL-143 concurrent update 503)', () => {
    it('replays _finishUpload on update 503 and never polls', () => {
        const targets = selectReplayTargets([
            { name: 'pollPendingAssessments', metadataType: 'poll' },
            { name: '_finishUpload', metadataType: null, params: ['photoFiles.fusebox_photo', ['livewire-tmp/a.jpg'], true, true] },
            { name: '$set', metadataType: null },
        ]);

        expect(targets).toHaveLength(1);
        expect(targets[0].name).toBe('_finishUpload');
        expect(targets[0].params?.[1]).toEqual(['livewire-tmp/a.jpg']);
    });

    it('prefers upload lifecycle calls over other actions', () => {
        const targets = selectReplayTargets([
            { name: 'saveCurrentStep', metadataType: null },
            { name: '_startUpload', metadataType: null },
            { name: '_uploadErrored', metadataType: null },
        ]);

        expect(targets.map((t) => t.name)).toEqual(['_startUpload', '_uploadErrored']);
    });

    it('falls back to non-poll actions when no upload call is present', () => {
        const targets = selectReplayTargets([
            { name: 'pollPendingAssessments', metadataType: 'poll' },
            { name: 'saveCurrentStep', metadataType: null },
        ]);

        expect(targets).toHaveLength(1);
        expect(targets[0].name).toBe('saveCurrentStep');
    });
});
