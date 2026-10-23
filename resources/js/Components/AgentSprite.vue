<script setup>
import { ref, computed, onUnmounted } from 'vue';
import { AnimationState } from '@/config/agentSprites';
import { useAgentAnimation } from '@/composables/useAgentAnimation';
import AgentTypingSprite from '@/Components/AgentTypingSprite.vue';

const props = defineProps({
    agent: {
        type: Object,
        required: true,
    },
    slotNumber: {
        type: Number,
        required: true,
    },
    skinId: {
        type: Number,
        default: 1,
    },
    deskPosition: {
        type: Object,
        required: true, // { top: Number, left: Number, facing: 'left' | 'right' }
    },
    typingCalibration: {
        type: Object,
        default: () => ({
            scale: 1,
            offsetX: 0,
            offsetY: 0,
            fps: 9.5,
            direction: 'toward-monitor',
            pivotX: 50,
            pivotY: 52,
            lockedFrame: 0,
            insetBottomPct: null,
        }),
    },
    serverPosition: {
        type: Object,
        default: () => ({ top: 49.5, left: 48.4 }),
    },
    coffeePosition: {
        type: Object,
        default: () => ({ top: 38.5, left: 73.5 }),
    },
    behaviorState: {
        type: String,
        default: 'idle',
    },
    statusText: {
        type: String,
        default: 'Siap memeriksa...',
    },
    isSelected: {
        type: Boolean,
        default: false,
    },
    calibrationMode: {
        type: Boolean,
        default: false,
    },
    debugMode: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['select', 'update-position', 'open-skin-picker', 'path-update']);

const {
    currentTop,
    currentLeft,
    visualState,
    direction,
    horizontalFacing,
    walkFrameIndex,
    workTypingFrameIndex,
    isReadingPause,
    isTypingActive,
    effectiveTypingFps,
    dynamicOffsetY,
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
} = useAgentAnimation(props, emit);

const isWorkingAtDesk = computed(
    () =>
        visualState.value === AnimationState.WORKING ||
        visualState.value === AnimationState.ANALYZING
);
const isCoffeeBreak = computed(() => visualState.value === AnimationState.COFFEE);
const isAtServer = computed(() => visualState.value === AnimationState.SERVER);
const isDoneWaving = computed(() => visualState.value === AnimationState.DONE);
const isSeatedInChair = computed(() => activeSheetType.value === 'work');
const isIdleBreathing = computed(
    () => visualState.value === AnimationState.IDLE || visualState.value === AnimationState.SERVER
);
const isWalkingOrTransitioning = computed(
    () =>
        visualState.value === AnimationState.WALKING ||
        visualState.value === AnimationState.DESK_APPROACH ||
        visualState.value === AnimationState.STAND_UP ||
        visualState.value === AnimationState.TURNING ||
        visualState.value === AnimationState.SITTING_DOWN
);

const effectiveFrameIndex = computed(() => {
    const locked = Number(props.typingCalibration?.lockedFrame || 0);
    if (props.calibrationMode && locked >= 1 && locked <= 8) {
        return locked - 1;
    }
    return workTypingFrameIndex.value;
});

const worldBoxStyle = computed(() => {
    const pivotX = isSeatedInChair.value ? Number(props.typingCalibration?.pivotX ?? 50) : 50;
    const pivotY = isSeatedInChair.value ? Number(props.typingCalibration?.pivotY ?? 52) : 52;
    const offX = isSeatedInChair.value ? Number(props.typingCalibration?.offsetX ?? 0) : 0;
    const offY = isSeatedInChair.value ? Number(props.typingCalibration?.offsetY ?? 0) : 0;

    return {
        top: `${currentTop.value}%`,
        left: `${currentLeft.value}%`,
        width: '11.5%',
        height: '17.25%',
        transform: `translate3d(calc(-${pivotX}% + ${offX}px), calc(-${pivotY}% + ${offY}px), 0)`,
    };
});

const motionLayerStyle = computed(() => {
    return {
        transform: `translate3d(0, ${dynamicOffsetY.value}px, 0) scale(${isometricDepthScale.value})`,
    };
});

// Compute left/right hand & seat anchor marker positions inside the sprite box (accounting for horizontal flip if active)
const calibMarkers = computed(() => {
    const asset = skinAsset.value;
    const flipped = shouldFlipHorizontal.value;
    const flipX = (x) => (flipped ? 100 - x : x);

    const hl = asset.handLeftPct || { x: 31, y: 66 };
    const hr = asset.handRightPct || { x: 43, y: 68 };
    const st = asset.seatAnchorPct || { x: 58, y: 74 };

    return {
        handLeft: { left: `${flipX(hl.x)}%`, top: `${hl.y}%` },
        handRight: { left: `${flipX(hr.x)}%`, top: `${hr.y}%` },
        seat: { left: `${flipX(st.x)}%`, top: `${st.y}%` },
        pivot: {
            left: `${Number(props.typingCalibration?.pivotX ?? 50)}%`,
            top: `${Number(props.typingCalibration?.pivotY ?? 52)}%`,
        },
    };
});

const statusDotClass = computed(() => {
    if (visualState.value === AnimationState.OFFLINE) return 'bg-[#D7D1C8]';
    if (isWalkingOrTransitioning.value) return 'bg-[#85500F]';
    if (isWorkingAtDesk.value || isAtServer.value) return 'bg-[#245F59]';
    if (isCoffeeBreak.value) return 'bg-[#85500F]';
    if (isDoneWaving.value) return 'bg-[#FFA4A4]';
    return 'bg-[#245F59]';
});

// Drag support when calibrationMode is active
const isDragging = ref(false);
let parentRect = null;

function onPointerDown(e) {
    if (!props.calibrationMode) {
        emit('select', props.agent);
        return;
    }
    e.stopPropagation();
    emit('select', props.agent);
    const container = e.currentTarget.parentElement;
    if (!container) return;
    parentRect = container.getBoundingClientRect();
    isDragging.value = true;
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
}

function onPointerMove(e) {
    if (!isDragging.value || !parentRect) return;
    const relX = ((e.clientX - parentRect.left) / parentRect.width) * 100;
    const relY = ((e.clientY - parentRect.top) / parentRect.height) * 100;
    const clampedTop = Math.round(Math.min(90, Math.max(12, relY)) * 10) / 10;
    const clampedLeft = Math.round(Math.min(90, Math.max(8, relX)) * 10) / 10;
    currentTop.value = clampedTop;
    currentLeft.value = clampedLeft;
    emit('update-position', {
        slot: props.slotNumber,
        top: clampedTop,
        left: clampedLeft,
    });
}

function onPointerUp() {
    isDragging.value = false;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
}

onUnmounted(() => {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
});
</script>

<template>
    <!-- ================================================================= -->
    <!-- LAYER 2: CHARACTER BODY & LEGS (z-index: 20 + seatTop)            -->
    <!-- Rendered BEFORE Desk Foreground Mask (21 + seatTop) so legs tuck  -->
    <!-- cleanly underneath the wooden desk while hips rest on the chair!  -->
    <!-- ================================================================= -->
    <div
        class="agent-world-root absolute select-none group"
        :class="calibrationMode ? 'cursor-move' : 'cursor-pointer'"
        :style="{
            ...worldBoxStyle,
            zIndex: dynamicZIndex,
        }"
        @pointerdown="onPointerDown"
    >
        <!-- Ground Contact Shadow & Selected Highlight Aura -->
        <div
            class="absolute left-1/2 -translate-x-1/2 bottom-[7%] rounded-full pointer-events-none transition-all duration-200"
            :class="[
                isSelected
                    ? 'w-[58%] h-[14%] bg-[#FFA4A4]/50 ring-2 ring-[#FFA4A4]'
                    : isSeatedInChair
                      ? 'opacity-0'
                      : 'w-[44%] h-[10%] bg-[#302B2A]/16 group-hover:bg-[#302B2A]/26',
            ]"
        ></div>

        <!-- Isometric Depth Scale & Stride Bob Layer (Zero bounce when seated in chair!) -->
        <div
            class="agent-motion-layer absolute inset-0 pointer-events-none"
            :class="{
                'anim-done-wave': isDoneWaving,
                'anim-idle-breathe': isIdleBreathing && !isWalkingOrTransitioning && !isSeatedInChair,
                'opacity-55 grayscale': visualState === AnimationState.OFFLINE,
            }"
            :style="motionLayerStyle"
        >
            <!-- Seated Working / Typing / Desk State: Dedicated 8-Frame AgentTypingSprite (Body Layer) -->
            <AgentTypingSprite
                v-if="isSeatedInChair"
                :skin-id="skinId"
                :slot-number="slotNumber"
                :frame-index="effectiveFrameIndex"
                :is-typing-active="isTypingActive"
                :is-reading-pause="isReadingPause"
                :should-flip-horizontal="shouldFlipHorizontal"
                :inset-bottom-pct="typingCalibration?.insetBottomPct"
                layer-mode="body"
            />

            <!-- Walking / Standing / Coffee / Server States -->
            <div
                v-else
                class="agent-direction-layer w-full h-full"
                :style="{
                    transform: shouldFlipHorizontal ? 'scaleX(-1)' : 'scaleX(1)',
                }"
            >
                <div
                    class="sprite-character w-full h-full"
                    :style="{
                        backgroundImage: activeSpriteSheetUrl,
                        backgroundSize: '400% 200%',
                        backgroundPosition: activeBackgroundPosition,
                        backgroundRepeat: 'no-repeat',
                    }"
                ></div>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- LAYER 4: SPEECH BUBBLE, STATUS FX & ADMIN CALIBRATION MARKERS     -->
    <!-- (z-index: 40/85 + seatTop — Always crisp above all furniture!)    -->
    <!-- ================================================================= -->
    <div
        class="agent-world-ui absolute select-none pointer-events-none"
        :style="{
            ...worldBoxStyle,
            zIndex: uiOverlayZIndex,
        }"
    >
        <!-- Animation Effect Layer (Coffee & Server Sync Badges) -->
        <div class="absolute inset-0 pointer-events-none">
            <div
                v-if="isCoffeeBreak"
                class="absolute right-[18%] top-[18%] flex items-center justify-center bg-[#FCF9EA]/90 border border-[#D7D1C8] rounded-full px-1.5 py-0.5 text-[9px] shadow-xs animate-bounce"
            >
                ☕
            </div>
            <div
                v-else-if="isAtServer"
                class="absolute right-[16%] top-[18%] flex items-center gap-1 bg-[#BADFDB]/95 border border-[#245F59] rounded-full px-1.5 py-0.5 text-[8.5px] font-mono font-bold text-[#245F59] shadow-xs"
            >
                <span class="w-1.5 h-1.5 rounded-full bg-[#245F59] animate-ping"></span>
                <span>SYNC</span>
            </div>
        </div>

        <!-- Admin Typing Calibration Overlay Markers (Left Hand, Right Hand, Seat, Body Pivot) -->
        <div
            v-if="calibrationMode || debugMode"
            class="absolute inset-0 pointer-events-none"
            :style="motionLayerStyle"
        >
            <!-- Marker Tangan Kiri (Left Hand) -->
            <div
                class="calib-marker calib-hand-l absolute -translate-x-1/2 -translate-y-1/2"
                :style="calibMarkers.handLeft"
                title="Marker Tangan Kiri (Left Hand)"
            >
                <span>L</span>
            </div>

            <!-- Marker Tangan Kanan (Right Hand) -->
            <div
                class="calib-marker calib-hand-r absolute -translate-x-1/2 -translate-y-1/2"
                :style="calibMarkers.handRight"
                title="Marker Tangan Kanan (Right Hand)"
            >
                <span>R</span>
            </div>

            <!-- Marker Posisi Duduk (Seat Cushion Anchor) -->
            <div
                class="calib-marker calib-seat absolute -translate-x-1/2 -translate-y-1/2"
                :style="calibMarkers.seat"
                title="Marker Posisi Duduk (Seat Anchor)"
            >
                <span>S</span>
            </div>

            <!-- Marker Anchor Tubuh (Body Pivot) -->
            <div
                class="calib-marker calib-pivot absolute -translate-x-1/2 -translate-y-1/2"
                :style="calibMarkers.pivot"
                title="Marker Anchor Tubuh (Pivot)"
            >
                <span>+</span>
            </div>
        </div>

        <!-- Floating Speech Bubble & Mobile Micro-Badge -->
        <!-- 1. MOBILE UNSELECTED: Compact Micro-Pill to eliminate overlapping on mobile screens -->
        <div
            v-if="!isSelected"
            class="agent-micro-badge sm:hidden pointer-events-auto cursor-pointer absolute left-1/2 -translate-x-1/2 flex items-center gap-1 bg-[#FFFFFF]/95 border border-[#D7D1C8] hover:border-[#302B2A] rounded-full px-1.5 py-0.5 shadow-xs transition-transform active:scale-95 z-20"
            :title="`${agent.name || 'Agent ' + slotNumber}: ${statusText}`"
            @pointerdown.stop="emit('select', props.agent)"
        >
            <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="statusDotClass"></span>
            <span class="font-mono text-[9px] font-bold text-[#302B2A] leading-none">#{{ slotNumber }}</span>
        </div>

        <!-- 2. MOBILE SELECTED: Expanded Active Speech Bubble -->
        <div
            v-if="isSelected"
            class="speech-bubble sm:hidden pointer-events-auto cursor-pointer absolute left-1/2 -translate-x-1/2 w-max max-w-[126px] bg-[#FFFFFF]/95 border border-[#302B2A] ring-2 ring-[#FFA4A4] rounded-xl px-2 py-1 shadow-md z-30 transition-colors duration-150"
            @pointerdown.stop="emit('select', props.agent)"
        >
            <div class="flex items-center justify-between gap-1">
                <div class="flex items-center gap-1 min-w-0">
                    <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="statusDotClass"></span>
                    <span class="bubble-name text-[9.5px] font-bold text-[#302B2A] truncate leading-tight">
                        {{ agent.name || `Agent ${slotNumber}` }}
                    </span>
                </div>
                <span
                    class="font-mono tabular-nums text-[8px] font-semibold px-1 py-0.2 rounded shrink-0 bg-[#BADFDB] text-[#245F59]"
                >
                    #{{ slotNumber }}
                </span>
            </div>

            <p class="bubble-text text-[8px] text-[#5A5150] truncate leading-tight font-medium mt-0.5">
                {{ statusText }}
            </p>

            <div
                class="absolute left-1/2 -bottom-[4px] -translate-x-1/2 w-2 h-2 bg-[#FFFFFF] border-r border-b rotate-45 border-[#302B2A]"
            ></div>
        </div>

        <!-- 3. DESKTOP VIEW: Full Speech Bubble on all agents -->
        <div
            class="speech-bubble hidden sm:block pointer-events-auto cursor-pointer absolute left-1/2 -translate-x-1/2 w-max max-w-[164px] bg-[#FFFFFF]/95 border rounded-xl px-2.5 py-1 shadow-xs transition-colors duration-150"
            :class="
                isSelected
                    ? 'border-[#302B2A] ring-2 ring-[#FFA4A4] shadow-md z-30'
                    : 'border-[#D7D1C8] hover:border-[#302B2A]'
            "
            @pointerdown.stop="emit('select', props.agent)"
        >
            <div class="flex items-center justify-between gap-1.5">
                <div class="flex items-center gap-1 min-w-0">
                    <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="statusDotClass"></span>
                    <span class="bubble-name text-[10.5px] font-bold text-[#302B2A] truncate leading-tight">
                        {{ agent.name || `Agent ${slotNumber}` }}
                    </span>
                </div>
                <span
                    class="font-mono tabular-nums text-[8.5px] font-semibold px-1 py-0.5 rounded shrink-0"
                    :class="
                        isWorkingAtDesk || isAtServer
                            ? 'bg-[#BADFDB] text-[#245F59]'
                            : isCoffeeBreak || isWalkingOrTransitioning
                              ? 'bg-[#FCF9EA] text-[#85500F]'
                              : 'bg-[#FFBDBD] text-[#302B2A]'
                    "
                >
                    #{{ slotNumber }}
                </span>
            </div>

            <p class="bubble-text text-[9.5px] text-[#5A5150] truncate leading-tight font-medium">
                {{ statusText }}
            </p>

            <!-- Debug & Calibration Telemetry Readout -->
            <div
                v-if="calibrationMode || debugMode"
                class="mt-0.5 pt-0.5 border-t border-[#D7D1C8] flex flex-col gap-0.5 font-mono tabular-nums text-[8px] text-[#302B2A]"
            >
                <div class="flex items-center justify-between gap-1 text-[#85500F]">
                    <span>{{ currentLeft.toFixed(1) }}%, {{ currentTop.toFixed(1) }}%</span>
                    <span>{{ shouldFlipHorizontal ? 'flip' : 'native' }} ({{ horizontalFacing }})</span>
                </div>
                <div class="flex items-center justify-between gap-1 text-[#245F59]">
                    <span>
                        {{ visualState.toUpperCase() }} ·
                        {{
                            activeSheetType === 'work'
                                ? `F${effectiveFrameIndex + 1}/8`
                                : `W${walkFrameIndex + 1}/8`
                        }}
                    </span>
                    <span>{{ effectiveTypingFps.toFixed(1) }}f · {{ fpsReadout }}hz</span>
                </div>
            </div>

            <div
                class="absolute left-1/2 -bottom-[4px] -translate-x-1/2 w-2 h-2 bg-[#FFFFFF] border-r border-b rotate-45"
                :class="isSelected ? 'border-[#302B2A]' : 'border-[#D7D1C8]'"
            ></div>
        </div>
    </div>
</template>

<style scoped>
.agent-world-root,
.agent-world-hands,
.agent-world-ui {
    will-change: top, left, transform;
}

.agent-motion-layer {
    transform-origin: 50% 80%;
    will-change: transform;
}

.agent-direction-layer {
    transform-origin: 50% 50%;
    transition: transform 90ms ease-out;
}

.speech-bubble {
    bottom: calc(100% - 4%);
}

.agent-micro-badge {
    bottom: calc(100% - 2px);
    white-space: nowrap;
    z-index: 25;
}

.bubble-text {
    margin: 1px 0 0 !important;
    max-width: 142px !important;
}

.sprite-character {
    image-rendering: auto;
    will-change: background-position, background-image;
    filter: drop-shadow(0 3px 5px rgba(48, 43, 42, 0.14));
}

/* Calibration Markers (L Hand, R Hand, Seat, Pivot) */
.calib-marker {
    width: 13px;
    height: 13px;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'JetBrains Mono', monospace;
    font-size: 7.5px !important;
    font-weight: 700;
    line-height: 1 !important;
    border: 1.5px solid #ffffff;
    box-shadow: 0 1px 3px rgba(48, 43, 42, 0.35);
}

.calib-hand-l {
    background-color: #245f59;
    color: #ffffff;
}

.calib-hand-r {
    background-color: #1d7a8c;
    color: #ffffff;
}

.calib-seat {
    background-color: #85500f;
    color: #ffffff;
}

.calib-pivot {
    background-color: #a5363f;
    color: #ffffff;
}

@keyframes doneWave {
    0%,
    100% {
        transform: translate3d(0, 0px, 0) scale(1);
    }
    50% {
        transform: translate3d(0, -3px, 0) scale(1.025);
    }
}

.anim-done-wave {
    animation: doneWave 0.9s ease-in-out infinite;
}

@keyframes idleBreathe {
    0%,
    100% {
        transform: scaleY(1);
    }
    50% {
        transform: scaleY(1.015);
    }
}

.anim-idle-breathe {
    animation: idleBreathe 2.8s ease-in-out infinite;
}
</style>
