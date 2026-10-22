/**
 * Centralized Sprite Sheet & Animation Configuration for Markas Agen (5 AI Agents)
 *
 * Includes:
 * - 8-Frame Walk Cycle (`/assets/office/walk/walk-agent-{1..8}.png`)
 * - 8-Frame Normalized Typing Cycle (`/assets/office/typing/agent-{1..8}-typing.png`, 2048x1024, 512x512 cells)
 * - Workstation Orientation & Keyboard/Hand Calibration Metadata
 */

export const OFFICE_ASSET_VERSION = '20261022_v9';
export const OFFICE_BG_URL = `/assets/office/office-bg.png?v=${OFFICE_ASSET_VERSION}`;
export const OFFICE_CHAIRS_FG_URL = `/assets/office/office-chairs-fg.png?v=${OFFICE_ASSET_VERSION}`;

export const AnimationState = Object.freeze({
    IDLE: 'idle',
    STAND_UP: 'stand_up',
    TURNING: 'turning',
    WALKING: 'walking',
    DESK_APPROACH: 'desk_approach',
    SITTING_DOWN: 'sitting_down',
    WORKING: 'working',
    ANALYZING: 'analyzing',
    READING_PAUSE: 'reading_pause',
    COFFEE: 'coffee',
    SERVER: 'server',
    DONE: 'done',
    OFFLINE: 'offline',
});

// 8-Frame Grid (4 columns x 2 rows = 400% x 200%)
// Playback order: 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8 -> 1
export const WALK_FRAME_POSITIONS = Object.freeze([
    '0% 0%',        // Frame 1
    '33.333% 0%',   // Frame 2
    '66.667% 0%',   // Frame 3
    '100% 0%',      // Frame 4
    '0% 100%',      // Frame 5
    '33.333% 100%', // Frame 6
    '66.667% 100%', // Frame 7
    '100% 100%',    // Frame 8
]);

// 8-Frame Typing Cycle Grid (4 columns x 2 rows = 400% x 200%)
// Playback order: Frame 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8 -> 1
export const TYPING_FRAME_POSITIONS = Object.freeze([
    '0% 0%',        // Frame 1: Left hand keystroke press, right hand lifted
    '33.333% 0%',   // Frame 2: Left hand rebound, right hand descending
    '66.667% 0%',   // Frame 3: Left hand lifted, right hand keystroke press + screen focus
    '100% 0%',      // Frame 4: Mid-keystroke crossover
    '0% 100%',      // Frame 5: Left hand inner-key press, right hand lifted
    '33.333% 100%', // Frame 6: Right hand inner-key press + reading scan
    '66.667% 100%', // Frame 7: Left hand outer reach, right hand full keystroke press
    '100% 100%',    // Frame 8: Smooth return transition to Frame 1
]);

// Legacy alias kept for compatibility
export const WORK_TYPING_FRAMES = TYPING_FRAME_POSITIONS;

export const WORK_SEATED_POSES = Object.freeze({
    SITTING_IDLE_34: '33.333% 0%',    // Frame 2: Neutral balanced hands resting over keyboard
    SITTING_GLANCE: '100% 0%',        // Frame 4: Subtle screen reading posture
    SITTING_FRONT: '33.333% 0%',      // Frame 2: Seated neutral posture
    SIT_STAND_TRANSITION: '100% 100%',// Frame 8: Transition posture
});

// Standing Pose Sheet Grid (4 columns x 2 rows = 400% x 200%) in /assets/office/agent-{1..8}.png
export const POSE_FRAME_POSITIONS = Object.freeze({
    IDLE_FRONT: '0% 0%',
    IDLE_THREE_QUARTER: '33.333% 0%',
    WAVE_DONE: '0% 100%',
    COFFEE_CUP: '33.333% 100%',
});

/**
 * Workstation Orientation & Typing Sprite Configuration for the 5 Main Agents
 */
export const workstationConfig = Object.freeze({
    1: {
        slot: 1,
        characterName: 'Robot Mint',
        direction: 'toward-monitor',
        nativeFacing: 'left',
        typingSprite: `/assets/office/typing/agent-1-typing.png?v=${OFFICE_ASSET_VERSION}`,
    },
    2: {
        slot: 2,
        characterName: 'Kucing Berkacamata',
        direction: 'toward-monitor',
        nativeFacing: 'right',
        typingSprite: `/assets/office/typing/agent-2-typing.png?v=${OFFICE_ASSET_VERSION}`,
    },
    3: {
        slot: 3,
        characterName: 'Girl Headset Pink',
        direction: 'toward-monitor',
        nativeFacing: 'left',
        typingSprite: `/assets/office/typing/agent-3-typing.png?v=${OFFICE_ASSET_VERSION}`,
    },
    4: {
        slot: 4,
        characterName: 'Bear Hoodie Mint',
        direction: 'back-three-quarter',
        nativeFacing: 'left',
        typingSprite: `/assets/office/typing/agent-4-typing.png?v=${OFFICE_ASSET_VERSION}`,
    },
    5: {
        slot: 5,
        characterName: 'Robot Peach',
        direction: 'toward-monitor',
        nativeFacing: 'left',
        typingSprite: `/assets/office/typing/agent-5-typing.png?v=${OFFICE_ASSET_VERSION}`,
    },
});

export const AGENT_ASSETS = Object.freeze({
    1: {
        id: 1,
        name: 'Mint Bot',
        role: 'Auditor Statistik',
        poses: `/assets/office/agent-1.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-1.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-1-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-1-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'left',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 31.28, y: 65.31 },
        handRightPct: { x: 43.39, y: 67.83 },
        handAnchorPct: { x: 37.74, y: 66.99 },
        seatAnchorPct: { x: 57.37, y: 73.71 },
    },
    2: {
        id: 2,
        name: 'Kucing Kacamata',
        role: 'Pemeriksa Tabel',
        poses: `/assets/office/agent-2.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-2.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-2-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-2-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'right',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 60.03, y: 67.83 },
        handRightPct: { x: 73.86, y: 65.31 },
        handAnchorPct: { x: 67.57, y: 66.99 },
        seatAnchorPct: { x: 39.92, y: 73.71 },
    },
    3: {
        id: 3,
        name: 'Gadis Coral',
        role: 'Analis Narasi',
        poses: `/assets/office/agent-3.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-3.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-3-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-3-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'left',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 30.25, y: 61.11 },
        handRightPct: { x: 42.19, y: 62.79 },
        handAnchorPct: { x: 37.66, y: 63.63 },
        seatAnchorPct: { x: 56.51, y: 72.03 },
    },
    4: {
        id: 4,
        name: 'Beruang Mint',
        role: 'Validator Angka',
        poses: `/assets/office/agent-4.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-4.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-4-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-4-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'left',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 26.08, y: 57.75 },
        handRightPct: { x: 33.27, y: 61.95 },
        handAnchorPct: { x: 28.48, y: 60.27 },
        seatAnchorPct: { x: 58.42, y: 77.07 },
    },
    5: {
        id: 5,
        name: 'Robot Dasi Peach',
        role: 'Inspektur Layout',
        poses: `/assets/office/agent-5.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-5.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-5-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-5-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'left',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 31.01, y: 64.47 },
        handRightPct: { x: 43.39, y: 68.67 },
        handAnchorPct: { x: 37.37, y: 66.99 },
        seatAnchorPct: { x: 57.68, y: 73.71 },
    },
    6: {
        id: 6,
        name: 'Visor Bot Roda',
        role: 'Pemindai Cepat',
        poses: `/assets/office/agent-6.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-6.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-6-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-6-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'right',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 60.0, y: 66.0 },
        handRightPct: { x: 72.0, y: 66.0 },
        handAnchorPct: { x: 66.0, y: 66.0 },
        seatAnchorPct: { x: 42.0, y: 74.0 },
    },
    7: {
        id: 7,
        name: 'Hover Bot Spektral',
        role: 'Penguji Konsistensi',
        poses: `/assets/office/agent-7.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-7.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-7-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-7-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'right',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 60.0, y: 66.0 },
        handRightPct: { x: 72.0, y: 66.0 },
        handAnchorPct: { x: 66.0, y: 66.0 },
        seatAnchorPct: { x: 42.0, y: 74.0 },
    },
    8: {
        id: 8,
        name: 'Audio Bot Krem',
        role: 'Sinkronisasi Data',
        poses: `/assets/office/agent-8.png?v=${OFFICE_ASSET_VERSION}`,
        walk: `/assets/office/walk/walk-agent-8.png?v=${OFFICE_ASSET_VERSION}`,
        work: `/assets/office/typing/agent-8-typing.png?v=${OFFICE_ASSET_VERSION}`,
        typing: `/assets/office/typing/agent-8-typing.png?v=${OFFICE_ASSET_VERSION}`,
        nativeTypingFacing: 'right',
        handOverlayInsetBottom: 0,
        handLeftPct: { x: 60.0, y: 66.0 },
        handRightPct: { x: 72.0, y: 66.0 },
        handAnchorPct: { x: 66.0, y: 66.0 },
        seatAnchorPct: { x: 42.0, y: 74.0 },
    },
});

const preloadedUrls = new Set();

export function preloadAgentSprites(skinIds = [1, 2, 3, 4, 5, 6, 7, 8]) {
    if (typeof window === 'undefined') return;

    for (const fgMaskUrl of [OFFICE_BG_URL, OFFICE_CHAIRS_FG_URL]) {
        if (!preloadedUrls.has(fgMaskUrl)) {
            preloadedUrls.add(fgMaskUrl);
            const fgImg = new Image();
            fgImg.src = fgMaskUrl;
        }
    }

    for (const id of skinIds) {
        const asset = AGENT_ASSETS[id];
        if (!asset) continue;
        for (const url of [asset.poses, asset.walk, asset.typing]) {
            if (url && !preloadedUrls.has(url)) {
                preloadedUrls.add(url);
                const img = new Image();
                img.src = url;
            }
        }
    }
}
