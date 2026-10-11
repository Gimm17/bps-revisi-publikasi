<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from "vue";
import {
    AlertTriangle,
    FileSpreadsheet,
    RefreshCw,
    ShieldCheck,
    X,
    Check,
} from "lucide-vue-next";
import { confirmation, settleConfirmation } from "./confirmation";

const dialog = ref<HTMLDialogElement | null>(null);
const cancelButton = ref<HTMLButtonElement | null>(null);
const icon = computed(
    () =>
        ({
            export: FileSpreadsheet,
            warning: AlertTriangle,
            refresh: RefreshCw,
            lock: ShieldCheck,
        })[confirmation.value?.tone ?? "warning"],
);
let previousFocus: HTMLElement | null = null;
let previousOverflow = "";

watch(
    confirmation,
    async (options) => {
        if (options) {
            previousFocus =
                document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null;
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = "hidden";
            dialog.value?.showModal();
            await nextTick();
            cancelButton.value?.focus();
        } else {
            dialog.value?.close();
            document.body.style.overflow = previousOverflow;
            previousFocus?.focus();
        }
    },
    { flush: "post" },
);

onUnmounted(() => {
    if (confirmation.value) {
        document.body.style.overflow = previousOverflow;
        settleConfirmation(false);
    }
});
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="confirm-dialog"
            aria-labelledby="confirmation-title"
            aria-describedby="confirmation-message"
            @cancel.prevent="settleConfirmation(false)"
        >
            <div v-if="confirmation" class="confirm-content">
                <button
                    type="button"
                    class="confirm-close"
                    aria-label="Tutup konfirmasi"
                    @click="settleConfirmation(false)"
                >
                    <X :size="20" aria-hidden="true" />
                </button>
                <div
                    class="confirm-emblem"
                    :class="confirmation.tone"
                    aria-hidden="true"
                >
                    <component
                        :is="icon"
                        :size="29"
                        :stroke-width="1.8"
                    /><span />
                </div>
                <h2 id="confirmation-title">{{ confirmation.title }}</h2>
                <p id="confirmation-message">{{ confirmation.message }}</p>
                <p v-if="confirmation.detail" class="confirm-detail">
                    {{ confirmation.detail }}
                </p>
                <div v-if="confirmation.note" class="confirm-note">
                    <ShieldCheck :size="19" aria-hidden="true" /><span>{{
                        confirmation.note
                    }}</span>
                </div>
                <div class="confirm-actions">
                    <button
                        ref="cancelButton"
                        type="button"
                        class="button secondary"
                        @click="settleConfirmation(false)"
                    >
                        {{ confirmation.cancelLabel ?? "Batal" }}
                    </button>
                    <button
                        type="button"
                        class="button primary"
                        @click="settleConfirmation(true)"
                    >
                        <Check :size="17" aria-hidden="true" />{{
                            confirmation.confirmLabel
                        }}
                    </button>
                </div>
            </div>
        </dialog>
    </Teleport>
</template>

<style scoped>
.confirm-dialog {
    position: fixed;
    inset: 0;
    margin: auto;
    padding: 0;
    width: min(520px, calc(100vw - 32px));
    max-height: calc(100dvh - 40px);
    overflow: auto;
    border: 1px solid var(--line);
    border-radius: 32px 32px 32px 12px;
    background: var(--cream);
    color: var(--ink);
    box-shadow: 0 24px 80px rgb(48 43 42 / 22%);
}
.confirm-dialog::backdrop {
    background: rgb(48 43 42 / 42%);
    backdrop-filter: blur(4px);
}
.confirm-dialog[open] {
    animation: confirm-in 160ms ease-out;
}
.confirm-content {
    position: relative;
    padding: 32px;
}
.confirm-close {
    position: absolute;
    right: 18px;
    top: 18px;
    width: 44px;
    height: 44px;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 50%;
    background: transparent;
}
.confirm-close:hover {
    background: var(--pink);
}
.confirm-emblem {
    position: relative;
    display: grid;
    place-items: center;
    width: 68px;
    height: 64px;
    margin-bottom: 23px;
    border-radius: 24px 30px 22px 28px;
    background: var(--pink);
}
.confirm-emblem.export,
.confirm-emblem.lock {
    background: var(--mint);
}
.confirm-emblem span {
    position: absolute;
    bottom: -4px;
    right: -5px;
    width: 19px;
    height: 19px;
    border: 4px solid var(--cream);
    background: var(--coral);
    border-radius: 50%;
}
.confirm-content h2 {
    font-size: 27px;
    padding-right: 16px;
}
.confirm-content p {
    margin: 14px 0 0;
    font-size: 14px;
    line-height: 1.7;
}
.confirm-content .confirm-detail {
    margin-top: 10px;
    font-size: 13px;
}
.confirm-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 22px;
    padding: 15px 17px;
    border-radius: 16px 16px 16px 6px;
    background: var(--mint);
    font-size: 13px;
    line-height: 1.6;
}
.confirm-note svg {
    margin-top: 2px;
}
.confirm-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 28px;
}
.confirm-actions .button {
    min-height: 46px;
    white-space: normal;
}
@keyframes confirm-in {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
@media (max-width: 480px) {
    .confirm-content {
        padding: 27px 22px 22px;
    }
    .confirm-content h2 {
        font-size: 24px;
    }
    .confirm-actions {
        flex-direction: column-reverse;
    }
    .confirm-actions .button {
        width: 100%;
    }
}
@media (prefers-reduced-motion: reduce) {
    .confirm-dialog[open] {
        animation: none;
    }
}
</style>
