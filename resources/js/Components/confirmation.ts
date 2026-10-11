import { shallowRef } from "vue";

export interface ConfirmationOptions {
    title: string;
    message: string;
    confirmLabel: string;
    cancelLabel?: string;
    note?: string;
    detail?: string;
    tone?: "export" | "warning" | "refresh" | "lock";
}

export const confirmation = shallowRef<ConfirmationOptions | null>(null);
let resolveConfirmation: ((accepted: boolean) => void) | null = null;

export function askConfirmation(
    options: ConfirmationOptions,
): Promise<boolean> {
    if (confirmation.value) return Promise.resolve(false);
    return new Promise((resolve) => {
        resolveConfirmation = resolve;
        confirmation.value = options;
    });
}

export function settleConfirmation(accepted: boolean): void {
    const resolve = resolveConfirmation;
    resolveConfirmation = null;
    confirmation.value = null;
    resolve?.(accepted);
}
