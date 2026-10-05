<script setup lang="ts">
import { useForm, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { Save, History, ArrowUpRight } from "lucide-vue-next";
import type { Finding, Publication } from "../types";
const props = defineProps<{
    finding: Finding | null;
    publication: Publication;
    rules: Record<string, string>;
    canEdit: boolean;
    page: number;
}>();
const emit = defineEmits<{ saved: []; evidence: [number] }>();
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
});
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
function save() {
    const url = `/publications/${props.publication.id}/findings`;
    const options = { preserveScroll: true, onSuccess: () => emit("saved") };
    props.finding
        ? form.patch(`${url}/${props.finding.id}`, options)
        : form.post(url, options);
}
function reloadConflict() {
    if (
        window.confirm(
            "Muat versi terbaru? Salin dahulu perubahan Anda yang belum disimpan.",
        )
    )
        router.reload({ only: ["findings", "publication"] });
}
async function loadHistory() {
    historyOpen.value = !historyOpen.value;
    if (!historyOpen.value || !props.finding) return;
    historyLoading.value = true;
    try {
        const response = await fetch(
            `/publications/${props.publication.id}/findings/${props.finding.id}/history`,
            { headers: { Accept: "application/json" } },
        );
        if (!response.ok) throw new Error();
        history.value = await response.json();
    } catch {
        historyError.value = "Riwayat belum bisa dimuat. Coba kembali.";
    } finally {
        historyLoading.value = false;
    }
}
defineExpose({ dirty: () => form.isDirty });
</script>
<template>
    <form class="finding-editor" @submit.prevent="save">
        <div class="section-heading">
            <h3>{{ finding ? "Detail temuan" : "Tambahkan temuan" }}</h3>
            <button
                v-if="finding"
                type="button"
                class="icon-button"
                @click="loadHistory"
                :aria-expanded="historyOpen"
                aria-label="Lihat riwayat perubahan"
            >
                <History :size="18" />
            </button>
        </div>
        <div v-if="finding" class="evidence-list">
            <span class="eyebrow">Bukti pada dokumen</span
            ><button
                v-for="(e, i) in finding.evidences"
                :key="i"
                type="button"
                class="evidence"
                @click="emit('evidence', e.physical_page)"
            >
                <strong
                    >PDF hlm. {{ e.physical_page }}
                    <span v-if="e.printed_page"
                        >· tercetak {{ e.printed_page }}</span
                    ><ArrowUpRight :size="15" /></strong
                ><span v-if="e.quote">“{{ e.quote }}”</span
                ><span v-else>Area bukti ditandai pada gambar.</span></button
            ><small
                >{{
                    finding.source === "manual"
                        ? "Dicatat pemeriksa"
                        : finding.source === "ai"
                          ? "Dibaca AI"
                          : finding.source
                }}
                · Keyakinan {{ Math.round(finding.confidence * 100) }}%</small
            >
        </div>
        <div v-if="form.errors.version" class="notice error" role="alert">
            {{ form.errors.version }}
            <button
                type="button"
                class="button secondary"
                @click="reloadConflict"
            >
                Muat ulang temuan
            </button>
        </div>
        <fieldset :disabled="!canEdit || form.processing">
            <label for="finding-rule">Aturan</label
            ><select id="finding-rule" v-model="form.rule_code">
                <option v-for="(text, code) in rules" :key="code" :value="code">
                    {{ text }}
                </option></select
            ><label for="severity">Jenis temuan</label
            ><select id="severity" v-model="form.severity">
                <option value="wajib">Wajib diperbaiki</option>
                <option value="saran">Saran perbaikan</option>
                <option value="verifikasi">Perlu verifikasi</option></select
            ><label for="observed">Kondisi yang ditemukan</label
            ><textarea
                id="observed"
                v-model="form.observed"
                rows="3"
                required
                maxlength="5000"
            /><label for="expected">Ketentuan yang seharusnya</label
            ><textarea
                id="expected"
                v-model="form.expected"
                rows="3"
                required
                maxlength="5000"
            /><label for="recommendation">Saran revisi</label
            ><textarea
                id="recommendation"
                v-model="form.recommendation"
                rows="4"
                required
                maxlength="5000"
            /><template v-if="!finding"
                ><label for="manual-page">Halaman PDF</label
                ><input
                    id="manual-page"
                    type="number"
                    min="1"
                    :max="publication.page_count"
                    v-model="form.page"
                    required
                /><label for="manual-quote">Kutipan bukti</label
                ><textarea
                    id="manual-quote"
                    v-model="form.quote"
                    rows="2"
                    required
                    maxlength="3000"
                /></template
            ><label for="review-status">Keputusan pemeriksa</label
            ><select id="review-status" v-model="form.review_status">
                <option value="unreviewed">Belum ditinjau</option>
                <option value="accepted">Diterima</option>
                <option value="rejected">Ditolak</option>
                <option value="needs_verification">
                    Perlu verifikasi
                </option></select
            ><label for="review-note">Catatan / alasan keputusan</label
            ><textarea
                id="review-note"
                v-model="form.review_note"
                rows="2"
                maxlength="3000"
            /><label v-if="finding" class="checkbox"
                ><input type="checkbox" v-model="form.deleted" />Arsipkan temuan
                ini (riwayat tetap disimpan)</label
            >
            <p v-if="form.isDirty" class="small unsaved" role="status">
                Ada perubahan yang belum disimpan.
            </p>
            <button
                v-if="canEdit"
                class="button primary full-width"
                :disabled="form.processing"
            >
                <Save :size="17" />{{
                    form.processing ? "Menyimpan..." : "Simpan temuan"
                }}
            </button>
        </fieldset>
        <p v-if="!canEdit" class="notice neutral">
            Hasil hanya dapat dibaca. Hubungi pemeriksa atau reviewer untuk
            perubahan.
        </p>
        <div v-if="historyOpen" class="history-list">
            <h4>Riwayat perubahan</h4>
            <p v-if="historyLoading">Memuat riwayat...</p>
            <p v-if="historyError" role="alert">{{ historyError }}</p>
            <p v-if="!history.length && !historyLoading">
                Belum ada penyuntingan manual.
            </p>
            <article v-for="item in history" :key="item.id">
                <small>{{
                    new Date(item.created_at).toLocaleString("id-ID")
                }}</small>
                <p>{{ item.reason }}</p>
                <details>
                    <summary>Lihat perubahan</summary>
                    <p
                        v-for="key in [
                            'observed',
                            'expected',
                            'recommendation',
                            'review_status',
                            'review_note',
                        ]"
                        :key="key"
                    >
                        <strong>{{ key }}</strong
                        ><br />Sebelum: {{ item.before[key] ?? "—"
                        }}<br />Sesudah: {{ item.after[key] ?? "—" }}
                    </p>
                </details>
            </article>
        </div>
    </form>
</template>
