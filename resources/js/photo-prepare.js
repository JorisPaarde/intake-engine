/**
 * Client-side photo prep before Livewire upload (BL-128 / BL-143).
 * Must never hang: every attempt resolves with either a smaller JPEG or the original file.
 */

export const MAX_LONG_EDGE = 2000;
export const JPEG_QUALITY = 0.85;
export const SKIP_BELOW_BYTES = 900 * 1024;
export const DOWNSCALE_TIMEOUT_MS = 8_000;

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

    // First decode without resize to learn intrinsic size when options unsupported.
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, {
            resizeWidth: target.width,
            resizeHeight: target.height,
            resizeQuality: 'medium',
            // Respect EXIF orientation when the browser supports it (BL-143).
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

    // If browser ignored resize options, draw to canvas at target size later.
    return { bitmap, originalWidth, originalHeight };
}

/**
 * @param {File} file
 * @param {{ loadImage?: (file: File) => Promise<CanvasImageSource & { naturalWidth?: number, width?: number, naturalHeight?: number, height?: number }>, createBitmap?: typeof decodeWithCreateImageBitmap, toJpeg?: typeof canvasToJpegFile, now?: () => number }} [deps]
 * @returns {Promise<{ file: File, originalWidth: number|null, originalHeight: number|null, downscaled: boolean, reason?: string }>}
 */
export async function preparePhotoForUpload(file, deps = {}) {
    const type = String(file?.type || '').toLowerCase();
    if (type.includes('heic') || type.includes('heif') || ! type.startsWith('image/')) {
        return { file, originalWidth: null, originalHeight: null, downscaled: false, reason: 'skip-type' };
    }

    const loadImage = deps.loadImage || defaultLoadImage;
    const createBitmap = deps.createBitmap || decodeWithCreateImageBitmap;
    const toJpeg = deps.toJpeg || canvasToJpegFile;

    const work = (async () => {
        // Prefer createImageBitmap path for progressive JPEG / memory.
        try {
            // Probe dimensions cheaply when possible via bitmap without forced size.
            let probe = null;
            if (typeof createImageBitmap === 'function') {
                try {
                    probe = await createImageBitmap(file);
                } catch {
                    probe = null;
                }
            }

            const originalWidth = Math.max(1, probe?.width || 0);
            const originalHeight = Math.max(1, probe?.height || 0);
            if (probe && typeof probe.close === 'function') {
                probe.close();
            }

            if (originalWidth > 1 && originalHeight > 1 && ! shouldDownscale(file, originalWidth, originalHeight)) {
                return { file, originalWidth, originalHeight, downscaled: false, reason: 'small-enough' };
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
                srcW = decoded.originalWidth;
                srcH = decoded.originalHeight;
            } catch {
                const image = await loadImage(file);
                source = image;
                srcW = Math.max(1, image.naturalWidth || image.width || 0);
                srcH = Math.max(1, image.naturalHeight || image.height || 0);
            }

            const finalTarget = computeTargetSize(srcW, srcH);
            if (! shouldDownscale(file, srcW, srcH)) {
                if (source && typeof source.close === 'function') {
                    source.close();
                }
                return { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'small-enough' };
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
                return { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'no-ctx' };
            }
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, finalTarget.width, finalTarget.height);
            ctx.drawImage(source, 0, 0, finalTarget.width, finalTarget.height);
            if (source && typeof source.close === 'function') {
                source.close();
            }

            const compressed = await toJpeg(canvas, file.name);
            if (! compressed || compressed.size <= 0) {
                return { file, originalWidth: srcW, originalHeight: srcH, downscaled: false, reason: 'toblob-empty' };
            }

            return {
                file: compressed,
                originalWidth: srcW,
                originalHeight: srcH,
                downscaled: true,
                reason: 'downscaled',
            };
        } catch (error) {
            return {
                file,
                originalWidth: null,
                originalHeight: null,
                downscaled: false,
                reason: error instanceof Error ? error.message : 'downscale-failed',
            };
        }
    })();

    try {
        return await withTimeout(work, DOWNSCALE_TIMEOUT_MS, 'downscale-timeout');
    } catch (error) {
        return {
            file,
            originalWidth: null,
            originalHeight: null,
            downscaled: false,
            reason: error instanceof Error ? error.message : 'downscale-timeout',
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
