/**
 * Markas Agen AI Workstation Monitor-Facing Typing & Ergonomic Calibration Configuration
 *
 * Canvas Reference Standard: 1536 x 1024 pixels (3:2 Aspect Ratio)
 * Sprite Standard: 2048 x 1024 Atlas (4 columns x 2 rows, 512 x 512 cells)
 * Playback: 8-Frame Loop via requestAnimationFrame (0 -> 7 -> 0)
 */

export const REFERENCE_CANVAS_WIDTH = 1536;
export const REFERENCE_CANVAS_HEIGHT = 1024;
export const TYPING_ASSET_VERSION = '20261022_v10';

/**
 * Calculates vector direction and angle (in degrees) from head reference to monitor center
 */
export function calculateGazeVector(monitor, head) {
    const dx = monitor.x - head.x;
    const dy = monitor.y - head.y;
    const angleDeg = (Math.atan2(dy, dx) * 180) / Math.PI;
    const distancePx = Math.hypot(dx, dy);
    return { dx, dy, angleDeg, distancePx };
}

/**
 * Convert reference 1536x1024 pixel coordinates to normalized percentage (%)
 */
export function pxToCanvasPercent(x, y) {
    return {
        left: Number(((x / REFERENCE_CANVAS_WIDTH) * 100).toFixed(2)),
        top: Number(((y / REFERENCE_CANVAS_HEIGHT) * 100).toFixed(2)),
    };
}

/**
 * Convert normalized percentage (%) to reference 1536x1024 pixel coordinates
 */
export function canvasPercentToPx(leftPct, topPct) {
    return {
        x: Math.round((leftPct / 100) * REFERENCE_CANVAS_WIDTH),
        y: Math.round((topPct / 100) * REFERENCE_CANVAS_HEIGHT),
    };
}

/**
 * Master Typing & Monitor-Facing Workstation Calibration for all 5 AI Agents
 */
export const officeTypingConfig = Object.freeze({
    1: {
        slot: 1,
        agentName: 'Robot Mint',
        role: 'Auditor Statistik',
        monitor: { x: 531, y: 273 }, // Center of CRT Monitor 1 (34.57%, 26.66%)
        headReference: { x: 585, y: 290 }, // Rear-left head center (38.09%, 28.32%)
        seatAnchor: { x: 594, y: 321, pctLeft: 38.7, pctTop: 31.4 },
        keyboard: { x: 566, y: 342, pctLeft: 36.9, pctTop: 33.4 },
        handLeft: { x: 24.78, y: 75.89 },
        handRight: { x: 42.78, y: 79.89 },
        direction: 'rear-left',
        nativeFacing: 'left',
        typingSprite: `/assets/office/typing/agent-1-typing.png?v=${TYPING_ASSET_VERSION}`,
        frameCount: 8,
        columns: 4,
        rows: 2,
        fps: 10,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        pivotX: 50,
        pivotY: 52,
        foregroundMaskOffset: { x: 0, y: 0 },
        preserveExisting: false,
    },

    2: {
        slot: 2,
        agentName: 'Cat Glasses',
        role: 'Pemeriksa Tabel',
        monitor: { x: 974, y: 242 }, // Center of CRT Monitor 2 (63.41%, 23.63%)
        headReference: { x: 895, y: 262 }, // Rear-right head center (58.27%, 25.59%)
        seatAnchor: { x: 884, y: 292, pctLeft: 57.6, pctTop: 28.6 },
        keyboard: { x: 921, y: 313, pctLeft: 60.0, pctTop: 30.6 },
        handLeft: { x: 49.17, y: 80.09 },
        handRight: { x: 67.17, y: 76.09 },
        direction: 'rear-right',
        nativeFacing: 'right',
        typingSprite: `/assets/office/typing/agent-2-typing.png?v=${TYPING_ASSET_VERSION}`,
        frameCount: 8,
        columns: 4,
        rows: 2,
        fps: 10,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        pivotX: 50,
        pivotY: 52,
        foregroundMaskOffset: { x: 0, y: 0 },
        preserveExisting: false,
    },

    3: {
        slot: 3,
        agentName: 'Girl Headset',
        role: 'Analis Narasi',
        monitor: { x: 388, y: 513 }, // Center of CRT Monitor 3 (25.26%, 50.10%)
        headReference: { x: 432, y: 532 }, // Rear-left head center (28.12%, 51.95%)
        seatAnchor: { x: 439, y: 566, pctLeft: 28.6, pctTop: 55.3 },
        keyboard: { x: 411, y: 581, pctLeft: 26.8, pctTop: 56.8 },
        handLeft: { x: 25.40, y: 76.09 },
        handRight: { x: 43.40, y: 80.09 },
        direction: 'rear-left',
        nativeFacing: 'left',
        typingSprite: `/assets/office/typing/agent-3-typing.png?v=${TYPING_ASSET_VERSION}`,
        frameCount: 8,
        columns: 4,
        rows: 2,
        fps: 10,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        pivotX: 50,
        pivotY: 52,
        foregroundMaskOffset: { x: 0, y: 0 },
        preserveExisting: false,
    },

    4: {
        slot: 4,
        agentName: 'Bear Hoodie Mint',
        role: 'Validator Angka',
        monitor: { x: 713, y: 662 }, // Center of Monitor 4 (46.42%, 64.65%)
        headReference: { x: 780, y: 690 }, // Rear-left head center (50.78%, 67.38%)
        seatAnchor: { x: 786, y: 718, pctLeft: 51.2, pctTop: 70.2 },
        keyboard: { x: 749, y: 733, pctLeft: 48.8, pctTop: 71.6 },
        handLeft: { x: 26.08, y: 57.75 },
        handRight: { x: 33.27, y: 61.95 },
        direction: 'existing-correct',
        nativeFacing: 'left',
        typingSprite: `/assets/office/typing/agent-4-typing.png?v=${TYPING_ASSET_VERSION}`,
        frameCount: 8,
        columns: 4,
        rows: 2,
        fps: 9.2,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        pivotX: 50,
        pivotY: 52,
        foregroundMaskOffset: { x: 0, y: 0 },
        preserveExisting: true, // EXPLICITLY PRESERVED AS CONFIRMED BY USER
    },

    5: {
        slot: 5,
        agentName: 'Peach Robot',
        role: 'Inspektur Layout',
        monitor: { x: 1066, y: 520 }, // Center of CRT Monitor 5 (69.40%, 50.78%)
        headReference: { x: 1125, y: 535 }, // Rear-left head center (73.24%, 52.25%)
        seatAnchor: { x: 1135, y: 567, pctLeft: 73.9, pctTop: 55.4 },
        keyboard: { x: 1105, y: 587, pctLeft: 72.0, pctTop: 57.4 },
        handLeft: { x: 28.99, y: 75.89 },
        handRight: { x: 46.99, y: 79.89 },
        direction: 'rear-left',
        nativeFacing: 'left', // Pre-flipped horizontally during preprocessing
        typingSprite: `/assets/office/typing/agent-5-typing.png?v=${TYPING_ASSET_VERSION}`,
        frameCount: 8,
        columns: 4,
        rows: 2,
        fps: 10,
        scale: 1.0,
        offsetX: 0,
        offsetY: 0,
        pivotX: 50,
        pivotY: 52,
        foregroundMaskOffset: { x: 0, y: 0 },
        preserveExisting: false,
    },
});

export default officeTypingConfig;
