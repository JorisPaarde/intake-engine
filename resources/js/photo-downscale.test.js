/** @vitest-environment happy-dom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    isLikelyImageFile,
    photoPrepEventMatchesScope,
    photoPrepEventScope,
    registerClientPhotoDownscale,
} from './photo-downscale.js';

describe('photo-downscale prep event scoping', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        registerClientPhotoDownscale();
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    function mountInstallerForm(inputId) {
        const form = document.createElement('form');
        form.setAttribute('data-client-downscale', '1');
        form.setAttribute('data-upload-max-bytes', String(1024));
        form.setAttribute('data-upload-too-large', 'Deze foto is te groot.');
        const input = document.createElement('input');
        input.type = 'file';
        input.id = inputId;
        input.multiple = true;
        form.appendChild(input);
        document.body.appendChild(form);

        return { form, input };
    }

    it('recognises images by extension when type is empty', () => {
        expect(isLikelyImageFile({ type: '', name: 'meterkast.JPG' })).toBe(true);
        expect(isLikelyImageFile({ type: '', name: 'notes.txt' })).toBe(false);
        expect(isLikelyImageFile({ type: 'image/png', name: 'x' })).toBe(true);
    });

    it('scopes prep events to the originating input id', () => {
        expect(photoPrepEventMatchesScope(
            { inputId: 'subject-1-photo', composite: 'installer' },
            { inputId: 'subject-1-photo', composite: 'installer' },
        )).toBe(true);
        expect(photoPrepEventMatchesScope(
            { inputId: 'subject-1-photo', composite: 'installer' },
            { inputId: 'subject-2-photo', composite: 'installer' },
        )).toBe(false);
    });

    it('dispatches inputId + disables only the matching form during prep-failed', async () => {
        const a = mountInstallerForm('subject-1-photo');
        const b = mountInstallerForm('subject-2-photo');

        /** @type {Record<string, { busy: boolean, error: string }>} */
        const state = {
            'subject-1-photo': { busy: false, error: '' },
            'subject-2-photo': { busy: false, error: '' },
        };

        const onStart = (event) => {
            const id = event.detail?.inputId;
            if (! id || ! state[id]) {
                return;
            }
            state[id].busy = true;
            state[id].error = '';
        };
        const onFailed = (event) => {
            const id = event.detail?.inputId;
            if (! id || ! state[id]) {
                return;
            }
            state[id].busy = false;
            state[id].error = event.detail?.message || '';
        };

        document.addEventListener('intake:photo-prep-start', onStart);
        document.addEventListener('intake:photo-prep-failed', onFailed);

        const huge = new File([new Uint8Array(2048)], 'huge.jpg', { type: 'image/jpeg' });
        Object.defineProperty(a.input, 'files', {
            configurable: true,
            value: [huge],
        });

        a.input.dispatchEvent(new Event('change', { bubbles: true }));
        await vi.waitFor(() => expect(state['subject-1-photo'].error).not.toBe(''));

        expect(photoPrepEventScope(a.input)).toEqual({
            composite: 'installer',
            inputId: 'subject-1-photo',
        });
        expect(state['subject-1-photo'].busy).toBe(false);
        expect(state['subject-1-photo'].error).toContain('te groot');
        expect(state['subject-2-photo'].busy).toBe(false);
        expect(state['subject-2-photo'].error).toBe('');

        // Submit would bind :disabled="prepBusy" — only form A ever became busy.
        expect(b.input.id).toBe('subject-2-photo');
    });

    it('marks skip-only failures with skipped=true', async () => {
        const { input } = mountInstallerForm('subject-9-photo');
        /** @type {CustomEvent|null} */
        let failed = null;
        document.addEventListener('intake:photo-prep-failed', (event) => {
            failed = event;
        });

        const txt = new File([new Uint8Array(20)], 'readme.txt', { type: 'text/plain' });
        Object.defineProperty(input, 'files', {
            configurable: true,
            value: [txt],
        });
        input.dispatchEvent(new Event('change', { bubbles: true }));
        await vi.waitFor(() => expect(failed).not.toBeNull());

        expect(failed?.detail?.skipped).toBe(true);
        expect(failed?.detail?.inputId).toBe('subject-9-photo');
        expect(failed?.detail?.message).toContain('geen foto');
    });
});
