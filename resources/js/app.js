import Alpine from 'alpinejs';
import { registerDemoGuide } from './demo-guide';

window.Alpine = Alpine;

registerDemoGuide(Alpine);

/**
 * Dutch HTML5 constraint messages (BL-071). Browser-native English
 * ("Please fill out this field.") is replaced when the document is nl.
 */
function registerDutchFormValidation() {
    const locale = (document.documentElement.lang || '').toLowerCase();
    if (!locale.startsWith('nl')) {
        return;
    }

    const messageFor = (el) => {
        // Native checks only — customError would otherwise stick as "Controleer dit veld."
        if (el.validity.valueMissing) {
            return 'Vul dit veld in.';
        }
        if (el.validity.typeMismatch) {
            if (el.type === 'email') {
                return 'Vul een geldig e-mailadres in.';
            }
            return 'De invoer klopt niet.';
        }
        if (el.validity.patternMismatch) {
            return 'De invoer heeft niet het juiste formaat.';
        }
        if (el.validity.tooShort) {
            return 'De invoer is te kort.';
        }
        if (el.validity.tooLong) {
            return 'De invoer is te lang.';
        }
        if (el.validity.rangeUnderflow || el.validity.rangeOverflow) {
            return 'Kies een geldige waarde.';
        }
        return 'Controleer dit veld.';
    };

    const clearCustomValidity = (el) => {
        if (el instanceof HTMLElement && 'setCustomValidity' in el) {
            el.setCustomValidity('');
        }
    };

    const recomputeCustomValidity = (el) => {
        if (!(el instanceof HTMLElement) || !('setCustomValidity' in el)) {
            return;
        }
        // Clear first so autofilled values are not blocked by a stale customError.
        el.setCustomValidity('');
        if (!el.validity.valid) {
            el.setCustomValidity(messageFor(el));
        }
    };

    document.addEventListener(
        'invalid',
        (event) => {
            recomputeCustomValidity(event.target);
        },
        true,
    );

    ['input', 'change'].forEach((eventName) => {
        document.addEventListener(
            eventName,
            (event) => {
                clearCustomValidity(event.target);
            },
            true,
        );
    });

    // Expose for address-lookup autofill / submit guards (create form).
    window.__intakeClearCustomValidity = clearCustomValidity;
    window.__intakeRecomputeCustomValidity = recomputeCustomValidity;
}

registerDutchFormValidation();

/**
 * Deep-links (#room-12, #demo-customer-task, …) must also work when the
 * target sits in, or carries, an ingeklapt <details>-blok: open the
 * ancestors and any `details[data-open-on-target]` directly in the target.
 */
function registerHashDisclosure() {
    const reveal = () => {
        const id = decodeURIComponent(window.location.hash.slice(1));
        if (!id) {
            return;
        }
        const target = document.getElementById(id);
        if (!target) {
            return;
        }
        for (let el = target.parentElement; el; el = el.parentElement) {
            if (el.tagName === 'DETAILS') {
                el.open = true;
            }
        }
        if (target.tagName === 'DETAILS') {
            target.open = true;
        }
        const inner = target.querySelector('details[data-open-on-target]');
        if (inner) {
            inner.open = true;
        }
    };

    window.addEventListener('hashchange', reveal);
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href*="#"]') : null;
        if (link && link.hash && link.pathname === window.location.pathname && link.hash === window.location.hash) {
            reveal();
        }
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reveal);
    } else {
        reveal();
    }
}

registerHashDisclosure();

/**
 * Livewire file-upload network timing (BL-116 / P2).
 * Measure start → first livewire-upload-progress at 100%, then call
 * recordNetworkUploadTiming(uploadId, ms) after the server dispatches ai-upload-stored.
 */
function registerLivewireUploadTiming() {
    /** @type {Map<string, {startedAt: number, networkMs: number|null, componentId: string|null}>} */
    const pending = new Map();

    /** @type {Map<string, number[]>} FIFO network ms per Livewire component */
    const networkMsByComponent = new Map();

    const isTimedInput = (event) => {
        const input = event.target instanceof Element ? event.target : null;
        if (!input) {
            return false;
        }
        return input.closest('[data-upload-timing="1"]') !== null;
    };

    const keyFor = (event) => {
        const input = event.target instanceof Element ? event.target : null;
        const name = input?.getAttribute?.('wire:model') || input?.getAttribute?.('name') || 'default';
        const root = input?.closest?.('[wire\\:id]');
        const id = root?.getAttribute?.('wire:id') || 'unknown';
        return id + '::' + name;
    };

    const enqueueMs = (componentId, ms) => {
        if (!componentId || ms === null) {
            return;
        }
        const queue = networkMsByComponent.get(componentId) ?? [];
        queue.push(ms);
        networkMsByComponent.set(componentId, queue);
    };

    const dequeueAnyMs = () => {
        for (const [componentId, queue] of networkMsByComponent) {
            if (!queue || queue.length === 0) {
                continue;
            }
            const ms = queue.shift();
            if (queue.length === 0) {
                networkMsByComponent.delete(componentId);
            }

            return { componentId, ms: ms ?? null };
        }

        return null;
    };

    document.addEventListener('livewire-upload-start', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        const key = keyFor(event);
        const root = event.target instanceof Element ? event.target.closest('[wire\\:id]') : null;
        pending.set(key, {
            startedAt: performance.now(),
            networkMs: null,
            componentId: root?.getAttribute?.('wire:id') || null,
        });
    });

    document.addEventListener('livewire-upload-progress', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        const key = keyFor(event);
        const entry = pending.get(key);
        if (!entry || entry.networkMs !== null) {
            return;
        }
        const detail = event.detail || {};
        const progress = typeof detail.progress === 'number'
            ? detail.progress
            : (typeof detail === 'number' ? detail : null);
        if (progress === 100) {
            entry.networkMs = Math.max(0, Math.round(performance.now() - entry.startedAt));
        }
    });

    document.addEventListener('livewire-upload-finish', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        const key = keyFor(event);
        const entry = pending.get(key);
        if (!entry) {
            return;
        }
        if (entry.networkMs === null) {
            entry.networkMs = Math.max(0, Math.round(performance.now() - entry.startedAt));
        }
        enqueueMs(entry.componentId, entry.networkMs);
        pending.delete(key);
    });

    document.addEventListener('livewire-upload-error', (event) => {
        if (!isTimedInput(event)) {
            return;
        }
        pending.delete(keyFor(event));
    });

    const bindUploadStoredListener = () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.on !== 'function') {
            return;
        }
        Livewire.on('ai-upload-stored', (payload) => {
            const uploadId = payload?.uploadId ?? payload?.[0]?.uploadId;
            if (!uploadId) {
                return;
            }
            const next = dequeueAnyMs();
            if (!next || next.ms === null) {
                return;
            }
            const component = typeof Livewire.find === 'function'
                ? Livewire.find(next.componentId)
                : null;
            if (!component || typeof component.call !== 'function') {
                return;
            }
            try {
                component.call('recordNetworkUploadTiming', uploadId, next.ms);
            } catch {
                // Soft-fail: timing is diagnostic only.
            }
        });
    };

    document.addEventListener('livewire:init', bindUploadStoredListener);
    if (typeof Livewire !== 'undefined') {
        bindUploadStoredListener();
    }
}

registerLivewireUploadTiming();

/**
 * BL-128: Livewire's upload XHR treats HTTP 200 with an empty/invalid body as success,
 * then crashes on `response.paths` and never calls finish/error — the Alpine timer then
 * falsely reports a timeout while the server may still be fine. Intercept those responses,
 * retry with a fresh signed URL (do not reuse a possibly spent signature), and finish via
 * `_finishUpload` so the UploadManager can complete normally.
 */
function registerLivewireUploadEmptyResponseGuard() {
    const MAX_RETRIES = 2;
    /** @type {Map<string, { componentId: string, name: string, multiple: boolean, append: boolean }>} */
    const uploadMetaByUrl = new Map();

    const isLivewireUploadUrl = (url) => {
        const value = String(url || '');
        return value.includes('/livewire/upload-file') || /\/upload-file(\?|$)/.test(value);
    };

    const parseUploadPaths = (xhr) => {
        const raw = typeof xhr.responseText === 'string' ? xhr.responseText : (xhr.response || '');
        if (typeof raw !== 'string' || raw.trim() === '') {
            return null;
        }
        try {
            const json = JSON.parse(raw);
            const paths = json?.paths;
            if (! Array.isArray(paths) || paths.length === 0) {
                return null;
            }
            return paths;
        } catch {
            return null;
        }
    };

    const isEmptyOrInvalidSuccess = (xhr) => {
        const status = String(xhr.status || '');
        if (status[0] !== '2') {
            return false;
        }
        return parseUploadPaths(xhr) === null;
    };

    const csrfToken = () => {
        const meta = document.head?.querySelector('meta[name="csrf-token"]');
        return meta?.getAttribute?.('content') || '';
    };

    const resolveComponent = (componentId) => {
        if (typeof Livewire === 'undefined' || typeof Livewire.find !== 'function') {
            return null;
        }
        try {
            return Livewire.find(componentId);
        } catch {
            return null;
        }
    };

    const trackSignedUrl = (component, name, url) => {
        if (! url || ! component) {
            return;
        }
        uploadMetaByUrl.set(String(url), {
            componentId: component.id,
            name: String(name || ''),
            multiple: true,
            append: true,
        });
    };

    const bindComponentTracking = (component) => {
        if (! component?.$wire || typeof component.$wire.$on !== 'function') {
            return;
        }
        if (component.__intakeUploadUrlTracked) {
            return;
        }
        component.__intakeUploadUrlTracked = true;
        component.$wire.$on('upload:generatedSignedUrl', ({ name, url }) => {
            trackSignedUrl(component, name, url);
        });
    };

    const bindAllComponents = () => {
        if (typeof Livewire === 'undefined') {
            return;
        }
        if (typeof Livewire.all === 'function') {
            Livewire.all().forEach(bindComponentTracking);
        }
        if (typeof Livewire.hook === 'function') {
            Livewire.hook('component.init', ({ component }) => bindComponentTracking(component));
        }
    };

    document.addEventListener('livewire:init', bindAllComponents);
    if (typeof Livewire !== 'undefined') {
        bindAllComponents();
    }

    const postFormData = (url, formData) => new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        request.__intakeUploadSkipGuard = true;
        request.open('post', url);
        request.setRequestHeader('Accept', 'application/json');
        const token = csrfToken();
        if (token) {
            request.setRequestHeader('X-CSRF-TOKEN', token);
        }
        request.addEventListener('load', () => {
            if (String(request.status)[0] === '2') {
                const paths = parseUploadPaths(request);
                if (paths) {
                    resolve({ url, paths });
                    return;
                }
                reject(new Error('empty-upload-response'));
                return;
            }
            reject(new Error('upload-http-' + request.status));
        });
        request.addEventListener('error', () => reject(new Error('upload-network-error')));
        request.send(formData);
    });

    const retryWithFreshUrl = async (xhr) => {
        const meta = uploadMetaByUrl.get(String(xhr.__intakeUploadUrl || ''));
        const formData = xhr.__intakeFormData;
        if (! meta || ! formData) {
            return false;
        }

        const component = resolveComponent(meta.componentId);
        if (! component?.$wire || typeof component.$wire.call !== 'function') {
            return false;
        }

        const attempt = (xhr.__intakeUploadAttempt || 0) + 1;
        if (attempt > MAX_RETRIES) {
            try {
                await component.$wire.call('_uploadErrored', meta.name, null, meta.multiple);
            } catch {
                // Soft-fail: Alpine error UI still handles livewire-upload-error if fired.
            }
            return false;
        }

        document.dispatchEvent(new CustomEvent('intake:upload-retrying', {
            detail: { attempt, name: meta.name },
        }));

        try {
            const freshUrl = await component.$wire.call('freshSignedUploadUrl');
            if (typeof freshUrl !== 'string' || freshUrl === '') {
                throw new Error('missing-fresh-url');
            }
            trackSignedUrl(component, meta.name, freshUrl);

            const result = await postFormData(freshUrl, formData);
            await component.$wire.call(
                '_finishUpload',
                meta.name,
                result.paths,
                meta.multiple,
                meta.append,
            );
            document.dispatchEvent(new CustomEvent('intake:upload-retry-succeeded', {
                detail: { attempt, name: meta.name },
            }));
            return true;
        } catch {
            if (attempt < MAX_RETRIES) {
                xhr.__intakeUploadAttempt = attempt;
                return retryWithFreshUrl(xhr);
            }
            try {
                await component.$wire.call('_uploadErrored', meta.name, null, meta.multiple);
            } catch {
                // ignore
            }
            return false;
        }
    };

    const proto = XMLHttpRequest.prototype;
    const originalOpen = proto.open;
    const originalSend = proto.send;
    const originalAddEventListener = proto.addEventListener;

    proto.open = function (method, url, ...rest) {
        this.__intakeUploadUrl = typeof url === 'string' ? url : String(url || '');
        this.__intakeIsLwUpload = isLivewireUploadUrl(this.__intakeUploadUrl);
        return originalOpen.call(this, method, url, ...rest);
    };

    proto.addEventListener = function (type, listener, options) {
        if (type === 'load' && this.__intakeIsLwUpload && ! this.__intakeUploadSkipGuard && typeof listener === 'function') {
            const xhr = this;
            const wrapped = function (event) {
                if (isEmptyOrInvalidSuccess(xhr)) {
                    document.dispatchEvent(new CustomEvent('intake:upload-empty-response', {
                        detail: { url: xhr.__intakeUploadUrl, status: xhr.status },
                    }));
                    retryWithFreshUrl(xhr);
                    return;
                }
                return listener.call(this, event);
            };
            return originalAddEventListener.call(this, type, wrapped, options);
        }
        return originalAddEventListener.call(this, type, listener, options);
    };

    proto.send = function (body) {
        if (this.__intakeIsLwUpload && ! this.__intakeUploadSkipGuard) {
            this.__intakeFormData = body;
            this.__intakeUploadAttempt = this.__intakeUploadAttempt || 0;
        }
        return originalSend.call(this, body);
    };
}

registerLivewireUploadEmptyResponseGuard();

/**
 * BL-128: verklein grote telefoonfoto's in de browser vóór Livewire-upload.
 * Bewaart originele breedte/hoogte in photoClientOriginals voor de resolutiecheck.
 * HEIC/HEIF blijft ongemoeid (canvas kan die niet betrouwbaar lezen).
 */
function registerClientPhotoDownscale() {
    const MAX_LONG_EDGE = 2048;
    const JPEG_QUALITY = 0.82;
    const SKIP_BELOW_BYTES = 900 * 1024;

    const isPrepInput = (input) => {
        if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
            return false;
        }

        return input.closest('[data-client-downscale="1"]') !== null;
    };

    const compositeFromInput = (input) => {
        const model = input.getAttribute('wire:model') || '';
        const prefix = 'photoFiles.';
        if (! model.startsWith(prefix)) {
            return null;
        }

        return model.slice(prefix.length);
    };

    const loadImage = (file) => new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('image-load-failed'));
        };
        image.src = url;
    });

    const canvasToJpegFile = (canvas, name) => new Promise((resolve) => {
        canvas.toBlob((blob) => {
            if (! blob) {
                resolve(null);
                return;
            }
            const base = String(name || 'foto').replace(/\.[^.]+$/, '');
            resolve(new File([blob], `${base}.jpg`, { type: 'image/jpeg', lastModified: Date.now() }));
        }, 'image/jpeg', JPEG_QUALITY);
    });

    const downscaleFile = async (file) => {
        const type = (file.type || '').toLowerCase();
        if (type.includes('heic') || type.includes('heif')) {
            return { file, originalWidth: null, originalHeight: null };
        }
        if (! type.startsWith('image/')) {
            return { file, originalWidth: null, originalHeight: null };
        }

        try {
            const image = await loadImage(file);
            const originalWidth = Math.max(1, image.naturalWidth || image.width || 0);
            const originalHeight = Math.max(1, image.naturalHeight || image.height || 0);
            const longEdge = Math.max(originalWidth, originalHeight);

            if (longEdge <= MAX_LONG_EDGE && file.size <= SKIP_BELOW_BYTES) {
                return { file, originalWidth, originalHeight };
            }

            const scale = longEdge > MAX_LONG_EDGE ? (MAX_LONG_EDGE / longEdge) : 1;
            const width = Math.max(1, Math.round(originalWidth * scale));
            const height = Math.max(1, Math.round(originalHeight * scale));
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (! ctx) {
                return { file, originalWidth, originalHeight };
            }
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, width, height);
            ctx.drawImage(image, 0, 0, width, height);

            const compressed = await canvasToJpegFile(canvas, file.name);
            if (! compressed || compressed.size <= 0) {
                return { file, originalWidth, originalHeight };
            }

            return { file: compressed, originalWidth, originalHeight };
        } catch {
            return { file, originalWidth: null, originalHeight: null };
        }
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

        const composite = compositeFromInput(input);
        if (! composite) {
            return;
        }

        event.stopImmediatePropagation();
        event.preventDefault();

        const prepared = [];
        const originals = [];
        for (const file of files) {
            const result = await downscaleFile(file);
            prepared.push(result.file);
            originals.push({
                width: result.originalWidth,
                height: result.originalHeight,
            });
        }

        const root = input.closest('[wire\\:id]');
        const componentId = root?.getAttribute?.('wire:id');
        if (componentId && typeof Livewire !== 'undefined' && typeof Livewire.find === 'function') {
            const component = Livewire.find(componentId);
            if (component && typeof component.set === 'function') {
                try {
                    await component.set(`photoClientOriginals.${composite}`, originals);
                } catch {
                    // Soft-fail: server meet dan zelf de (mogelijk verkleinde) afmetingen.
                }
            }
        }

        const transfer = new DataTransfer();
        prepared.forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
        input.dataset.intakeDownscaleDone = '1';
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }, true);
}

registerClientPhotoDownscale();

/**
 * Dutch Livewire request failures (e.g. LiteSpeed 503) instead of the English overlay.
 * Keeps the page/input; caller UI can offer "Opnieuw proberen".
 */
function registerLivewireDutchRequestErrors() {
    const messageForStatus = (status) => {
        if (status === 419) {
            return 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
        }
        if (status === 503 || status === 502 || status === 504) {
            return 'De server is even niet bereikbaar. Probeer het opnieuw.';
        }
        if (status >= 500) {
            return 'Er ging iets mis op de server. Probeer het opnieuw.';
        }
        if (status === 0) {
            return 'Geen verbinding. Controleer je netwerk en probeer opnieuw.';
        }
        return 'De aanvraag lukte niet. Probeer het opnieuw.';
    };

    const dispatchError = (status) => {
        const detail = {
            status: typeof status === 'number' ? status : 0,
            message: messageForStatus(typeof status === 'number' ? status : 0),
        };
        document.dispatchEvent(new CustomEvent('intake:livewire-request-failed', { detail }));
    };

    const bind = () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
            return;
        }

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (typeof preventDefault === 'function') {
                    preventDefault();
                }
                dispatchError(status);
            });
        });
    };

    document.addEventListener('livewire:init', bind);
    if (typeof Livewire !== 'undefined') {
        bind();
    }
}

registerLivewireDutchRequestErrors();

Alpine.start();
