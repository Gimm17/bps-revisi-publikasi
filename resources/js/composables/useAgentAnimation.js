import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import {
    AnimationState,
    WALK_FRAME_POSITIONS,
    TYPING_FRAME_POSITIONS,
    WORK_SEATED_POSES,
    POSE_FRAME_POSITIONS,
    AGENT_ASSETS,
    preloadAgentSprites,
} from '@/config/agentSprites';
import { buildOfficeRoute, WORKSTATION_CONFIG, DEFAULT_TYPING_CALIBRATION } from '@/config/officeWaypoints';

/**
 * Determine 8-way compass direction from movement vector (dx, dy)
 */
export function resolveCompassDirection(dx, dy, fallbackHorizontal = 'right') {
    const mag = Math.hypot(dx, dy);
    if (mag < 0.05) return fallbackHorizontal;

    const angleDeg = (Math.atan2(dy, dx) * 180) / Math.PI;

    if (angleDeg >= -22.5 && angleDeg < 22.5) return 'right';
    if (angleDeg >= 22.5 && angleDeg < 67.5) return 'front-right';
    if (angleDeg >= 67.5 && angleDeg < 112.5) return 'front';
    if (angleDeg >= 112.5 && angleDeg < 157.5) return 'front-left';
    if (angleDeg >= -67.5 && angleDeg < -22.5) return 'back-right';
    if (angleDeg >= -112.5 && angleDeg < -67.5) return 'back';
    if (angleDeg >= -157.5 && angleDeg < -112.5) return 'back-left';
    return 'left';
}

/**
 * Map backend/UI behaviorState string to target zone ('desk' | 'coffee' | 'server')
 * and settled AnimationState once the character arrives.
 */
export function mapBehaviorToTarget(behaviorState) {
    const raw = String(behaviorState || 'idle').toLowerCase();

    if (raw === 'offline') {
        return { zone: 'desk', settledState: AnimationState.OFFLINE };
    }
    if (raw === 'walking_to_coffee' || raw === 'coffee' || raw === 'cooldown') {
        return { zone: 'coffee', settledState: AnimationState.COFFEE };
    }
    if (
        raw === 'walking_to_server' ||
        raw === 'at_server' ||
        raw === 'server' ||
        raw === 'submitting' ||
        raw === 'testing'
    ) {
        return { zone: 'server', settledState: AnimationState.SERVER };
    }
    if (raw === 'done') {
        return { zone: 'desk', settledState: AnimationState.DONE };
    }
    if (raw === 'analyzing') {
        return { zone: 'desk', settledState: AnimationState.ANALYZING };
    }
    if (raw === 'working' || raw === 'processing' || raw === 'task_active') {
        return { zone: 'desk', settledState: AnimationState.WORKING };
    }
    return { zone: 'desk', settledState: AnimationState.IDLE };
}

export function useAgentAnimation(props, emit) {
    const slotNum = Math.min(5, Math.max(1, Number(props.slotNumber) || 1));
    const wsDefault = WORKSTATION_CONFIG[slotNum] || WORKSTATION_CONFIG[1];
    const calibDefault = DEFAULT_TYPING_CALIBRATION[slotNum] || DEFAULT_TYPING_CALIBRATION[1];

    // Current normalized canvas coordinates (%)
    const currentTop = ref(Number(props.deskPosition?.top ?? wsDefault.seatPosition.top));
    const currentLeft = ref(Number(props.deskPosition?.left ?? wsDefault.seatPosition.left));

    // Current state machine state
    const visualState = ref(AnimationState.IDLE);

    // Compass direction & horizontal facing
    const direction = ref(props.deskPosition?.facing || wsDefault.facing);
    const horizontalFacing = ref(props.deskPosition?.facing || wsDefault.facing);

    // 8-frame walk cycle state (0..7)
    const walkFrameIndex = ref(0);
    const walkFps = ref(11.5);

    // 8-frame typing cycle state (0..7 -> Frame 1..8)
    const workTypingFrameIndex = ref((slotNum - 1) % 8);
    const isReadingPause = ref(false);

    // Active waypoint queue [{ id, role, top, left, facing }]
    const waypointQueue = ref([]);
    const currentTargetZone = ref('desk');
    const desiredSettledState = ref(AnimationState.IDLE);

    // Subtle vertical stride bob (px) during walking ONLY (0.0px while seated working!)
    const dynamicOffsetY = ref(0);
    const currentSpeedUnits = ref(0);
    const fpsReadout = ref(60);

    // Internal timers & rAF state
    let rafId = null;
    let lastTimestamp = 0;
    let walkAccumulatorSec = 0;
    let typingAccumulatorSec = 0;
    let phaseTimerSec = 0;
    let currentTravelTotalDist = 0;
    let currentTravelCoveredDist = 0;

    // Staggered natural typing rhythm per slot (Typing 3.5-5.8s -> Short Reading Pause 0.45-0.9s -> Repeat)
    let rhythmBurstDurationSec = 3.6 + slotNum * 0.42;
    let rhythmPauseDurationSec = 0.48 + (slotNum % 3) * 0.16;
    let rhythmElapsedSec = (slotNum * 0.73) % 2.5;

    const BASE_WALK_SPEED = 15.8; // Canvas % per second
    const prefersReducedMotion = ref(false);

    const skinAsset = computed(() => {
        const id = Math.min(8, Math.max(1, Number(props.skinId) || 1));
        return AGENT_ASSETS[id] || AGENT_ASSETS[1];
    });

    const effectiveTypingFps = computed(() => {
        const configuredFps = Number(props.typingCalibration?.fps || calibDefault.fps || 9.5);
        if (visualState.value === AnimationState.ANALYZING && !props.calibrationMode) {
            return Math.max(5.5, configuredFps * 0.76);
        }
        return Math.min(18, Math.max(4, configuredFps));
    });

    // Determine which of the 3 sprite sheets ('walk' | 'work' | 'poses') is active
    const activeSheetType = computed(() => {
        if (props.calibrationMode) return 'work';
        const st = visualState.value;
        if (st === AnimationState.WALKING || st === AnimationState.DESK_APPROACH) {
            return 'walk';
        }
        if (
            st === AnimationState.WORKING ||
            st === AnimationState.ANALYZING ||
            st === AnimationState.READING_PAUSE ||
            st === AnimationState.IDLE ||
            st === AnimationState.OFFLINE ||
            st === AnimationState.STAND_UP ||
            st === AnimationState.SITTING_DOWN
        ) {
            return 'work';
        }
        return 'poses';
    });

    const isTypingActive = computed(() => {
        if (props.calibrationMode) return true;
        return (
            visualState.value === AnimationState.WORKING ||
            visualState.value === AnimationState.ANALYZING
        );
    });

    const activeSpriteSheetUrl = computed(() => {
        const asset = skinAsset.value;
        const type = activeSheetType.value;
        const url = type === 'walk' ? asset.walk : type === 'work' ? asset.typing || asset.work : asset.poses;
        return `url('${url}')`;
    });

    const activeBackgroundPosition = computed(() => {
        const type = activeSheetType.value;
        const st = visualState.value;

        if (type === 'walk') {
            return WALK_FRAME_POSITIONS[walkFrameIndex.value] || WALK_FRAME_POSITIONS[0];
        }

        if (type === 'work') {
            const locked = Number(props.typingCalibration?.lockedFrame || 0);
            if (props.calibrationMode && locked >= 1 && locked <= 8) {
                return TYPING_FRAME_POSITIONS[locked - 1] || TYPING_FRAME_POSITIONS[0];
            }
            if (st === AnimationState.WORKING || st === AnimationState.ANALYZING || props.calibrationMode) {
                if (isReadingPause.value && !props.calibrationMode) {
                    return WORK_SEATED_POSES.SITTING_GLANCE;
                }
                return TYPING_FRAME_POSITIONS[workTypingFrameIndex.value] || TYPING_FRAME_POSITIONS[0];
            }
            if (st === AnimationState.STAND_UP || st === AnimationState.SITTING_DOWN) {
                return WORK_SEATED_POSES.SIT_STAND_TRANSITION;
            }
            // Seated IDLE / OFFLINE at desk: hands rest calmly without typing loop
            return WORK_SEATED_POSES.SITTING_IDLE_34;
        }

        // Standing poses sheet (`COFFEE`, `SERVER`, `DONE`, `TURNING`)
        if (st === AnimationState.COFFEE) {
            return POSE_FRAME_POSITIONS.COFFEE_CUP;
        }
        if (st === AnimationState.DONE) {
            return POSE_FRAME_POSITIONS.WAVE_DONE;
        }
        return POSE_FRAME_POSITIONS.IDLE_THREE_QUARTER;
    });

    /**
     * Direction & Flipping Logic:
     * - For `walk` and `poses` sheets, native sprites face RIGHT (`+X`), so flip when `horizontalFacing === 'left'`.
     * - For `work` / `typing` sheets (`/assets/office/typing/agent-{1..5}-typing.png`), each character is
     *   ALREADY pre-oriented for its workstation (`nativeTypingFacing`: 'left' for 1, 3, 4, 5; 'right' for 2).
     *   Only flip if the workstation facing differs from the skin's `nativeTypingFacing` or if admin explicitly flips!
     */
    const shouldFlipHorizontal = computed(() => {
        if (visualState.value === AnimationState.DONE) {
            return false;
        }
        if (activeSheetType.value === 'work') {
            if (props.typingCalibration?.direction === 'flip-horizontal') {
                return true;
            }
            const nativeFacing = skinAsset.value.nativeTypingFacing || 'left';
            const deskFacing = props.deskPosition?.facing || wsDefault.facing;
            return nativeFacing !== deskFacing;
        }
        return horizontalFacing.value === 'left';
    });

    // Isometric depth scaling: characters lower on the floor (larger top %) are slightly closer to camera
    const isometricDepthScale = computed(() => {
        if (activeSheetType.value === 'work') {
            return Number(props.typingCalibration?.scale ?? 1.0);
        }
        const y = currentTop.value;
        const scale = 0.94 + ((y - 32) / 42) * 0.08;
        return Math.min(1.04, Math.max(0.93, Number(scale.toFixed(3))));
    });

    /**
     * Layer 2 Z-Index (`20 + Math.round(currentTop)`) for full character body.
     * Desk Foreground Mask is at `21 + Math.round(seatTop)`.
     * Hand/Upper Body Overlay is at `22 + Math.round(currentTop)`.
     * Chair Backrest Foreground Mask is at `23 + Math.round(seatTop)`.
     */
    const dynamicZIndex = computed(() => {
        return 20 + Math.round(currentTop.value);
    });

    const handOverlayZIndex = computed(() => {
        return 22 + Math.round(currentTop.value);
    });

    const uiOverlayZIndex = computed(() => {
        if (props.calibrationMode || props.isSelected) {
            return 85 + Math.round(currentTop.value);
        }
        return 40 + Math.round(currentTop.value);
    });

    function emitDebugSnapshot() {
        emit('path-update', {
            slot: props.slotNumber,
            currentTop: currentTop.value,
            currentLeft: currentLeft.value,
            visualState: visualState.value,
            direction: direction.value,
            horizontalFacing: horizontalFacing.value,
            walkFrame:
                activeSheetType.value === 'work'
                    ? workTypingFrameIndex.value + 1
                    : walkFrameIndex.value + 1,
            waypoints: waypointQueue.value,
        });
    }

    /**
     * Initiate or update route when behaviorState changes, without resetting an already matching route
     */
    function syncDesiredBehavior(newBehaviorState, forceImmediate = false) {
        const deskFacing = props.deskPosition?.facing || wsDefault.facing;

        if (props.calibrationMode) {
            waypointQueue.value = [];
            currentTop.value = Number(props.deskPosition.top);
            currentLeft.value = Number(props.deskPosition.left);
            visualState.value = AnimationState.WORKING;
            isReadingPause.value = false;
            direction.value = deskFacing;
            horizontalFacing.value = deskFacing;
            dynamicOffsetY.value = 0;
            emitDebugSnapshot();
            return;
        }

        const { zone, settledState } = mapBehaviorToTarget(newBehaviorState);
        desiredSettledState.value = settledState;

        // If already seated at desk and switching between WORKING / ANALYZING / IDLE / DONE, transition in-place smoothly without resetting frame accumulator!
        if (
            !forceImmediate &&
            zone === 'desk' &&
            currentTargetZone.value === 'desk' &&
            waypointQueue.value.length === 0 &&
            (visualState.value === AnimationState.WORKING ||
                visualState.value === AnimationState.ANALYZING ||
                visualState.value === AnimationState.IDLE ||
                visualState.value === AnimationState.DONE ||
                visualState.value === AnimationState.OFFLINE)
        ) {
            visualState.value = settledState;
            if (settledState !== AnimationState.WORKING && settledState !== AnimationState.ANALYZING) {
                isReadingPause.value = false;
            }
            emitDebugSnapshot();
            return;
        }

        // If already walking toward the exact same target zone, do NOT reset mid-walk!
        if (
            !forceImmediate &&
            zone === currentTargetZone.value &&
            (visualState.value === AnimationState.STAND_UP ||
                visualState.value === AnimationState.TURNING ||
                visualState.value === AnimationState.WALKING ||
                visualState.value === AnimationState.DESK_APPROACH ||
                visualState.value === AnimationState.SITTING_DOWN)
        ) {
            return;
        }

        const route = buildOfficeRoute({
            currentPos: { top: currentTop.value, left: currentLeft.value },
            targetZone: zone,
            slotNumber: props.slotNumber,
            deskPosition: props.deskPosition,
        });

        currentTargetZone.value = zone;

        if (forceImmediate || route.length === 0) {
            waypointQueue.value = [];
            visualState.value = settledState;
            dynamicOffsetY.value = 0;
            if (zone === 'desk') {
                currentTop.value = Number(props.deskPosition.top);
                currentLeft.value = Number(props.deskPosition.left);
                direction.value = deskFacing;
                horizontalFacing.value = deskFacing;
            }
            emitDebugSnapshot();
            return;
        }

        let totalDist = 0;
        let prevPt = { top: currentTop.value, left: currentLeft.value };
        for (const wp of route) {
            totalDist += Math.hypot(wp.left - prevPt.left, wp.top - prevPt.top);
            prevPt = wp;
        }
        currentTravelTotalDist = Math.max(0.1, totalDist);
        currentTravelCoveredDist = 0;
        waypointQueue.value = route;

        const firstWp = route[0];
        const dx = firstWp.left - currentLeft.value;
        const dy = firstWp.top - currentTop.value;

        // Leaving workstation: `typing -> stop typing -> hands return to idle -> standing -> walking`
        if (
            visualState.value === AnimationState.WORKING ||
            visualState.value === AnimationState.ANALYZING ||
            visualState.value === AnimationState.IDLE ||
            visualState.value === AnimationState.DONE
        ) {
            isReadingPause.value = false;
            visualState.value = AnimationState.STAND_UP;
            phaseTimerSec = 0.24;
        } else {
            if (Math.abs(dx) > 0.15) {
                horizontalFacing.value = dx >= 0 ? 'right' : 'left';
            }
            direction.value = resolveCompassDirection(dx, dy, horizontalFacing.value);
            visualState.value = AnimationState.TURNING;
            phaseTimerSec = 0.09;
        }

        emitDebugSnapshot();
    }

    /**
     * Main requestAnimationFrame tick with deltaTime
     */
    function stepAnimationFrame(timestamp) {
        if (typeof document !== 'undefined' && document.hidden) {
            lastTimestamp = timestamp;
            rafId = window.requestAnimationFrame(stepAnimationFrame);
            return;
        }

        if (!lastTimestamp) lastTimestamp = timestamp;
        const rawDeltaSec = (timestamp - lastTimestamp) / 1000;
        lastTimestamp = timestamp;

        if (rawDeltaSec > 0.001) {
            const instFps = Math.round(1 / rawDeltaSec);
            fpsReadout.value = Math.min(144, Math.max(15, Math.round(fpsReadout.value * 0.85 + instFps * 0.15)));
        }

        const dt = Math.min(0.08, Math.max(0.0, rawDeltaSec));
        const deskFacing = props.deskPosition?.facing || wsDefault.facing;

        // In Calibration Mode: run live 8-frame typing preview (unless admin locked a specific frame 1..8)
        if (props.calibrationMode) {
            dynamicOffsetY.value = 0;
            horizontalFacing.value = deskFacing;
            direction.value = deskFacing;
            isReadingPause.value = false;

            const locked = Number(props.typingCalibration?.lockedFrame || 0);
            if (locked >= 1 && locked <= 8) {
                workTypingFrameIndex.value = locked - 1;
            } else if (!prefersReducedMotion.value) {
                const typingFrameDur = 1 / effectiveTypingFps.value;
                typingAccumulatorSec += dt;
                while (typingAccumulatorSec >= typingFrameDur) {
                    typingAccumulatorSec -= typingFrameDur;
                    workTypingFrameIndex.value = (workTypingFrameIndex.value + 1) % 8;
                }
            }
            rafId = window.requestAnimationFrame(stepAnimationFrame);
            return;
        }

        // 0. Handle WORKING & ANALYZING states (8-frame typing loop + natural reading pause rhythm, 0 chair bounce!)
        if (
            visualState.value === AnimationState.WORKING ||
            visualState.value === AnimationState.ANALYZING
        ) {
            dynamicOffsetY.value = 0;
            horizontalFacing.value = deskFacing;
            direction.value = deskFacing;

            if (!prefersReducedMotion.value) {
                rhythmElapsedSec += dt;
                const activeBurstLimit =
                    visualState.value === AnimationState.ANALYZING
                        ? rhythmBurstDurationSec * 0.72
                        : rhythmBurstDurationSec;
                const activePauseLimit =
                    visualState.value === AnimationState.ANALYZING
                        ? rhythmPauseDurationSec * 1.45
                        : rhythmPauseDurationSec;

                if (!isReadingPause.value) {
                    if (rhythmElapsedSec >= activeBurstLimit) {
                        isReadingPause.value = true;
                        rhythmElapsedSec = 0;
                    } else {
                        const typingFrameDur = 1 / effectiveTypingFps.value;
                        typingAccumulatorSec += dt;
                        while (typingAccumulatorSec >= typingFrameDur) {
                            typingAccumulatorSec -= typingFrameDur;
                            workTypingFrameIndex.value = (workTypingFrameIndex.value + 1) % 8;
                        }
                    }
                } else {
                    if (rhythmElapsedSec >= activePauseLimit) {
                        isReadingPause.value = false;
                        rhythmElapsedSec = 0;
                    }
                }
            }
        }
        // 1. Handle STAND_UP state (stop typing -> hands return to idle -> stand up before walking)
        else if (visualState.value === AnimationState.STAND_UP) {
            phaseTimerSec -= dt;
            dynamicOffsetY.value = -1.5;
            if (phaseTimerSec <= 0) {
                if (waypointQueue.value.length > 0) {
                    const wp = waypointQueue.value[0];
                    const dx = wp.left - currentLeft.value;
                    const dy = wp.top - currentTop.value;
                    if (Math.abs(dx) > 0.15) {
                        horizontalFacing.value = dx >= 0 ? 'right' : 'left';
                    }
                    direction.value = resolveCompassDirection(dx, dy, horizontalFacing.value);
                }
                visualState.value = AnimationState.TURNING;
                phaseTimerSec = 0.08;
            }
        }
        // 2. Handle TURNING state
        else if (visualState.value === AnimationState.TURNING) {
            phaseTimerSec -= dt;
            dynamicOffsetY.value = 0;
            if (waypointQueue.value.length > 0) {
                const wp = waypointQueue.value[0];
                const dx = wp.left - currentLeft.value;
                const dy = wp.top - currentTop.value;
                if (Math.abs(dx) > 0.15) {
                    horizontalFacing.value = dx >= 0 ? 'right' : 'left';
                }
                direction.value = resolveCompassDirection(dx, dy, horizontalFacing.value);
            }
            if (phaseTimerSec <= 0) {
                visualState.value = AnimationState.WALKING;
                walkAccumulatorSec = 0;
            }
        }
        // 3. Handle WALKING & DESK_APPROACH states (8-frame walk cycle + delta-time waypoint traversal)
        else if (
            visualState.value === AnimationState.WALKING ||
            visualState.value === AnimationState.DESK_APPROACH
        ) {
            const frameDuration = 1 / walkFps.value;
            walkAccumulatorSec += dt;
            while (walkAccumulatorSec >= frameDuration) {
                walkAccumulatorSec -= frameDuration;
                walkFrameIndex.value = (walkFrameIndex.value + 1) % 8;
            }

            const bobTable = [0, 0.7, -0.7, -1.6, 0, 0.7, -0.7, -1.6];
            dynamicOffsetY.value = prefersReducedMotion.value ? 0 : bobTable[walkFrameIndex.value] || 0;

            if (waypointQueue.value.length === 0) {
                dynamicOffsetY.value = 0;
                if (currentTargetZone.value === 'desk') {
                    horizontalFacing.value = deskFacing;
                    direction.value = deskFacing;
                    visualState.value = AnimationState.SITTING_DOWN;
                    phaseTimerSec = 0.22;
                } else {
                    visualState.value = desiredSettledState.value;
                }
                emitDebugSnapshot();
            } else {
                const targetWp = waypointQueue.value[0];
                const dx = targetWp.left - currentLeft.value;
                const dy = targetWp.top - currentTop.value;
                const dist = Math.hypot(dx, dy);

                if (targetWp.role === 'seat' && visualState.value !== AnimationState.DESK_APPROACH) {
                    visualState.value = AnimationState.DESK_APPROACH;
                }

                if (dist > 0.08) {
                    if (Math.abs(dx) > 0.12) {
                        horizontalFacing.value = dx >= 0 ? 'right' : 'left';
                    }
                    direction.value = resolveCompassDirection(dx, dy, horizontalFacing.value);
                }

                const progressRatio = Math.min(1, currentTravelCoveredDist / currentTravelTotalDist);
                const easeFactor =
                    progressRatio < 0.12
                        ? 0.74 + (progressRatio / 0.12) * 0.26
                        : progressRatio > 0.84
                          ? 0.68 + ((1 - progressRatio) / 0.16) * 0.32
                          : 1.0;

                const effectiveSpeed = BASE_WALK_SPEED * easeFactor;
                currentSpeedUnits.value = Number(effectiveSpeed.toFixed(1));
                const stepDist = effectiveSpeed * dt;

                if (dist <= stepDist || dist < 0.22) {
                    currentLeft.value = Number(targetWp.left.toFixed(2));
                    currentTop.value = Number(targetWp.top.toFixed(2));
                    currentTravelCoveredDist += dist;
                    const finishedWp = waypointQueue.value.shift();

                    if (waypointQueue.value.length > 0) {
                        const nextWp = waypointQueue.value[0];
                        const nextDx = nextWp.left - currentLeft.value;
                        const nextDy = nextWp.top - currentTop.value;
                        const nextHoriz =
                            Math.abs(nextDx) > 0.12
                                ? nextDx >= 0
                                    ? 'right'
                                    : 'left'
                                : horizontalFacing.value;

                        if (nextWp.role === 'seat') {
                            horizontalFacing.value = deskFacing;
                            direction.value = deskFacing;
                            visualState.value = AnimationState.DESK_APPROACH;
                        } else if (nextHoriz !== horizontalFacing.value) {
                            horizontalFacing.value = nextHoriz;
                            direction.value = resolveCompassDirection(nextDx, nextDy, horizontalFacing.value);
                            visualState.value = AnimationState.TURNING;
                            phaseTimerSec = 0.06;
                        }
                    } else {
                        currentSpeedUnits.value = 0;
                        dynamicOffsetY.value = 0;
                        if (currentTargetZone.value === 'desk') {
                            horizontalFacing.value = deskFacing;
                            direction.value = deskFacing;
                            visualState.value = AnimationState.SITTING_DOWN;
                            phaseTimerSec = 0.22;
                        } else {
                            if (finishedWp?.facing) {
                                horizontalFacing.value = finishedWp.facing;
                                direction.value = finishedWp.facing;
                            }
                            visualState.value = desiredSettledState.value;
                        }
                    }
                    emitDebugSnapshot();
                } else {
                    const nx = dx / dist;
                    const ny = dy / dist;
                    currentLeft.value = Number((currentLeft.value + nx * stepDist).toFixed(2));
                    currentTop.value = Number((currentTop.value + ny * stepDist).toFixed(2));
                    currentTravelCoveredDist += stepDist;
                }
            }
        }
        // 4. Handle SITTING_DOWN state (`walking -> arrive at chair -> face monitor -> sitting -> place hands -> typing`)
        else if (visualState.value === AnimationState.SITTING_DOWN) {
            phaseTimerSec -= dt;
            dynamicOffsetY.value = 0.6;
            horizontalFacing.value = deskFacing;
            direction.value = deskFacing;
            if (phaseTimerSec <= 0) {
                dynamicOffsetY.value = 0;
                isReadingPause.value = false;
                visualState.value = desiredSettledState.value;
                emitDebugSnapshot();
            }
        }
        // 5. Handle IDLE / OFFLINE at desk
        else if (visualState.value === AnimationState.IDLE || visualState.value === AnimationState.OFFLINE) {
            dynamicOffsetY.value = 0;
            isReadingPause.value = false;
            horizontalFacing.value = deskFacing;
            direction.value = deskFacing;
        }

        rafId = window.requestAnimationFrame(stepAnimationFrame);
    }

    watch(
        () => props.behaviorState,
        (newState) => {
            syncDesiredBehavior(newState, false);
        }
    );

    watch(
        () => props.calibrationMode,
        (isCalib) => {
            const deskFacing = props.deskPosition?.facing || wsDefault.facing;
            if (isCalib) {
                waypointQueue.value = [];
                currentTop.value = Number(props.deskPosition.top);
                currentLeft.value = Number(props.deskPosition.left);
                horizontalFacing.value = deskFacing;
                direction.value = deskFacing;
                visualState.value = AnimationState.WORKING;
                dynamicOffsetY.value = 0;
                emitDebugSnapshot();
            } else {
                syncDesiredBehavior(props.behaviorState, true);
            }
        }
    );

    watch(
        () => [props.deskPosition?.top, props.deskPosition?.left, props.deskPosition?.facing],
        ([newTop, newLeft, newFacing]) => {
            if (
                props.calibrationMode ||
                (currentTargetZone.value === 'desk' &&
                    visualState.value !== AnimationState.WALKING &&
                    visualState.value !== AnimationState.DESK_APPROACH)
            ) {
                currentTop.value = Number(newTop);
                currentLeft.value = Number(newLeft);
                if (newFacing) {
                    horizontalFacing.value = newFacing;
                    direction.value = newFacing;
                }
                emitDebugSnapshot();
            }
        }
    );

    watch(
        () => props.skinId,
        (newSkin) => {
            preloadAgentSprites([newSkin]);
        }
    );

    onMounted(() => {
        if (typeof window !== 'undefined' && window.matchMedia) {
            const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
            prefersReducedMotion.value = mq.matches;
        }
        preloadAgentSprites([1, 2, 3, 4, 5, 6, 7, 8]);
        syncDesiredBehavior(props.behaviorState, true);
        if (typeof window !== 'undefined') {
            rafId = window.requestAnimationFrame(stepAnimationFrame);
        }
    });

    onUnmounted(() => {
        if (rafId && typeof window !== 'undefined') {
            window.cancelAnimationFrame(rafId);
            rafId = null;
        }
    });

    return {
        currentTop,
        currentLeft,
        visualState,
        direction,
        horizontalFacing,
        walkFrameIndex,
        workTypingFrameIndex,
        isReadingPause,
        isTypingActive,
        walkFps,
        effectiveTypingFps,
        waypointQueue,
        dynamicOffsetY,
        currentSpeedUnits,
        fpsReadout,
        skinAsset,
        activeSheetType,
        activeSpriteSheetUrl,
        activeBackgroundPosition,
        shouldFlipHorizontal,
        isometricDepthScale,
        dynamicZIndex,
        handOverlayZIndex,
        uiOverlayZIndex,
        syncDesiredBehavior,
    };
}
