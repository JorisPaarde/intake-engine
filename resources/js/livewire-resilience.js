/**
 * Livewire upload-file + update resilience (BL-143).
 * Uses the shared gate/backoff from server-resilience.js.
 */

import {
    BUSY_MESSAGE,
    MAX_ATTEMPTS,
    RETRYING_MESSAGE,
    canAutoRetry,
    computeBackoffMs,
    createRetryGate,
    isEmptyOrInvalidUploadSuccess,
    isRetryableStatus,
    parseUploadPaths,
} from './server-resilience';

/** @type {ReturnType<typeof createRetryGate>|null} */
let sharedGate = null;

export function getRetryGate() {
    if (! sharedGate) {
        sharedGate = createRetryGate();
    }
    return sharedGate;
}

/** @type {Map<string, number>} */
const livewireAttemptByKey = new Map();

function livewireAttemptKey(componentId, name) {
    return String(componentId || 'unknown') + '::' + String(name || '');
}

function csrfToken() {
    const meta = document.head?.querySelector('meta[name="csrf-token"]');
    return meta?.getAttribute?.('content') || '';
}

function resolveComponent(componentId) {
    if (typeof Livewire === 'undefined' || typeof Livewire.find !== 'function') {
        return null;
    }
    try {
        return Livewire.find(componentId);
    } catch {
        return null;
    }
}

function isLivewireUploadUrl(url) {
    const value = String(url || '');
    return value.includes('/livewire/upload-file') || /\/upload-file(\?|$)/.test(value);
}

function dispatchRetrying(detail) {
    document.dispatchEvent(new CustomEvent('intake:upload-retrying', { detail }));
}

function dispatchRetrySucceeded(detail) {
    document.dispatchEvent(new CustomEvent('intake:upload-retry-succeeded', { detail }));
}

function dispatchFailed(detail) {
    document.dispatchEvent(new CustomEvent('intake:upload-failed', {
        detail: {
            message: BUSY_MESSAGE,
            ...detail,
        },
    }));
    document.dispatchEvent(new CustomEvent('intake:livewire-request-failed', {
        detail: {
            status: detail?.status ?? 503,
            message: BUSY_MESSAGE,
            exhausted: true,
        },
    }));
}

function headerFromXhr(xhr, name) {
    try {
        return xhr.getResponseHeader?.(name) || null;
    } catch {
        return null;
    }
}

/**
 * Pause Livewire wire:poll while the global 503/retry window is active.
 */
export function registerPollPauseWhileBusy() {
    const bind = () => {
        if (typeof Livewire === 'undefined' || typeof Livewire.interceptAction !== 'function') {
            return;
        }
        if (window.__intakePollPauseBound) {
            return;
        }
        window.__intakePollPauseBound = true;

        Livewire.interceptAction(({ action }) => {
            if (document.documentElement.dataset.intakeServerBusy !== '1') {
                return;
            }
            if (action?.metadata?.type === 'poll') {
                action.cancel();
            }
        });
    };

    document.addEventListener('livewire:init', bind);
    if (typeof Livewire !== 'undefined') {
        bind();
    }
}

/**
 * Prefer upload lifecycle calls when replaying a failed Livewire update.
 * Never auto-replay polls. Exported for Vitest (BL-143 staging intake 81).
 *
 * @param {Array<{ name?: string, metadataType?: string|null }>} replay
 * @returns {Array<{ name?: string, metadataType?: string|null }>}
 */
export function selectReplayTargets(replay) {
    const items = Array.isArray(replay) ? replay : [];
    const uploadsOnly = items.filter((item) => {
        const name = String(item.name || '');
        return name === '_startUpload'
            || name === '_finishUpload'
            || name === '_uploadErrored';
    });

    if (uploadsOnly.length > 0) {
        return uploadsOnly;
    }

    return items.filter((item) => item.metadataType !== 'poll' && item.name);
}

/**
 * Auto-retry Livewire update requests on 5xx/network with global gate + backoff.
 * While a photo upload is in flight, non-upload Livewire actions are deferred so
 * LiteSpeed/LVE cannot 503 a concurrent $set/_finishUpload pair (staging intake 81).
 */
export function registerLivewireUpdateResilience() {
    const gate = getRetryGate();

    const bind = () => {
        if (typeof Livewire === 'undefined') {
            return;
        }
        if (window.__intakeLwUpdateResilienceBound) {
            return;
        }
        window.__intakeLwUpdateResilienceBound = true;

        /** @type {Set<string>} component ids with an in-flight Livewire file upload */
        const uploadInFlightByComponent = new Set();

        const markUploadInFlight = (componentId, active) => {
            const id = String(componentId || '');
            if (! id) {
                return;
            }
            if (active) {
                uploadInFlightByComponent.add(id);
                return;
            }
            uploadInFlightByComponent.delete(id);
        };

        const trackUploadLifecycle = (component) => {
            if (! component?.$wire || typeof component.$wire.$on !== 'function') {
                return;
            }
            if (component.__intakeUploadSerializeTracked) {
                return;
            }
            component.__intakeUploadSerializeTracked = true;
            const id = component.id;
            component.$wire.$on('upload:generatedSignedUrl', () => markUploadInFlight(id, true));
            component.$wire.$on('upload:finished', () => markUploadInFlight(id, false));
            component.$wire.$on('upload:errored', () => markUploadInFlight(id, false));
            component.$wire.$on('upload:removed', () => markUploadInFlight(id, false));
        };

        if (typeof Livewire.all === 'function') {
            Livewire.all().forEach(trackUploadLifecycle);
        }
        if (typeof Livewire.hook === 'function') {
            Livewire.hook('component.init', ({ component }) => trackUploadLifecycle(component));
        }

        // Serialize: while upload-file XHR is in flight, block polls and live $set
        // so they cannot race _finishUpload on a second concurrent LiteSpeed worker
        // (staging intake 81: two POSTs same second → one 503 → silent loss).
        if (typeof Livewire.interceptAction === 'function') {
            Livewire.interceptAction(({ action }) => {
                const componentId = action?.component?.id;
                if (! componentId || ! uploadInFlightByComponent.has(String(componentId))) {
                    return;
                }
                const name = String(action?.name || '');
                const metaType = action?.metadata?.type || null;
                if (metaType === 'poll') {
                    action.cancel();
                    return;
                }
                // live=false $set never reaches here; a live $set would open a second POST.
                if (name === '$set') {
                    action.cancel();
                }
            });
        }

        const useIntercept = typeof Livewire.interceptRequest === 'function';

        const handleRetryableFailure = async ({ status, retryAfterHeader, replay, preventDefault }) => {
            if (typeof preventDefault === 'function') {
                preventDefault();
            }

            const targets = selectReplayTargets(replay);

            if (targets.length === 0) {
                return;
            }

            const primary = targets[0];
            const key = livewireAttemptKey(primary.componentId, primary.name);
            const failedAttempt = (livewireAttemptByKey.get(key) || 0) + 1;
            livewireAttemptByKey.set(key, failedAttempt);

            if (! canAutoRetry(failedAttempt)) {
                livewireAttemptByKey.delete(key);
                // Clear any stuck Livewire upload bags for photo properties.
                targets.forEach((item) => {
                    const component = resolveComponent(item.componentId);
                    if (component?.$wire && typeof component.$wire.cancelUpload === 'function' && item.uploadName) {
                        try {
                            component.$wire.cancelUpload(item.uploadName);
                        } catch {
                            // ignore
                        }
                    }
                });
                dispatchFailed({ status, scope: 'livewire', attempt: failedAttempt });
                return;
            }

            const waitMs = computeBackoffMs(failedAttempt, { retryAfterHeader });
            dispatchRetrying({
                attempt: failedAttempt,
                maxAttempts: MAX_ATTEMPTS,
                waitMs,
                scope: 'livewire',
                message: RETRYING_MESSAGE,
            });

            try {
                await gate.runExclusive(waitMs, async () => {
                    for (const item of targets) {
                        const component = resolveComponent(item.componentId);
                        if (! component?.$wire || typeof component.$wire.call !== 'function') {
                            continue;
                        }
                        // Idempotent: re-call _finishUpload with the same tmp paths.
                        await component.$wire.call(item.name, ...(item.params || []));
                    }
                });
                livewireAttemptByKey.delete(key);
                dispatchRetrySucceeded({ attempt: failedAttempt, scope: 'livewire' });
            } catch {
                // A nested failure will re-enter this handler with an incremented attempt.
            }
        };

        if (useIntercept) {
            Livewire.interceptRequest(({ request, onError, onFailure, onSuccess }) => {
                onSuccess(() => {
                    // Reset attempt counters for successful component traffic.
                    Array.from(request.messages || []).forEach((message) => {
                        Array.from(message.actions || []).forEach((action) => {
                            livewireAttemptByKey.delete(livewireAttemptKey(action.component?.id, action.name));
                        });
                    });
                });

                const collectReplay = () => {
                    const replay = [];
                    Array.from(request.messages || []).forEach((message) => {
                        Array.from(message.actions || []).forEach((action) => {
                            replay.push({
                                componentId: action.component?.id,
                                name: action.name,
                                params: Array.isArray(action.params) ? action.params : [],
                                metadataType: action.metadata?.type || null,
                                uploadName: typeof action.params?.[0] === 'string' ? action.params[0] : null,
                            });
                        });
                    });
                    return replay;
                };

                onError(({ response, preventDefault }) => {
                    const status = response?.status ?? 0;
                    if (! isRetryableStatus(status)) {
                        return;
                    }
                    const retryAfterHeader = typeof response?.headers?.get === 'function'
                        ? response.headers.get('Retry-After')
                        : null;
                    handleRetryableFailure({
                        status,
                        retryAfterHeader,
                        replay: collectReplay(),
                        preventDefault,
                    });
                });

                onFailure(({ error: err }) => {
                    handleRetryableFailure({
                        status: 0,
                        retryAfterHeader: null,
                        replay: collectReplay(),
                        preventDefault: () => {},
                    });
                    void err;
                });
            });
            return;
        }

        // Legacy Livewire.hook('request') fallback (also used by customer layout).
        if (typeof Livewire.hook === 'function') {
            Livewire.hook('request', ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    if (! isRetryableStatus(status)) {
                        if (typeof preventDefault === 'function') {
                            preventDefault();
                        }
                        document.dispatchEvent(new CustomEvent('intake:livewire-request-failed', {
                            detail: {
                                status: typeof status === 'number' ? status : 0,
                                message: BUSY_MESSAGE,
                            },
                        }));
                        return;
                    }
                    handleRetryableFailure({
                        status: typeof status === 'number' ? status : 503,
                        retryAfterHeader: null,
                        replay: [],
                        preventDefault,
                    });
                });
            });
        }
    };

    document.addEventListener('livewire:init', bind);
    if (typeof Livewire !== 'undefined') {
        bind();
    }
}

/**
 * Intercept /livewire/upload-file XHR: retry empty 200 + 5xx/network with fresh signed URL.
 */
export function registerLivewireUploadResilience() {
    const gate = getRetryGate();
    /** @type {Map<string, { componentId: string, name: string, multiple: boolean, append: boolean }>} */
    const uploadMetaByUrl = new Map();
    /** @type {Map<string, number>} */
    const attemptsByName = new Map();

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
            attemptsByName.delete(String(name || ''));
        });
        component.$wire.$on('upload:finished', ({ name }) => {
            attemptsByName.delete(String(name || ''));
        });
        component.$wire.$on('upload:errored', ({ name }) => {
            attemptsByName.delete(String(name || ''));
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
            const status = request.status;
            if (String(status)[0] === '2') {
                const paths = parseUploadPaths(request.responseText);
                if (paths) {
                    resolve({ url, paths, status, retryAfter: null });
                    return;
                }
                const err = new Error('empty-upload-response');
                err.status = status;
                err.retryAfter = headerFromXhr(request, 'Retry-After');
                reject(err);
                return;
            }
            const err = new Error('upload-http-' + status);
            err.status = status;
            err.retryAfter = headerFromXhr(request, 'Retry-After');
            reject(err);
        });
        request.addEventListener('error', () => {
            const err = new Error('upload-network-error');
            err.status = 0;
            err.retryAfter = null;
            reject(err);
        });
        request.send(formData);
    });

    const clearUploadBag = async (component, name) => {
        if (component?.$wire && typeof component.$wire.cancelUpload === 'function') {
            try {
                component.$wire.cancelUpload(name);
            } catch {
                // ignore
            }
        }
        try {
            await component?.$wire?.call?.('_uploadErrored', name, null, true);
        } catch {
            // Soft-fail: bag may already be empty.
        }
    };

    const retryUploadWithFreshUrl = async (xhr) => {
        const meta = uploadMetaByUrl.get(String(xhr.__intakeUploadUrl || ''));
        const formData = xhr.__intakeFormData;
        if (! meta || ! formData) {
            return false;
        }

        const component = resolveComponent(meta.componentId);
        if (! component?.$wire || typeof component.$wire.call !== 'function') {
            return false;
        }

        const failedAttempt = (attemptsByName.get(meta.name) || 0) + 1;
        attemptsByName.set(meta.name, failedAttempt);

        const status = Number(xhr.__intakeFailStatus ?? xhr.status ?? 0);
        const retryAfterHeader = xhr.__intakeRetryAfter ?? headerFromXhr(xhr, 'Retry-After');

        if (! canAutoRetry(failedAttempt)) {
            attemptsByName.delete(meta.name);
            await clearUploadBag(component, meta.name);
            dispatchFailed({ status, scope: 'upload', name: meta.name, attempt: failedAttempt });
            return false;
        }

        const waitMs = computeBackoffMs(failedAttempt, { retryAfterHeader });
        dispatchRetrying({
            attempt: failedAttempt,
            maxAttempts: MAX_ATTEMPTS,
            waitMs,
            scope: 'upload',
            name: meta.name,
            message: RETRYING_MESSAGE,
        });

        try {
            await gate.runExclusive(waitMs, async () => {
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
            });
            attemptsByName.delete(meta.name);
            dispatchRetrySucceeded({ attempt: failedAttempt, scope: 'upload', name: meta.name });
            return true;
        } catch (error) {
            const errStatus = Number(error?.status ?? status);
            xhr.__intakeFailStatus = errStatus;
            xhr.__intakeRetryAfter = error?.retryAfter ?? null;
            if (failedAttempt < MAX_ATTEMPTS) {
                return retryUploadWithFreshUrl(xhr);
            }
            attemptsByName.delete(meta.name);
            await clearUploadBag(component, meta.name);
            dispatchFailed({ status: errStatus, scope: 'upload', name: meta.name, attempt: failedAttempt });
            return false;
        }
    };

    const proto = XMLHttpRequest.prototype;
    if (proto.__intakeUploadResiliencePatched) {
        return;
    }
    proto.__intakeUploadResiliencePatched = true;

    const originalOpen = proto.open;
    const originalSend = proto.send;
    const originalAddEventListener = proto.addEventListener;

    proto.open = function (method, url, ...rest) {
        this.__intakeUploadUrl = typeof url === 'string' ? url : String(url || '');
        this.__intakeIsLwUpload = isLivewireUploadUrl(this.__intakeUploadUrl);
        return originalOpen.call(this, method, url, ...rest);
    };

    proto.addEventListener = function (type, listener, options) {
        if (! this.__intakeIsLwUpload || this.__intakeUploadSkipGuard || typeof listener !== 'function') {
            return originalAddEventListener.call(this, type, listener, options);
        }

        if (type === 'load') {
            const xhr = this;
            const wrapped = function (event) {
                const status = xhr.status;
                const retryableEmpty = isEmptyOrInvalidUploadSuccess(status, xhr.responseText);
                const retryableHttp = isRetryableStatus(status);
                if (retryableEmpty || retryableHttp) {
                    xhr.__intakeFailStatus = status;
                    xhr.__intakeRetryAfter = headerFromXhr(xhr, 'Retry-After');
                    document.dispatchEvent(new CustomEvent('intake:upload-empty-response', {
                        detail: { url: xhr.__intakeUploadUrl, status },
                    }));
                    retryUploadWithFreshUrl(xhr);
                    return;
                }
                return listener.call(this, event);
            };
            return originalAddEventListener.call(this, type, wrapped, options);
        }

        if (type === 'error') {
            const xhr = this;
            const wrapped = function (event) {
                xhr.__intakeFailStatus = 0;
                xhr.__intakeRetryAfter = null;
                retryUploadWithFreshUrl(xhr);
                // Swallow Livewire's default error path so we own the retry lifecycle.
                void event;
            };
            return originalAddEventListener.call(this, type, wrapped, options);
        }

        return originalAddEventListener.call(this, type, listener, options);
    };

    proto.send = function (body) {
        if (this.__intakeIsLwUpload && ! this.__intakeUploadSkipGuard) {
            this.__intakeFormData = body;
        }
        return originalSend.call(this, body);
    };
}

export {
    BUSY_MESSAGE,
    MAX_ATTEMPTS,
    RETRYING_MESSAGE,
    canAutoRetry,
    computeBackoffMs,
    isRetryableStatus,
};
