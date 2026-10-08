/**
 * BL-128/BL-143/BL-148: verklein grote telefoonfoto's in de browser vóór Livewire-upload.
 * Shared by app.js (installer) and customer.js (klantwizard — no Alpine).
 * wireModelUploadTargets mapteert photoFiles → photoClientOriginals en
 * followUpPhotoFiles → followUpPhotoClientOriginals; originals worden deferred
 * ($set false) gezet zodat een trage Livewire-set de upload niet blokkeert.
 */

import {
    preparePhotoForUpload,
    TOO_LARGE_MESSAGE,
    exceedsHardByteLimit,
    exceedsHardMegapixelLimit,
    wireModelUploadTargets,
} from './photo-prepare';

const IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif']);

/**
 * @param {File} file
 * @returns {boolean}
 */
export function isLikelyImageFile(file) {
    const type = String(file?.type || '').toLowerCase();
    if (type.startsWith('image/')) {
        return true;
    }

    const name = String(file?.name || '');
    const dot = name.lastIndexOf('.');
    if (dot < 0) {
        return false;
    }

    return IMAGE_EXTENSIONS.has(name.slice(dot + 1).toLowerCase());
}

/**
 * @param {HTMLInputElement} input
 * @returns {{ composite: string, inputId: string }}
 */
export function photoPrepEventScope(input) {
    const parsed = wireModelUploadTargets(input.getAttribute('wire:model') || '');

    return {
        composite: parsed?.composite || 'installer',
        inputId: String(input.id || ''),
    };
}

/**
 * Alpine / listener filter: event belongs to this input (or is unscoped).
 *
 * @param {{ inputId?: string, composite?: string }|null|undefined} detail
 * @param {{ inputId?: string, composite?: string }} scope
 * @returns {boolean}
 */
export function photoPrepEventMatchesScope(detail, scope) {
    if (! detail) {
        return true;
    }

    const eventInputId = String(detail.inputId || '');
    const scopeInputId = String(scope.inputId || '');
    if (eventInputId !== '' && scopeInputId !== '' && eventInputId !== scopeInputId) {
        return false;
    }

    const eventComposite = String(detail.composite || '');
    const scopeComposite = String(scope.composite || '');
    if (eventComposite !== '' && scopeComposite !== '' && eventComposite !== scopeComposite) {
        // Installer native forms share composite "installer" — inputId is the real scope.
        if (eventComposite === 'installer' && scopeComposite === 'installer') {
            return eventInputId === '' || scopeInputId === '' || eventInputId === scopeInputId;
        }

        return false;
    }

    return true;
}

export function registerClientPhotoDownscale() {
    const isPrepInput = (input) => {
        if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
            return false;
        }

        return input.closest('[data-client-downscale="1"]') !== null;
    };

    const limitsFromInput = (input) => {
        const root = input.closest('[data-client-downscale="1"]');
        const maxBytes = Number(root?.getAttribute?.('data-upload-max-bytes')) || undefined;
        const maxMp = Number(root?.getAttribute?.('data-upload-max-megapixels')) || undefined;
        const message = root?.getAttribute?.('data-upload-too-large') || TOO_LARGE_MESSAGE;

        return { maxBytes, maxMp, message };
    };

    const compositeFromInput = (input) => {
        const model = input.getAttribute('wire:model') || '';

        return wireModelUploadTargets(model);
    };

    const setClientOriginals = (input, originalsProperty, composite, originals) => {
        const root = input.closest('[wire\\:id]');
        const componentId = root?.getAttribute?.('wire:id');
        if (! componentId || typeof Livewire === 'undefined' || typeof Livewire.find !== 'function') {
            return;
        }
        try {
            const component = Livewire.find(componentId);
            if (component && typeof component.set === 'function') {
                // live=false: only mutate ephemeral state — no separate Livewire update.
                // A live $set here raced _finishUpload (staging intake 81: two POSTs in
                // the same second → one LiteSpeed 503 → silent photo loss). Originals
                // ride along in getUpdates() of the next upload/_finishUpload request.
                Promise.resolve(
                    component.set(`${originalsProperty}.${composite}`, originals, false),
                ).catch(() => {});
            }
        } catch {
            // Soft-fail: server meet dan zelf de (mogelijk verkleinde) afmetingen.
        }
    };

    const dispatchPrep = (name, detail) => {
        document.dispatchEvent(new CustomEvent(name, { detail }));
    };

    const rejectTooLarge = (input, scope, message, extra = {}) => {
        dispatchPrep('intake:photo-prep-failed', {
            ...scope,
            message: message || TOO_LARGE_MESSAGE,
            ...extra,
        });
        input.value = '';
    };

    document.addEventListener('change', async (event) => {
        const input = event.target;
        if (! isPrepInput(input)) {
            return;
        }
        if (input.dataset.intakeDownscaleDone === '1') {
            input.dataset.intakeDownscaleDone = '0';
            return;
        }

        const files = Array.from(input.files || []);
        if (files.length === 0) {
            return;
        }

        const parsed = compositeFromInput(input);
        // Livewire path needs wire:model mapping; native installer forms only need
        // downscale + file replacement (demotest 8 okt taak 3).
        const isNativeForm = parsed === null && input.closest('form[data-client-downscale="1"]') !== null;
        if (! parsed && ! isNativeForm) {
            return;
        }

        event.stopImmediatePropagation();
        event.preventDefault();

        const limits = limitsFromInput(input);
        const scope = photoPrepEventScope(input);

        dispatchPrep('intake:photo-prep-start', {
            ...scope,
            count: files.length,
        });

        let prepared = [];
        let originals = [];
        let prepFailed = false;
        let failMessage = limits.message;
        /** @type {string[]} */
        const skippedMessages = [];
        try {
            for (const file of files) {
                const name = String(file.name || 'bestand');
                if (! isLikelyImageFile(file)) {
                    // Non-images are skipped with a clear notice (demotest 8 okt taak 4).
                    const skipMessage = `${name} is geen foto en is niet meegenomen.`;
                    skippedMessages.push(skipMessage);
                    dispatchPrep('intake:photo-prep-skipped', {
                        ...scope,
                        name,
                        message: skipMessage,
                        skipped: true,
                    });
                    continue;
                }

                // Hard byte ceiling before spending time on decode (no hanging upload).
                if (exceedsHardByteLimit(file.size, limits.maxBytes)) {
                    prepFailed = true;
                    failMessage = limits.message;
                    break;
                }

                const result = await preparePhotoForUpload(file);
                // Staging intake 82: never upload a full-size phone JPEG after a
                // downscale timeout — that left the UI stuck while Imagick chewed 12 MP.
                if (result?.failed) {
                    prepFailed = true;
                    failMessage = limits.message;
                    break;
                }

                if (exceedsHardByteLimit(result.file?.size, limits.maxBytes)
                    || exceedsHardMegapixelLimit(result.originalWidth, result.originalHeight, limits.maxMp)) {
                    // After resize: still over the safety net → clear Dutch message.
                    // Note: megapixel check uses original dims; after successful downscale
                    // the *file* is small — only reject when the prepared file itself is huge
                    // or when we could not downscale (original dims still apply to upload).
                    if (exceedsHardByteLimit(result.file?.size, limits.maxBytes)
                        || (! result.downscaled && exceedsHardMegapixelLimit(result.originalWidth, result.originalHeight, limits.maxMp))) {
                        prepFailed = true;
                        failMessage = limits.message;
                        break;
                    }
                }

                prepared.push(result.file);
                originals.push({
                    width: result.originalWidth,
                    height: result.originalHeight,
                });
            }
        } catch {
            prepFailed = true;
            failMessage = limits.message;
            prepared = [];
            originals = [];
        }

        if (prepFailed) {
            rejectTooLarge(input, scope, failMessage);
            return;
        }

        if (prepared.length === 0) {
            // Only non-images were selected — never pretend they were "too large".
            const skipOnlyMessage = skippedMessages.length > 0
                ? skippedMessages.join(' ')
                : failMessage;
            rejectTooLarge(input, scope, skipOnlyMessage, { skipped: true });
            return;
        }

        if (parsed) {
            setClientOriginals(input, parsed.originalsProperty, parsed.composite, originals);
        }

        try {
            const transfer = new DataTransfer();
            prepared.forEach((file) => transfer.items.add(file));
            input.files = transfer.files;
        } catch {
            // DataTransfer/file assignment failed — fail closed (no hang / no full original).
            rejectTooLarge(input, scope, failMessage);
            return;
        }

        input.dataset.intakeDownscaleDone = '1';
        dispatchPrep('intake:photo-prep-done', {
            ...scope,
            count: prepared.length,
        });
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }, true);
}
