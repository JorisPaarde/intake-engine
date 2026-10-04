/**
 * Client-side photo prep before Livewire upload (BL-128 / BL-143 / staging intake 82).
 * Must never hang: every attempt resolves. Large files MUST be downscaled or the
 * prep fails closed — never upload the full original after a timeout (staging 82:
 * 2.2 MB progressive JPEG stayed in livewire-tmp after an 8s soft-fail).
 */

export const MAX_LONG_EDGE = 2000;
export const JPEG_QUALITY = 0.85;
export const SKIP_BELOW_BYTES = 900 * 1024;
/** Soft deadline for the whole prep; large phone JPEGs need more than 8s. */
export const DOWNSCALE_TIMEOUT_MS = 20_000;
/** Hard safety net (must match config/intake.php defaults). */
export const HARD_MAX_BYTES = 15 * 1024 * 1024;
export const HARD_MAX_MEGAPIXELS = 24;
export const TOO_LARGE_MESSAGE = 'Deze foto is te groot. Probeer een andere foto of maak een nieuwe.';

/**
 * Map Livewire file wire:model prefix → client-originals property (intake + follow-up).
 *
 * @param {string} model
 * @returns {{ filesPrefix: string, originalsProperty: string, composite: string }|null}
 */
export function wireModelUploadTargets(model) {
    const value = String(model || '');
    const map = [
        { filesPrefix: 'photoFiles.', originalsProperty: 'photoClientOriginals' },
        { filesPrefix: 'followUpPhotoFiles.', originalsProperty: 'followUpPhotoClientOriginals' },
    ];
    for (const entry of map) {
        if (value.startsWith(entry.filesPrefix)) {
            return {
                filesPrefix: entry.filesPrefix,
                originalsProperty: entry.originalsProperty,
                composite: value.slice(entry.filesPrefix.length),
            };
        }
    }

    return null;
}

/**
 * @param {number} bytes
 * @param {number} [limit]
 * @returns {boolean}
 */
export function exceedsHardByteLimit(bytes, limit = HARD_MAX_BYTES) {
    return typeof bytes === 'number' && bytes > limit;
}

/**
 * @param {number|null|undefined} width
 * @param {number|null|undefined} height
 * @param {number} [limit]
 * @returns {boolean}
 */
export function exceedsHardMegapixelLimit(width, height, limit = HARD_MAX_MEGAPIXELS) {
    const w = Number(width) || 0;
    const h = Number(height) || 0;
    if (w <= 0 || h <= 0) {
        return false;
    }

    return (w * h) / 1_000_000 > limit;
}

/**
 * @param {number} originalWidth
 * @param {number} originalHeight
 * @param {number} maxLongEdge
 * @returns {{ width: number, height: number, scale: number }}
 */
export function computeTargetSize(originalWidth, originalHeight, maxLongEdge = MAX_LONG_EDGE) {
    const width = Math.max(1, Math.round(originalWidth) || 1);
    const height = Math.max(1, Math.round(originalHeight) || 1);
    const longEdge = Math.max(width, height);
    const scale = longEdge > maxLongEdge ? (maxLongEdge / longEdge) : 1;

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
        scale,
    };
}

/**
 * @param {File} file
 * @param {number} originalWidth
 * @param {number} originalHeight
 * @returns {boolean}
 */
export function shouldDownscale(file, originalWidth, originalHeight) {
    if (! file || typeof file.size !== 'number') {
        return false;
    }
    const type = String(file.type || '').toLowerCase();
    if (type.includes('heic') || type.includes('heif') || ! type.startsWith('image/')) {
        return false;
    }
    const longEdge = Math.max(originalWidth || 0, originalHeight || 0);
    if (longEdge <= 0) {
        return file.size > SKIP_BELOW_BYTES;
    }

    return longEdge > MAX_LONG_EDGE || file.size > SKIP_BELOW_BYTES;
}

/**
 * True when uploading the given file without a successful downscale would be unsafe
 * (staging intake 82: full 2.2 MB progressive JPEG in livewire-tmp).
 *
 * @param {File} file
 * @returns {boolean}
 */
export function mustDownscaleOrFail(file) {
    if (! file || typeof file.size !== 'number') {
        return false;
    }
    const type = String(file.type || '').toLowerCase();
    if (type.includes('heic') || type.includes('heif') || ! type.startsWith('image/')) {
        return false;
    }

    return file.size > SKIP_BELOW_BYTES;
}

/**
 * @template T
 * @param {Promise<T>} promise
 * @param {number} ms
 * @param {string} [label]
 * @returns {Promise<T>}
 */
export function withTimeout(promise, ms, label = 'timeout') {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(label)), Math.max(1, ms));
        promise.then(
            (value) => {
                clearTimeout(timer);
                resolve(value);
            },
            (error) => {
                clearTimeout(timer);
                reject(error);
            },
        );
    });
}

/**
 * @param {HTMLCanvasElement|OffscreenCanvas} canvas
 * @param {string} name
 * @param {number} [quality]
 * @returns {Promise<File|null>}
 */
export async function canvasToJpegFile(canvas, name, quality = JPEG_QUALITY) {
    const toBlob = () => new Promise((resolve) => {
        if (typeof canvas.convertToBlob === 'function') {
            canvas.convertToBlob({ type: 'image/jpeg', quality })
                .then((blob) => resolve(blob || null))
                .catch(() => resolve(null));
            return;
        }
        if (typeof canvas.toBlob === 'function') {
            canvas.toBlob((blob) => resolve(blob || null), 'image/jpeg', quality);
            return;
        }
        resolve(null);
    });

    const blob = await withTimeout(toBlob(), 5_000, 'toblob-timeout');
    if (! blob || blob.size <= 0) {
        return null;
    }
    const base = String(name || 'foto').replace(/\.[^.]+$/, '');

    return new File([blob], `${base}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
}

/**
 * Decode + resize via createImageBitmap when available (better with progressive JPEG).
 *
 * @param {File} file
 * @param {{ width: number, height: number }} target
 * @returns {Promise<{ bitmap: ImageBitmap, originalWidth: number, originalHeight: number }>}
 */
export async function decodeWithCreateImageBitmap(file, target) {
    if (typeof createImageBitmap !== 'function') {
        throw new Error('createImageBitmap-unavailable');
    }

    // Prefer resize-at-decode so progressive JPEGs never fully expand in memory.
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, {
            resizeWidth: target.width,
            resizeHeight: target.height,
            resizeQuality: 'medium',
            imageOrientation: 'from-image',
        });
    } catch {
        try {
            bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            bitmap = await createImageBitmap(file);
        }
    }

    const originalWidth = Math.max(1, bitmap.width || 0);
    const originalHeight = Math.max(1, bitmap.height || 0);

    return { bitmap, originalWidth, originalHeight };
}

/**
 * @param {File} file
 * @param {string} reason
 * @param {number|null} [originalWidth]
 * @param {number|null} [originalHeight]
 * @returns {{ file: File, originalWidth: number|null, originalHeight: number|null, downscaled: boolean, reason: string, failed: boolean }}
 */
function failClosed(file, reason, originalWidth = null, originalHeight = null) {
    return {
        file,
        originalWidth,
        originalHeight,
        downscaled: false,
        reason,
        failed: true,
    };
}

/**
 * @param {File} file
 * @param {{ loadImage?: (file: File) => Promise<CanvasImageSource & { naturalWidth?: number, width?: number, naturalHeight?: number, height?: number }>, createBitmap?: typeof decodeWithCreateImageBitmap, toJpeg?: typeof canvasToJpegFile, now?: () => number }} [deps]
 * @returns {Promise<{ file: File, originalWidth: number|null, originalHeight: number|null, downscaled: boolean, reason?: string, failed?: boolean }>}
 */
export async function preparePhotoForUpload(file, deps = {}) {
    const type = String(file?.type || '').toLowerCase();
    if (type.includes('heic') || type.includes('heif') || ! type.startsWith('image/')) {
        return { file, originalWidth: null, originalHeight: null, downscaled: false, reason: 'skip-type', failed: false };
    }

    const loadImage = deps.loadImage || defaultLoadImage;
    const createBitmap = deps.createBitmap || decodeWithCreateImageBitmap;
    const toJpeg = deps.toJpeg || canvasToJpegFile;
    const requireDownscale = mustDownscaleOrFail(file);

    const work = (async () => {
        // For large files: skip the full-resolution probe — decode directly at target size.
        // Probing a 2.2 MB progressive JPEG first caused the old 8s timeout (staging 82).
        let originalWidth = 0;
        let originalHeight = 0;

        if (! requireDownscale && typeof createImageBitmap === 'function') {
            try {
                const probe = await createImageBitmap(file);
                originalWidth = Math.max(1, probe?.width || 0);
                originalHeight = Math.max(1, probe?.height || 0);
                if (probe && typeof probe.close === 'function') {
                    probe.close();
                }
            } catch {
                // Fall through to forced target decode.
            }

            if (originalWidth > 1 && originalHeight > 1 && ! shouldDownscale(file, originalWidth, originalHeight)) {
                return {
                    file,
                    originalWidth,
                    originalHeight,
                    downscaled: false,
                    reason: 'small-enough',
                    failed: false,
                };
            }
        }

        const target = computeTargetSize(
            originalWidth > 1 ? originalWidth : MAX_LONG_EDGE,
            originalHeight > 1 ? originalHeight : MAX_LONG_EDGE,
        );

        let source = null;
        let srcW = originalWidth;
        let srcH = originalHeight;

        try {
            const decoded = await createBitmap(file, target);
            source = decoded.bitmap;
            // When createImageBitmap resized at decode, bitmap dims are the target;
            // keep max(source, target) as a best-effort original for the server.
            srcW = Math.max(decoded.originalWidth, originalWidth, target.width);
            srcH = Math.max(decoded.originalHeight, originalHeight, target.height);
            // If the browser actually returned target-sized pixels, treat those as canvas size.
            if (decoded.bitmap.width > 0 && decoded.bitmap.height > 0
                && decoded.bitmap.width <= MAX_LONG_EDGE
                && decoded.bitmap.height <= MAX_LONG_EDGE
                && (decoded.bitmap.width < decoded.originalWidth || decoded.bitmap.height < decoded.originalHeight)) {
                // Resized at decode — draw 1:1.
                srcW = decoded.originalWidth;
                srcH = decoded.originalHeight;
            }
        } catch {
            const image = await loadImage(file);
            source = image;
            srcW = Math.max(1, image.naturalWidth || image.width || 0);
            srcH = Math.max(1, image.naturalHeight || image.height || 0);
        }

        const finalTarget = computeTargetSize(
            srcW > 1 ? srcW : MAX_LONG_EDGE,
            srcH > 1 ? srcH : MAX_LONG_EDGE,
        );

        if (! requireDownscale && ! shouldDownscale(file, srcW, srcH)) {
            if (source && typeof source.close === 'function') {
                source.close();
            }
            return {
                file,
                originalWidth: srcW,
                originalHeight: srcH,
                downscaled: false,
                reason: 'small-enough',
                failed: false,
            };
        }

        const canvas = typeof OffscreenCanvas !== 'undefined'
            ? new OffscreenCanvas(finalTarget.width, finalTarget.height)
            : Object.assign(document.createElement('canvas'), {
                width: finalTarget.width,
                height: finalTarget.height,
            });

        if (! ('width' in canvas) || canvas.width !== finalTarget.width) {
            canvas.width = finalTarget.width;
            canvas.height = finalTarget.height;
        }

        const ctx = canvas.getContext('2d');
        if (! ctx) {
            if (source && typeof source.close === 'function') {
                source.close();
            }
            return requireDownscale
                ? failClosed(file, 'no-ctx', srcW || null, srcH || null)
                : { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'no-ctx', failed: false };
        }
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, finalTarget.width, finalTarget.height);
        ctx.drawImage(source, 0, 0, finalTarget.width, finalTarget.height);
        if (source && typeof source.close === 'function') {
            source.close();
        }

        const compressed = await toJpeg(canvas, file.name);
        if (! compressed || compressed.size <= 0) {
            return requireDownscale
                ? failClosed(file, 'toblob-empty', srcW || null, srcH || null)
                : { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'toblob-empty', failed: false };
        }

        // Still oversized after compress → fail closed for large originals.
        if (requireDownscale && compressed.size > file.size * 0.95 && compressed.size > SKIP_BELOW_BYTES) {
            // Accept if dimensions were reduced even when size stayed high (unlikely for JPEG).
            // Prefer a real size win; otherwise still accept the resized JPEG (long edge capped).
        }

        return {
            file: compressed,
            originalWidth: srcW > 1 ? srcW : null,
            originalHeight: srcH > 1 ? srcH : null,
            downscaled: true,
            reason: 'downscaled',
            failed: false,
        };
    })();

    try {
        return await withTimeout(work, DOWNSCALE_TIMEOUT_MS, 'downscale-timeout');
    } catch (error) {
        const reason = error instanceof Error ? error.message : 'downscale-timeout';
        if (requireDownscale) {
            return failClosed(file, reason);
        }

        return {
            file,
            originalWidth: null,
            originalHeight: null,
            downscaled: false,
            reason,
            failed: false,
        };
    }
}

/**
 * @param {File} file
 * @returns {Promise<HTMLImageElement>}
 */
function defaultLoadImage(file) {
    return new Promise((resolve, reject) => {
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
}
