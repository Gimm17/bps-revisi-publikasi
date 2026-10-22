<script setup>
import { computed } from 'vue';
import { AGENT_ASSETS, TYPING_FRAME_POSITIONS, WORK_SEATED_POSES } from '@/config/agentSprites';

const props = defineProps({
    skinId: {
        type: Number,
        default: 1,
    },
    slotNumber: {
        type: Number,
        default: 1,
    },
    frameIndex: {
        type: Number,
        default: 0, // 0..7 -> Frame 1..8
    },
    isTypingActive: {
        type: Boolean,
        default: true,
    },
    isReadingPause: {
        type: Boolean,
        default: false,
    },
    shouldFlipHorizontal: {
        type: Boolean,
        default: false,
    },
    /**
     * 'body' = Layer 2 (Full character body & legs, rendered behind Desk Foreground Mask)
     * 'hands' = Layer 4 (Upper body & hands overlay, rendered above Desk Foreground Mask & keyboard)
     */
    layerMode: {
        type: String,
        default: 'body',
    },
    insetBottomPct: {
        type: Number,
        default: null,
    },
});

const asset = computed(() => {
    const id = Math.min(8, Math.max(1, Number(props.skinId) || 1));
    return AGENT_ASSETS[id] || AGENT_ASSETS[1];
});

const spriteSheetUrl = computed(() => {
    return `url('${asset.value.typing || asset.value.work}')`;
});

const backgroundPosition = computed(() => {
    if (!props.isTypingActive) {
        return WORK_SEATED_POSES.SITTING_IDLE_34;
    }
    if (props.isReadingPause) {
        return WORK_SEATED_POSES.SITTING_GLANCE;
    }
    const idx = ((Number(props.frameIndex) || 0) % 8 + 8) % 8;
    return TYPING_FRAME_POSITIONS[idx] || TYPING_FRAME_POSITIONS[0];
});

const overlayClipPath = computed(() => {
    return 'none';
});
</script>

<template>
    <div
        class="agent-typing-direction w-full h-full pointer-events-none"
        :style="{
            transform: shouldFlipHorizontal ? 'scaleX(-1)' : 'scaleX(1)',
            clipPath: overlayClipPath,
        }"
    >
        <div
            class="agent-typing-sheet w-full h-full"
            :class="{
                'typing-body-shadow': layerMode === 'body',
            }"
            :style="{
                backgroundImage: spriteSheetUrl,
                backgroundSize: '400% 200%',
                backgroundPosition: backgroundPosition,
                backgroundRepeat: 'no-repeat',
            }"
        ></div>
    </div>
</template>

<style scoped>
.agent-typing-direction {
    transform-origin: 50% 50%;
    will-change: transform;
}

.agent-typing-sheet {
    image-rendering: auto;
    will-change: background-position, background-image;
}

.typing-body-shadow {
    filter: drop-shadow(0 3px 5px rgba(48, 43, 42, 0.14));
}
</style>
