<script setup lang="ts">
import { useForm, router } from "@inertiajs/vue3";
import { ref, computed, watch } from "vue";
import {
    Save,
    History,
    ArrowUpRight,
    Check,
    X,
    AlertCircle,
    HelpCircle,
    Edit3,
    FileText,
    ChevronDown,
    ChevronUp,
    Sparkles,
    CheckCircle2,
    XCircle,
    MessageSquare,
    Files
} from "lucide-vue-next";
import type { Evidence, Finding, Publication } from "../types";
import { askConfirmation } from "./confirmation";

const props = defineProps<{
    finding: Finding | null;
    publication: Publication;
    rules: Record<string, string>;
    canEdit: boolean;
    page: number;
}>();

const emit = defineEmits<{ saved: []; evidence: [number]; close: [] }>();

// Tab state for compact presentation: 'summary' (default review view), 'edit' (edit redaksi), 'evidence' (edit bukti)
const activeTab = ref<"summary" | "edit" | "evidence">("summary");
const showNoteField = ref(false);
const showAllQuotes = ref(false);
const evidenceSearchQuery = ref("");
const isObservedExpanded = ref(false);

const form = useForm({
    version: props.finding?.version ?? 1,
    rule_code: props.finding?.rule_code ?? "MANUAL.REVIEW",
    severity: props.finding?.severity ?? "verifikasi",
    observed: props.finding?.observed ?? "",
    expected: props.finding?.expected ?? "",
    recommendation: props.finding?.recommendation ?? "",
    review_status: props.finding?.review_status ?? "unreviewed",
    review_note: props.finding?.review_note ?? "",
    deleted: props.finding?.deleted ?? false,
    page: props.page,
    quote: "",
    evidences: props.finding
        ? (JSON.parse(JSON.stringify(props.finding.evidences)) as Evidence[])
        : undefined,
});

// Reset tab and note visibility when selected finding changes
watch(
    () => props.finding?.id,
    () => {
        activeTab.value = "summary";
        showNoteField.value =
            !!props.finding?.review_note ||
            props.finding?.review_status === "rejected";
        showAllQuotes.value = false;
        evidenceSearchQuery.value = "";
        isObservedExpanded.value = false;
        form.version = props.finding?.version ?? 1;
        form.rule_code = props.finding?.rule_code ?? "MANUAL.REVIEW";
        form.severity = props.finding?.severity ?? "verifikasi";
        form.observed = props.finding?.observed ?? "";
        form.expected = props.finding?.expected ?? "";
        form.recommendation = props.finding?.recommendation ?? "";
        form.review_status = props.finding?.review_status ?? "unreviewed";
        form.review_note = props.finding?.review_note ?? "";
        form.deleted = props.finding?.deleted ?? false;
        form.evidences = props.finding
            ? (JSON.parse(JSON.stringify(props.finding.evidences)) as Evidence[])
            : undefined;
    }
);

const history = ref<
    {
        id: number;
        created_at: string;
        reason: string;
        before: Record<string, unknown>;
        after: Record<string, unknown>;
    }[]
>([]);
const historyOpen = ref(false);
const historyError = ref("");
const historyLoading = ref(false);

function setDecision(status: "accepted" | "rejected" | "needs_verification" | "unreviewed") {
    form.review_status = status;
    if (status === "rejected") {
        showNoteField.value = true;
    }
}

function save() {
    const url = `/publications/${props.publication.id}/findings`;
    const options = { preserveScroll: true, onSuccess: () => emit("saved") };
    props.finding
        ? form.patch(`${url}/${props.finding.id}`, options)
        : form.post(url, options);
}

async function reloadConflict() {
    if (
        await askConfirmation({
            title: "Muat temuan versi terbaru?",
            message: "Versi terbaru akan dimuat dari hasil yang tersimpan di server.",
            detail: "Salin dahulu perubahan Anda yang belum disimpan agar tidak hilang.",
            confirmLabel: "Muat versi terbaru",
            cancelLabel: "Kembali menyunting",
            tone: "refresh",
        })
    ) {
        router.reload({ only: ["findings", "publication"] });
    }
}

async function loadHistory() {
    historyOpen.value = !historyOpen.value;
    if (!historyOpen.value || !props.finding) return;
    historyLoading.value = true;
    try {
        const response = await fetch(
            `/publications/${props.publication.id}/findings/${props.finding.id}/history`,
            { headers: { Accept: "application/json" } }
        );
        if (!response.ok) throw new Error();
        history.value = await response.json();
    } catch {
        historyError.value = "Riwayat belum bisa dimuat. Coba kembali.";
    } finally {
        historyLoading.value = false;
    }
}

const currentRuleTitle = computed(() => {
    return props.rules[form.rule_code] || form.rule_code;
});

const filteredEvidences = computed(() => {
    const list = form.evidences ?? props.finding?.evidences ?? [];
    if (!evidenceSearchQuery.value.trim()) return list;
    const q = evidenceSearchQuery.value.trim().toLowerCase();
    return list.filter((e) => {
        const p = String(e.physical_page ?? "");
        const pr = String(e.printed_page ?? "").toLowerCase();
        const qt = String(e.quote ?? "").toLowerCase();
        return p.includes(q) || pr.includes(q) || qt.includes(q);
    });
});

defineExpose({ dirty: () => form.isDirty });
</script>

<template>
    <form class="finding-editor finding-editor-compact" @submit.prevent="save">
        <!-- Header -->
        <div class="editor-header flex items-center justify-between gap-2 pb-2.5 border-b border-[#D7D1C8]">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-bold text-[#302B2A]">
                    {{ finding ? "Detail Temuan" : "Tambahkan Temuan" }}
                </h3>
                <span
                    v-if="finding"
                    :class="['severity-pill', form.severity]"
                >
                    {{
                        form.severity === "wajib"
                            ? "Wajib"
                            : form.severity === "saran"
                              ? "Saran"
                              : "Verifikasi"
                    }}
                </span>
            </div>

            <div class="flex items-center gap-1.5">
                <button
                    v-if="finding"
                    type="button"
                    class="history-btn"
                    @click="loadHistory"
                    :aria-expanded="historyOpen"
                    title="Lihat riwayat perubahan"
                >
                    <History :size="15" />
                    <span class="hidden sm:inline">Riwayat</span>
                </button>

                <button
                    type="button"
                    class="close-btn"
                    @click="emit('close')"
                    title="Tutup panel editor"
                    aria-label="Tutup panel editor"
                >
                    <X :size="15" />
                </button>
            </div>
        </div>

        <!-- Agreement Banner -->
        <div v-if="finding?.agreement === 'confirmed'" class="agreement-banner confirmed mt-2">
            <span>✓ Temuan dikonfirmasi oleh 2 Agen AI mandiri</span>
        </div>
        <div v-else-if="finding?.agreement === 'single_agent'" class="agreement-banner single-agent mt-2">
            <span>⚠ Terdeteksi oleh 1 Agen AI (Dapat ditinjau reviewer)</span>
        </div>

        <!-- Conflict Notice -->
        <div v-if="form.errors.version" class="notice error mt-2" role="alert">
            {{ form.errors.version }}
            <button type="button" class="button secondary mt-1" @click="reloadConflict">
                Muat ulang temuan
            </button>
        </div>

        <!-- ========================================================= -->
        <!-- MODE 1: REVIEW TEMUAN YANG ADA (ULTRA COMPACT HUB)        -->
        <!-- ========================================================= -->
        <div v-if="finding" class="review-hub mt-3">
            <!-- 1. STICKY / TOP DECISION ACTION ZONE -->
            <div class="decision-zone bg-[#FFFFFF] border border-[#D7D1C8] rounded-xl p-3 shadow-xs sticky top-0 z-10">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[11px] font-bold text-[#5A5150] uppercase tracking-wider">
                        Keputusan Pemeriksa
                    </span>
                    <button
                        v-if="!showNoteField && form.review_status !== 'rejected'"
                        type="button"
                        class="text-[11px] font-semibold text-[#245F59] hover:underline flex items-center gap-1 cursor-pointer"
                        @click="showNoteField = true"
                    >
                        <MessageSquare :size="12" />
                        <span>+ Catatan / Alasan</span>
                    </button>
                </div>

                <!-- 4 Quick Pill Buttons -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                    <button
                        type="button"
                        class="decision-pill-btn accept"
                        :class="{ active: form.review_status === 'accepted' }"
                        :disabled="!canEdit || form.processing"
                        @click="setDecision('accepted')"
                    >
                        <Check :size="14" />
                        <span>Terima</span>
                    </button>

                    <button
                        type="button"
                        class="decision-pill-btn reject"
                        :class="{ active: form.review_status === 'rejected' }"
                        :disabled="!canEdit || form.processing"
                        @click="setDecision('rejected')"
                    >
                        <X :size="14" />
                        <span>Tolak</span>
                    </button>

                    <button
                        type="button"
                        class="decision-pill-btn verify"
                        :class="{ active: form.review_status === 'needs_verification' }"
                        :disabled="!canEdit || form.processing"
                        @click="setDecision('needs_verification')"
                    >
                        <AlertCircle :size="14" />
                        <span>Verifikasi</span>
                    </button>

                    <button
                        type="button"
                        class="decision-pill-btn unreviewed"
                        :class="{ active: form.review_status === 'unreviewed' }"
                        :disabled="!canEdit || form.processing"
                        @click="setDecision('unreviewed')"
                    >
                        <HelpCircle :size="14" />
                        <span>Belum</span>
                    </button>
                </div>

                <!-- Review Note Input (always open if rejected, or toggled) -->
                <div v-if="showNoteField || form.review_status === 'rejected'" class="mt-2.5 pt-2.5 border-t border-[#E5E0D8]">
                    <div class="flex items-center justify-between mb-1">
                        <label for="review-note" class="text-[11px] font-bold text-[#302B2A] block">
                            Catatan / Alasan Penolakan:
                        </label>
                        <button
                            v-if="form.review_status !== 'rejected'"
                            type="button"
                            class="text-[10px] text-gray-400 hover:text-gray-600 cursor-pointer"
                            @click="showNoteField = false"
                        >
                            Tutup
                        </button>
                    </div>
                    <textarea
                        id="review-note"
                        v-model="form.review_note"
                        rows="2"
                        maxlength="3000"
                        class="w-full text-xs p-2 border border-[#D7D1C8] rounded-lg bg-[#FAF8F5] focus:bg-white focus:ring-1 focus:ring-[#245F59] focus:border-[#245F59]"
                        :placeholder="
                            form.review_status === 'rejected'
                                ? 'Tuliskan alasan penolakan (misal: template resmi BPS/podes berkala). Catatan ini otomatis menjadi bahan pembelajaran AI.'
                                : 'Tuliskan catatan tambahan pemeriksa...'
                        "
                    />
                    <small
                        v-if="form.review_status === 'rejected'"
                        class="block text-[10.5px] text-[#047857] mt-1 leading-tight flex items-center gap-1"
                    >
                        <span>💡</span>
                        <span>Catatan penolakan otomatis disimpan sebagai pembelajaran permanen sistem.</span>
                    </small>
                </div>

                <!-- Primary Save Button inside Quick Hub -->
                <div class="mt-3 flex items-center justify-between gap-2">
                    <span v-if="form.isDirty" class="text-[11px] font-semibold text-amber-700 animate-pulse flex items-center gap-1">
                        ● Ada perubahan belum disimpan
                    </span>
                    <span v-else class="text-[11px] text-gray-500">
                        Status saat ini: <strong class="text-gray-800">{{ form.review_status }}</strong>
                    </span>

                    <button
                        v-if="canEdit"
                        type="submit"
                        class="save-pill-btn"
                        :class="{ 'is-dirty': form.isDirty }"
                        :disabled="form.processing"
                    >
                        <Save :size="14" />
                        <span>{{ form.processing ? "Menyimpan..." : (form.isDirty ? "Simpan Perubahan" : "Simpan") }}</span>
                    </button>
                </div>
            </div>

            <!-- 2. SEGMENTED TABS (Ringkasan vs Edit Redaksi vs Koreksi Bukti) -->
            <div class="mt-3">
                <div class="tab-pill-bar flex items-center p-1 bg-[#ede8dc] border border-[#D7D1C8] rounded-xl gap-1">
                    <button
                        type="button"
                        class="tab-btn"
                        :class="{ active: activeTab === 'summary' }"
                        @click="activeTab = 'summary'"
                    >
                        <FileText :size="13" />
                        <span>Ringkasan &amp; Bukti</span>
                    </button>
                    <button
                        type="button"
                        class="tab-btn"
                        :class="{ active: activeTab === 'edit' }"
                        @click="activeTab = 'edit'"
                    >
                        <Edit3 :size="13" />
                        <span>Edit Redaksi</span>
                    </button>
                    <button
                        v-if="form.evidences && form.evidences.length > 0"
                        type="button"
                        class="tab-btn"
                        :class="{ active: activeTab === 'evidence' }"
                        @click="activeTab = 'evidence'"
                    >
                        <Files :size="13" />
                        <span>Bukti ({{ form.evidences.length }})</span>
                    </button>
                </div>

                <!-- TAB CONTENT A: RINGKASAN & BUKTI (CLEAN, COMPACT, READABLE) -->
                <div v-show="activeTab === 'summary'" class="mt-2.5 space-y-2.5">
                    <!-- Rule & Section Box -->
                    <div class="bg-white border border-[#D7D1C8] rounded-xl p-3 text-xs">
                        <div class="flex items-center justify-between text-[10.5px] text-[#5A5150] mb-1 font-mono">
                            <span>KODE: {{ form.rule_code }}</span>
                            <span class="capitalize">{{ finding.source }} · {{ Math.round(finding.confidence * 100) }}% yakin</span>
                        </div>
                        <h4 class="font-bold text-[#302B2A] text-sm">
                            {{ currentRuleTitle }}
                        </h4>
                    </div>

                    <!-- Observed Condition -->
                    <div class="bg-[#FAF8F5] border border-[#D7D1C8] rounded-xl p-3">
                        <div class="flex items-center justify-between mb-1">
                            <span class="block text-[10px] font-bold text-[#5A5150] uppercase tracking-wider">
                                Kondisi yang Ditemukan
                            </span>
                            <button
                                v-if="form.observed && form.observed.length > 180"
                                type="button"
                                class="text-[10.5px] font-semibold text-[#245F59] hover:underline cursor-pointer"
                                @click="isObservedExpanded = !isObservedExpanded"
                            >
                                {{ isObservedExpanded ? "Ringkas" : "Lihat selengkapnya" }}
                            </button>
                        </div>
                        <div
                            class="text-xs text-[#302B2A] leading-relaxed transition-all"
                            :class="[
                                !isObservedExpanded && form.observed && form.observed.length > 180
                                    ? 'line-clamp-3 overflow-hidden font-medium'
                                    : 'max-h-40 overflow-y-auto font-medium pr-1 custom-scroll'
                            ]"
                        >
                            "{{ form.observed }}"
                        </div>
                    </div>

                    <!-- Recommendation / Saran Revisi -->
                    <div v-if="form.recommendation" class="bg-[#eef7f5] border border-[#bce1da] rounded-xl p-3">
                        <span class="block text-[10px] font-bold text-[#245750] uppercase tracking-wider mb-1">
                            Saran Perbaikan
                        </span>
                        <p class="text-xs text-[#1e4640] leading-relaxed">
                            {{ form.recommendation }}
                        </p>
                    </div>

                    <!-- Compact Document Evidence Chips -->
                    <div v-if="finding.evidences && finding.evidences.length > 0" class="bg-white border border-[#D7D1C8] rounded-xl p-3">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px] font-bold text-[#5A5150] uppercase tracking-wider">
                                    Bukti Halaman
                                </span>
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-[#E8E2D5] text-[#302B2A]">
                                    {{ finding.evidences.length }} lokasi
                                </span>
                            </div>
                            <button
                                v-if="finding.evidences.some(e => e.quote)"
                                type="button"
                                class="text-[10.5px] text-[#245F59] font-semibold hover:underline flex items-center gap-0.5 cursor-pointer"
                                @click="showAllQuotes = !showAllQuotes"
                            >
                                <span>{{ showAllQuotes ? "Sembunyikan kutipan" : "Lihat kutipan" }}</span>
                                <ChevronUp v-if="showAllQuotes" :size="12" />
                                <ChevronDown v-else :size="12" />
                            </button>
                        </div>

                        <!-- Quick Search if > 12 evidences -->
                        <div v-if="finding.evidences.length > 12" class="mb-2">
                            <input
                                v-model="evidenceSearchQuery"
                                type="text"
                                placeholder="Cari nomor halaman bukti..."
                                class="w-full text-[11px] px-2.5 py-1 border border-[#D7D1C8] rounded-md bg-[#FAF8F5] focus:bg-white focus:outline-none focus:border-[#245F59]"
                            />
                        </div>

                        <!-- Clickable Page Chips (contained in scrollable box) -->
                        <div class="evidence-chips-container custom-scroll">
                            <button
                                v-for="(e, i) in filteredEvidences"
                                :key="i"
                                type="button"
                                class="evidence-chip"
                                :title="e.quote ? `“${e.quote}”` : 'Klik untuk melihat halaman di viewer'"
                                @click="e.physical_page && emit('evidence', e.physical_page)"
                            >
                                <span class="font-bold">
                                    {{ e.physical_page ? `PDF hlm. ${e.physical_page}` : (e.source_url ? 'Rujukan' : 'Bukti') }}
                                </span>
                                <span v-if="e.printed_page" class="text-[10px] opacity-75">
                                    ({{ e.printed_page }})
                                </span>
                                <ArrowUpRight :size="12" />
                            </button>
                            <span v-if="filteredEvidences.length === 0" class="text-[11px] text-gray-500 italic p-1">
                                Halaman tidak ditemukan.
                            </span>
                        </div>

                        <small v-if="finding.evidences.length > 10" class="text-[10px] text-gray-500 block mt-1.5 leading-tight">
                            Kotak di atas dapat di-scroll untuk melihat semua bukti tanpa memenuhi layar.
                        </small>

                        <!-- Expandable Quotes Drawer with max-height -->
                        <div v-if="showAllQuotes" class="mt-2.5 pt-2 border-t border-[#E5E0D8] space-y-2 max-h-48 overflow-y-auto pr-1 custom-scroll">
                            <div
                                v-for="(e, i) in filteredEvidences"
                                :key="i"
                                class="text-[11px] p-2 bg-[#FAF8F5] rounded-lg border border-[#E5E0D8]"
                            >
                                <strong class="text-[#245F59] block mb-0.5">
                                    {{ e.physical_page ? `PDF hlm. ${e.physical_page}` : 'Rujukan' }}:
                                </strong>
                                <span v-if="e.quote" class="italic text-gray-700">“{{ e.quote }}”</span>
                                <span v-else class="text-gray-500">Area bukti ditandai visual pada halaman.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB CONTENT B: EDIT REDAKSI (FULL EDIT MODE) -->
                <div v-show="activeTab === 'edit'" class="mt-2.5 bg-white border border-[#D7D1C8] rounded-xl p-3 space-y-3">
                    <div>
                        <label for="finding-rule" class="form-label">Aturan</label>
                        <select id="finding-rule" v-model="form.rule_code" class="form-select">
                            <option v-for="(text, code) in rules" :key="code" :value="code">
                                {{ text }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label for="severity" class="form-label">Jenis Temuan</label>
                        <select id="severity" v-model="form.severity" class="form-select">
                            <option value="wajib">Wajib diperbaiki</option>
                            <option value="saran">Saran perbaikan</option>
                            <option value="verifikasi">Perlu verifikasi</option>
                        </select>
                    </div>

                    <div>
                        <label for="observed" class="form-label">Kondisi yang Ditemukan</label>
                        <textarea
                            id="observed"
                            v-model="form.observed"
                            rows="2"
                            required
                            maxlength="5000"
                            class="form-textarea"
                        />
                    </div>

                    <div>
                        <label for="expected" class="form-label">Ketentuan yang Seharusnya</label>
                        <textarea
                            id="expected"
                            v-model="form.expected"
                            rows="2"
                            required
                            maxlength="5000"
                            class="form-textarea"
                        />
                    </div>

                    <div>
                        <label for="recommendation" class="form-label">Saran Revisi</label>
                        <textarea
                            id="recommendation"
                            v-model="form.recommendation"
                            rows="3"
                            required
                            maxlength="5000"
                            class="form-textarea"
                        />
                    </div>

                    <div class="pt-2 border-t border-[#E5E0D8]">
                        <label class="checkbox-label">
                            <input type="checkbox" v-model="form.deleted" class="mr-2" />
                            <span>Arsipkan temuan ini (riwayat tetap disimpan)</span>
                        </label>
                    </div>

                    <button
                        v-if="canEdit"
                        type="submit"
                        class="button primary full-width mt-2"
                        :disabled="form.processing"
                    >
                        <Save :size="15" />
                        <span>{{ form.processing ? "Menyimpan..." : "Simpan Perubahan Redaksi" }}</span>
                    </button>
                </div>

                <!-- TAB CONTENT C: KOREKSI BUKTI (MANAGE EVIDENCES) -->
                <div v-show="activeTab === 'evidence'" class="mt-2.5 bg-white border border-[#D7D1C8] rounded-xl p-3 space-y-3">
                    <div class="flex items-center justify-between mb-2">
                        <span class="block text-[11px] font-bold text-[#5A5150] uppercase tracking-wider">
                            Daftar Bukti Dokumen ({{ form.evidences?.length ?? 0 }})
                        </span>
                        <button
                            type="button"
                            class="button secondary text-[11px] py-1 px-2.5"
                            :disabled="(form.evidences?.length ?? 0) >= 500"
                            @click="form.evidences?.push({ physical_page: page, quote: '', bbox: null })"
                        >
                            + Tambah
                        </button>
                    </div>

                    <div class="evidence-edit-list max-h-80 overflow-y-auto pr-1 space-y-2.5 custom-scroll">
                        <div v-for="(e, i) in form.evidences" :key="i" class="p-2.5 bg-[#FAF8F5] border border-[#D7D1C8] rounded-lg space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-[#302B2A]">Bukti #{{ i + 1 }}</span>
                                <button
                                    v-if="(form.evidences?.length ?? 0) > 1"
                                    type="button"
                                    class="text-xs text-red-600 hover:underline cursor-pointer"
                                    @click="form.evidences?.splice(i, 1)"
                                >
                                    Hapus Bukti
                                </button>
                            </div>

                            <div class="grid grid-cols-3 gap-2 items-center">
                                <div class="col-span-1">
                                    <label :for="`evidence-page-${i}`" class="text-[10px] text-gray-500 block mb-0.5">Hal. PDF</label>
                                    <input
                                        :id="`evidence-page-${i}`"
                                        type="number"
                                        min="1"
                                        :max="publication.page_count"
                                        v-model="e.physical_page"
                                        required
                                        class="form-input text-xs p-1.5"
                                        @change="e.bbox = null; e.printed_page = null;"
                                    />
                                </div>
                                <div class="col-span-2">
                                    <label :for="`evidence-quote-${i}`" class="text-[10px] text-gray-500 block mb-0.5">Kutipan</label>
                                    <textarea
                                        :id="`evidence-quote-${i}`"
                                        v-model="e.quote"
                                        rows="2"
                                        :required="!e.bbox"
                                        maxlength="3000"
                                        class="form-textarea text-xs p-1.5"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2 border-t border-[#E5E0D8]">
                        <button
                            type="button"
                            class="button secondary text-xs"
                            :disabled="(form.evidences?.length ?? 0) >= 500"
                            @click="form.evidences?.push({ physical_page: page, quote: '', bbox: null })"
                        >
                            + Tambah Bukti
                        </button>

                        <button
                            v-if="canEdit"
                            type="submit"
                            class="button primary text-xs"
                            :disabled="form.processing"
                        >
                            <Save :size="14" />
                            <span>Simpan Bukti</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- MODE 2: TAMBAHKAN TEMUAN BARU (CREATE FORM)               -->
        <!-- ========================================================= -->
        <fieldset v-else :disabled="!canEdit || form.processing" class="space-y-3 mt-3">
            <div>
                <label for="finding-rule-new" class="form-label">Aturan</label>
                <select id="finding-rule-new" v-model="form.rule_code" class="form-select">
                    <option v-for="(text, code) in rules" :key="code" :value="code">
                        {{ text }}
                    </option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="severity-new" class="form-label">Jenis Temuan</label>
                    <select id="severity-new" v-model="form.severity" class="form-select">
                        <option value="wajib">Wajib diperbaiki</option>
                        <option value="saran">Saran perbaikan</option>
                        <option value="verifikasi">Perlu verifikasi</option>
                    </select>
                </div>
                <div>
                    <label for="manual-page" class="form-label">Halaman PDF</label>
                    <input
                        id="manual-page"
                        type="number"
                        min="1"
                        :max="publication.page_count"
                        v-model="form.page"
                        required
                        class="form-input"
                    />
                </div>
            </div>

            <div>
                <label for="manual-quote" class="form-label">Kutipan Bukti pada Halaman</label>
                <textarea
                    id="manual-quote"
                    v-model="form.quote"
                    rows="2"
                    required
                    maxlength="3000"
                    class="form-textarea"
                    placeholder="Kutipan teks yang bermasalah..."
                />
            </div>

            <div>
                <label for="observed-new" class="form-label">Kondisi yang Ditemukan</label>
                <textarea
                    id="observed-new"
                    v-model="form.observed"
                    rows="2"
                    required
                    maxlength="5000"
                    class="form-textarea"
                />
            </div>

            <div>
                <label for="expected-new" class="form-label">Ketentuan yang Seharusnya</label>
                <textarea
                    id="expected-new"
                    v-model="form.expected"
                    rows="2"
                    required
                    maxlength="5000"
                    class="form-textarea"
                />
            </div>

            <div>
                <label for="recommendation-new" class="form-label">Saran Revisi</label>
                <textarea
                    id="recommendation-new"
                    v-model="form.recommendation"
                    rows="3"
                    required
                    maxlength="5000"
                    class="form-textarea"
                />
            </div>

            <div>
                <label for="review-status-new" class="form-label">Keputusan Awal</label>
                <select id="review-status-new" v-model="form.review_status" class="form-select">
                    <option value="unreviewed">Belum ditinjau</option>
                    <option value="accepted">Diterima</option>
                    <option value="rejected">Ditolak</option>
                    <option value="needs_verification">Perlu verifikasi</option>
                </select>
            </div>

            <button
                v-if="canEdit"
                class="button primary full-width mt-3"
                :disabled="form.processing"
            >
                <Save :size="16" />
                <span>{{ form.processing ? "Menyimpan..." : "Simpan Temuan Baru" }}</span>
            </button>
        </fieldset>

        <!-- Readonly Warning -->
        <p v-if="!canEdit" class="notice neutral mt-3">
            Hasil hanya dapat dibaca. Hubungi pemeriksa atau reviewer untuk perubahan.
        </p>

        <!-- History Drawer -->
        <div v-if="historyOpen" class="history-list mt-3 p-3 bg-white border border-[#D7D1C8] rounded-xl text-xs space-y-2">
            <h4 class="font-bold text-[#302B2A]">Riwayat Perubahan Temuan</h4>
            <p v-if="historyLoading" class="text-gray-500">Memuat riwayat...</p>
            <p v-if="historyError" role="alert" class="text-red-600">{{ historyError }}</p>
            <p v-if="!history.length && !historyLoading" class="text-gray-500">
                Belum ada penyuntingan manual pada temuan ini.
            </p>
            <article v-for="item in history" :key="item.id" class="p-2 bg-[#FAF8F5] border border-[#E5E0D8] rounded-lg">
                <small class="text-gray-500 block mb-0.5">
                    {{ new Date(item.created_at).toLocaleString("id-ID") }}
                </small>
                <p class="font-semibold text-gray-800">{{ item.reason }}</p>
                <details class="mt-1">
                    <summary class="cursor-pointer text-[#245F59] font-medium">Lihat detail perubahan</summary>
                    <div class="mt-1 space-y-1 text-[11px] font-mono text-gray-600">
                        <div v-for="key in ['observed', 'expected', 'recommendation', 'review_status', 'review_note', 'evidences']" :key="key">
                            <span class="font-bold">{{ key }}:</span>
                            <span> Sebelum: {{ item.before[key] ?? "—" }} &rarr; Sesudah: {{ item.after[key] ?? "—" }}</span>
                        </div>
                    </div>
                </details>
            </article>
        </div>
    </form>
</template>

<style scoped>
.finding-editor-compact {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 14px 16px;
    background: #FAF8F5;
}

.close-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    border: 1px solid #D7D1C8;
    background: #FFFFFF;
    color: #5A5150;
    cursor: pointer;
    transition: all 0.15s;
}
.close-btn:hover {
    background: #FEE2E2;
    border-color: #EF4444;
    color: #B91C1C;
}

.history-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    color: #5A5150;
    background: #FFFFFF;
    border: 1px solid #D7D1C8;
    padding: 4px 10px;
    border-radius: 999px;
    cursor: pointer;
    transition: all 0.15s;
}
.history-btn:hover {
    color: #302B2A;
    border-color: #302B2A;
}

.severity-pill {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 2px 8px;
    border-radius: 999px;
}
.severity-pill.wajib {
    background: #FFBDBD;
    color: #713E37;
    border: 1px solid #E88D8D;
}
.severity-pill.saran {
    background: #BADFDB;
    color: #245F59;
    border: 1px solid #96C9C3;
}
.severity-pill.verifikasi {
    background: #F4E2BD;
    color: #68521F;
    border: 1px solid #DCC495;
}

.agreement-banner {
    font-size: 11px;
    font-weight: 600;
    padding: 6px 10px;
    border-radius: 8px;
}
.agreement-banner.confirmed {
    background: #DCFCE7;
    color: #15803D;
    border: 1px solid #86EFAC;
}
.agreement-banner.single-agent {
    background: #FEF3C7;
    color: #B45309;
    border: 1px solid #FDE68A;
}

/* Quick Decision Pill Buttons */
.decision-pill-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 600;
    padding: 7px 10px;
    border-radius: 10px;
    border: 1px solid #D7D1C8;
    background: #FAF8F5;
    color: #5A5150;
    cursor: pointer;
    transition: all 0.15s;
    user-select: none;
}
.decision-pill-btn:hover:not(:disabled) {
    background: #FFFFFF;
    border-color: #302B2A;
    color: #302B2A;
}

.decision-pill-btn.accept.active {
    background: #DCFCE7;
    border-color: #10B981;
    color: #047857;
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(16, 185, 129, 0.2);
}

.decision-pill-btn.reject.active {
    background: #FEE2E2;
    border-color: #EF4444;
    color: #B91C1C;
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(239, 68, 68, 0.2);
}

.decision-pill-btn.verify.active {
    background: #FEF3C7;
    border-color: #F59E0B;
    color: #B45309;
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(245, 158, 11, 0.2);
}

.decision-pill-btn.unreviewed.active {
    background: #F3F4F6;
    border-color: #6B7280;
    color: #1F2937;
    font-weight: 700;
}

/* Quick Save Button */
.save-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 700;
    padding: 7px 14px;
    border-radius: 999px;
    background: #245F59;
    color: #FFFFFF;
    border: 1px solid #245F59;
    cursor: pointer;
    transition: all 0.15s;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.save-pill-btn:hover:not(:disabled) {
    background: #1E4F4A;
}
.save-pill-btn.is-dirty {
    background: #C15C3D;
    border-color: #A3482C;
    animation: pulseSave 2s infinite;
}

@keyframes pulseSave {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.02); }
}

/* Segmented Tab Bar */
.tab-pill-bar {
    background: #E8E2D5;
}
.tab-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    padding: 6px 8px;
    border-radius: 8px;
    border: 0;
    background: transparent;
    color: #5A5150;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.tab-btn:hover {
    color: #302B2A;
}
.tab-btn.active {
    background: #FFFFFF;
    color: #302B2A;
    font-weight: 700;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}

/* Evidence Chips */
.evidence-chips-container {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-height: 130px;
    overflow-y: auto;
    padding: 6px;
    border: 1px solid #E5E0D8;
    border-radius: 8px;
    background: #FAF8F5;
}
.evidence-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    color: #245F59;
    background: #EEF7F5;
    border: 1px solid #BCE1DA;
    padding: 4px 9px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s;
}
.evidence-chip:hover {
    background: #245F59;
    color: #FFFFFF;
    border-color: #245F59;
}

/* Form inputs styling */
.form-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #302B2A;
    margin-bottom: 4px;
}
.form-select, .form-input, .form-textarea {
    width: 100%;
    font-size: 12px;
    padding: 6px 9px;
    border: 1px solid #D7D1C8;
    border-radius: 8px;
    background: #FFFFFF;
    color: #302B2A;
    outline: none;
    transition: border-color 0.15s;
}
.form-select:focus, .form-input:focus, .form-textarea:focus {
    border-color: #245F59;
    box-shadow: 0 0 0 1px #245F59;
}
.checkbox-label {
    display: flex;
    align-items: center;
    font-size: 11.5px;
    color: #302B2A;
    cursor: pointer;
}

.custom-scroll::-webkit-scrollbar {
    width: 5px;
    height: 5px;
}
.custom-scroll::-webkit-scrollbar-track {
    background: #F1EDDF;
    border-radius: 4px;
}
.custom-scroll::-webkit-scrollbar-thumb {
    background: #C4BCB0;
    border-radius: 4px;
}
.custom-scroll::-webkit-scrollbar-thumb:hover {
    background: #245F59;
}
</style>
