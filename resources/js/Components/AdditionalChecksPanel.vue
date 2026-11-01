<script setup lang="ts">
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import {
    Sparkles,
    RotateCcw,
    AlertTriangle,
    CheckCircle2,
    Clock,
    XCircle,
    Layers,
    BookOpen,
    Eye,
    Printer
} from "lucide-vue-next";
import CoverTitleComparison from "./CoverTitleComparison.vue";
import type { CoverageSummary, CheckRunRecord, CheckItemRecord } from "../types";

const props = defineProps<{
    publicationId: string;
    canEdit: boolean;
    coverage?: CoverageSummary | null;
    checkRuns?: CheckRunRecord[] | null;
}>();

const showStartDialog = ref(false);
const showPairModal = ref(false);
const showManualVolumeInput = ref(false);
const manualPriorVolume = ref<string>("");
const busy = ref(false);

const selectedScope = ref<string[]>(["volume", "cover_title", "print_layout"]);

const latestRun = computed(() => props.checkRuns?.[0] ?? null);
const checks = computed<CheckItemRecord[]>(() => (latestRun.value as any)?.checks ?? []);
const latestReference = computed(() => {
    const refs = (latestRun.value as any)?.references ?? [];
    return refs.length > 0 ? refs[refs.length - 1] : null;
});

const volumeResult = computed(() => getCheckRecord("volume")?.result ?? null);

const hasCoverArtifact = computed(() => {
    const manifest = latestReference.value?.artifact_manifest ?? [];
    return Array.isArray(manifest) && manifest.some((a: any) => a.artifact_type === "cover_image" && a.path);
});

const coverArtifactUrl = computed(() => {
    if (!latestReference.value || !hasCoverArtifact.value) return null;
    return `/publications/${props.publicationId}/references/${latestReference.value.id}/artifacts/cover_image`;
});

function submitManualVolume() {
    const parsed = parseInt(String(manualPriorVolume.value), 10);
    if (!parsed || parsed < 1 || parsed > 500) return;
    busy.value = true;
    router.post(
        `/publications/${props.publicationId}/checks/volume-manual`,
        {
            prior_volume: parsed,
            prior_year: volumeResult.value?.prior_year ?? latestReference.value?.year ?? undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showManualVolumeInput.value = false;
            },
            onFinish: () => {
                busy.value = false;
            },
        }
    );
}

const checkTypes = [
    {
        key: "volume",
        label: "Verifikasi Volume Edisi Sebelumnya",
        desc: "Mengecek nomor volume terhadap publikasi resmi tahun sebelumnya",
        icon: BookOpen,
    },
    {
        key: "cover_title",
        label: "Pembanding Kover & Halaman Judul",
        desc: "Mengevaluasi keselarasan visual, logo BPS, dan latar putih",
        icon: Eye,
    },
    {
        key: "print_layout",
        label: "Layout Cetak & Halaman Blank",
        desc: "Memvalidasi genap romawi, trailing blank cetak, dan hitungan katalog",
        icon: Printer,
    },
];

function startChecks() {
    if (selectedScope.value.length === 0) return;
    busy.value = true;
    router.post(
        `/publications/${props.publicationId}/checks`,
        { scope: selectedScope.value },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                showStartDialog.value = false;
            },
        }
    );
}

function retryCheck(checkId: number) {
    busy.value = true;
    router.post(
        `/publications/${props.publicationId}/checks/${checkId}/retry`,
        {},
        {
            preserveScroll: true,
            onFinish: () => (busy.value = false),
        }
    );
}

function getCheckRecord(type: string) {
    return checks.value.find((c) => c.check_type === type);
}

function statusBadge(status?: string, decision?: string | null) {
    if (status === "running" || status === "pending") return { label: "Sedang berjalan...", class: "status-running", icon: Clock };
    if (status === "retry_wait") return { label: "Menunggu retry", class: "status-retry", icon: Clock };
    if (status === "failed") return { label: "Gagal dijalankan", class: "status-danger", icon: XCircle };
    if (decision === "conform") return { label: "Sesuai Pedoman", class: "status-success", icon: CheckCircle2 };
    if (decision === "needs_verification") return { label: "Perlu verifikasi", class: "status-warning", icon: AlertTriangle };
    if (decision === "nonconform") return { label: "Ada Temuan (Tidak Sesuai)", class: "status-danger", icon: AlertTriangle };
    return { label: "Belum diperiksa", class: "status-pending", icon: Clock };
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === "Escape") {
        showStartDialog.value = false;
        showPairModal.value = false;
    }
}
</script>

<template>
    <section
        class="additional-checks-panel"
        aria-label="Pemeriksaan tambahan aturan V2"
        @keydown="handleKeydown"
    >
        <div class="panel-header">
            <div class="header-titles">
                <div class="header-icon-title">
                    <Sparkles class="sparkle-icon" :size="20" />
                    <h2>Pemeriksaan Tambahan Aturan Terbaru (V2)</h2>
                </div>
                <p class="header-subtitle">
                    Pemeriksaan aturan terbaru (V2) otomatis terintegrasi dan berjalan saat audit final, mencakup verifikasi volume edisi sebelumnya, keselarasan visual kover-judul, dan tata letak cetak.
                </p>
            </div>

            <div class="header-actions">
                <button
                    v-if="canEdit"
                    type="button"
                    class="button primary start-checks-button"
                    :disabled="busy"
                    @click="showStartDialog = true"
                >
                    <Sparkles :size="16" />
                    <span>Jalankan / Ulangi Pemeriksaan V2</span>
                </button>
            </div>
        </div>

        <!-- Combined Coverage Overview -->
        <div class="combined-coverage-card">
            <div class="coverage-header">
                <Layers :size="18" />
                <h3>Cakupan gabungan</h3>
            </div>
            <div class="coverage-stats-row">
                <div class="stat-box">
                    <span class="stat-label">Pemeriksaan Halaman Dokumen</span>
                    <strong class="stat-value">
                        {{ coverage?.page_coverage?.checked_pages ?? 0 }} / {{ coverage?.page_coverage?.total_pages ?? 0 }} hlm
                    </strong>
                    <small class="stat-status">
                        {{ coverage?.page_coverage?.complete ? 'Selesai' : 'Belum lengkap' }}
                    </small>
                </div>

                <div class="stat-box">
                    <span class="stat-label">Pemeriksaan Silang & Layout</span>
                    <strong class="stat-value">
                        {{ coverage?.base_crosscheck?.complete ? 'Selesai' : 'Menunggu' }}
                    </strong>
                    <small class="stat-status">
                        {{ coverage?.base_crosscheck?.status ?? 'pending' }}
                    </small>
                </div>

                <div class="stat-box">
                    <span class="stat-label">Status Laporan Akhir</span>
                    <strong class="stat-value report-mode-badge" :class="coverage?.report_mode ?? 'partial'">
                        {{
                            coverage?.report_mode === 'complete'
                                ? 'Lengkap'
                                : coverage?.report_mode === 'complete_with_limitations'
                                ? 'Lengkap dengan Keterbatasan'
                                : 'Sebagian / Belum Lengkap'
                        }}
                    </strong>
                    <small class="stat-status">
                        {{ coverage?.can_lock ? 'Siap dikunci' : 'Belum dapat dikunci' }}
                    </small>
                </div>
            </div>

            <!-- Limitations / Alerts -->
            <div
                v-if="coverage?.limitations && coverage.limitations.length > 0"
                class="limitations-alert"
                role="alert"
            >
                <div class="alert-icon-title">
                    <AlertTriangle :size="18" />
                    <strong>Catatan Keterbatasan Pemeriksaan</strong>
                </div>
                <ul>
                    <li v-for="(limit, idx) in coverage.limitations" :key="idx">
                        {{ limit }}
                    </li>
                </ul>
                <p class="manual-review-note">
                    Hasil Excel tetap dapat dibuat. Butir di atas memerlukan verifikasi manual oleh pemeriksa.
                </p>
            </div>
        </div>

        <!-- Checklist Grid -->
        <div class="checks-grid">
            <div
                v-for="ct in checkTypes"
                :key="ct.key"
                class="check-card"
            >
                <div class="check-card-top">
                    <div class="check-icon-title">
                        <component :is="ct.icon" :size="20" />
                        <div>
                            <h4>{{ ct.label }}</h4>
                            <p class="check-desc">{{ ct.desc }}</p>
                        </div>
                    </div>
                    <span
                        v-if="getCheckRecord(ct.key)"
                        class="status-pill"
                        :class="statusBadge(getCheckRecord(ct.key)?.execution_status, getCheckRecord(ct.key)?.decision).class"
                    >
                        <component
                            :is="statusBadge(getCheckRecord(ct.key)?.execution_status, getCheckRecord(ct.key)?.decision).icon"
                            :size="14"
                        />
                        {{ statusBadge(getCheckRecord(ct.key)?.execution_status, getCheckRecord(ct.key)?.decision).label }}
                    </span>
                    <span v-else class="status-pill status-pending">
                        <Clock :size="14" />
                        Belum dijalankan
                    </span>
                </div>

                <div v-if="getCheckRecord(ct.key)?.error_message" class="check-error-box">
                    <small>{{ getCheckRecord(ct.key)?.error_message }}</small>
                </div>

                <!-- Volume Verification Rich Details & 1-Click Quick Verify -->
                <div v-if="ct.key === 'volume' && getCheckRecord('volume')" class="volume-details-section">
                    <div
                        v-if="volumeResult?.reference_meta?.title || latestReference?.metadata?.title || volumeResult?.prior_volume"
                        class="volume-reference-card"
                    >
                        <img
                            v-if="coverArtifactUrl"
                            :src="coverArtifactUrl"
                            alt="Sampul Edisi Sebelumnya"
                            class="volume-ref-thumb"
                        />
                        <div class="volume-ref-info">
                            <div class="volume-ref-badges">
                                <span class="ref-source-badge">
                                    {{
                                        latestReference?.provenance === 'bps_webapi'
                                            ? 'BPS WebAPI Resmi'
                                            : latestReference?.provenance === 'local_registry'
                                            ? 'Memori Rujukan'
                                            : latestReference?.provenance === 'manual_verified'
                                            ? 'Verifikasi Manual'
                                            : 'Rujukan Eksternal'
                                    }}
                                </span>
                                <span v-if="volumeResult?.reference_meta?.catalogue_number || latestReference?.metadata?.catalogue_number" class="ref-cat-badge">
                                    Katalog: {{ volumeResult?.reference_meta?.catalogue_number || latestReference?.metadata?.catalogue_number }}
                                </span>
                            </div>
                            <strong class="volume-ref-title">
                                {{ volumeResult?.reference_meta?.title || latestReference?.metadata?.title || `Edisi Tahun ${volumeResult?.prior_year || ''}` }}
                            </strong>
                            <div v-if="volumeResult?.prior_volume && volumeResult?.expected_volume" class="volume-progression-pill">
                                <span>Edisi {{ volumeResult.prior_year }}: <strong>Volume {{ volumeResult.prior_volume }}</strong></span>
                                <span class="prog-arrow">&rarr;</span>
                                <span>Target Edisi Ini: <strong>Volume {{ volumeResult.expected_volume }}</strong></span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="volumeResult?.components && Object.keys(volumeResult.components).length > 0"
                        class="volume-components-grid"
                    >
                        <div
                            v-for="(comp, partKey) in volumeResult.components"
                            :key="partKey"
                            class="volume-comp-chip"
                            :class="{
                                'comp-match': comp.match === true,
                                'comp-mismatch': comp.match === false,
                                'comp-neutral': comp.match === null
                            }"
                        >
                            <div class="comp-top">
                                <span class="comp-label">{{ comp.label }}</span>
                                <span v-if="comp.page" class="comp-page">hlm. {{ comp.page }}</span>
                            </div>
                            <strong class="comp-val">
                                {{ comp.observed !== null && comp.observed !== undefined ? `Volume ${comp.observed}` : 'Belum tertulis' }}
                            </strong>
                        </div>
                    </div>

                    <!-- Quick Verify / Override Form -->
                    <div
                        v-if="canEdit && (getCheckRecord('volume')?.decision === 'needs_verification' || showManualVolumeInput)"
                        class="volume-quick-verify-box"
                    >
                        <label class="quick-verify-label">
                            Konfirmasi Cepat Volume Edisi Sebelumnya ({{ volumeResult?.prior_year || 'Tahun Lalu' }}):
                        </label>
                        <div class="quick-verify-row">
                            <input
                                v-model="manualPriorVolume"
                                type="number"
                                min="1"
                                max="500"
                                placeholder="Mis. 11"
                                class="quick-verify-input"
                                @keydown.enter.prevent="submitManualVolume"
                            />
                            <button
                                type="button"
                                class="button primary btn-sm"
                                :disabled="busy || !manualPriorVolume"
                                @click="submitManualVolume"
                            >
                                Simpan &amp; Verifikasi
                            </button>
                            <button
                                v-if="showManualVolumeInput && getCheckRecord('volume')?.decision !== 'needs_verification'"
                                type="button"
                                class="button secondary btn-sm"
                                @click="showManualVolumeInput = false"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </div>

                <div class="check-card-actions">
                    <button
                        v-if="ct.key === 'cover_title'"
                        type="button"
                        class="button secondary btn-sm"
                        @click="showPairModal = !showPairModal"
                    >
                        <Eye :size="14" />
                        <span>{{ showPairModal ? 'Tutup Perbandingan' : 'Lihat Pasangan Gambar' }}</span>
                    </button>

                    <button
                        v-if="ct.key === 'volume' && canEdit && getCheckRecord('volume') && getCheckRecord('volume')?.decision !== 'needs_verification' && !showManualVolumeInput"
                        type="button"
                        class="button secondary btn-sm"
                        @click="
                            manualPriorVolume = String(volumeResult?.prior_volume || '');
                            showManualVolumeInput = true;
                        "
                    >
                        <span>Koreksi Volume Rujukan</span>
                    </button>

                    <button
                        v-if="canEdit && getCheckRecord(ct.key) && ['failed', 'retry_wait', 'needs_verification'].includes(getCheckRecord(ct.key)?.execution_status || getCheckRecord(ct.key)?.decision || '')"
                        type="button"
                        class="button secondary btn-sm retry-btn"
                        :disabled="busy"
                        @click="retryCheck(getCheckRecord(ct.key)!.id)"
                    >
                        <RotateCcw :size="14" />
                        <span>Coba Ulang via BPS API</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Inline Pair Comparison if active -->
        <div v-if="showPairModal" class="pair-modal-container">
            <CoverTitleComparison
                :publication-id="publicationId"
                :cover-page="getCheckRecord('cover_title')?.result?.cover_page ?? 1"
                :title-page="getCheckRecord('cover_title')?.result?.title_page ?? 3"
                :result="getCheckRecord('cover_title')?.result"
            />
        </div>

        <!-- Start Dialog / Modal -->
        <div
            v-if="showStartDialog"
            class="modal-backdrop"
            role="dialog"
            aria-modal="true"
            aria-labelledby="dialog-title"
        >
            <div class="modal-card">
                <h3 id="dialog-title">Mulai Pemeriksaan Tambahan Aturan V2</h3>
                <p class="modal-subtitle">
                    Pilih cakupan aturan tambahan yang ingin dijalankan. Hasil pemeriksaan halaman yang sudah selesai tetap dipertahankan.
                </p>

                <fieldset class="scope-fieldset">
                    <legend class="sr-only">Pilihan cakupan</legend>
                    <label
                        v-for="ct in checkTypes"
                        :key="ct.key"
                        class="scope-checkbox-label"
                    >
                        <input
                            type="checkbox"
                            :value="ct.key"
                            v-model="selectedScope"
                        />
                        <div class="checkbox-info">
                            <strong>{{ ct.label }}</strong>
                            <small>{{ ct.desc }}</small>
                        </div>
                    </label>
                </fieldset>

                <div class="modal-actions">
                    <button
                        type="button"
                        class="button secondary"
                        :disabled="busy"
                        @click="showStartDialog = false"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        class="button primary"
                        :disabled="busy || selectedScope.length === 0"
                        @click="startChecks"
                    >
                        {{ busy ? "Memulai..." : "Mulai Pemeriksaan" }}
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.additional-checks-panel {
    margin-top: 1.5rem;
    padding: 1.5rem;
    background: var(--surface, #ffffff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 0.5rem;
}
.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.header-icon-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.header-icon-title h2 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 700;
}
.sparkle-icon {
    color: #2563eb;
}
.header-subtitle {
    margin: 0.25rem 0 0 0;
    color: #64748b;
    font-size: 0.875rem;
}
.combined-coverage-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}
.coverage-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    color: #334155;
}
.coverage-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}
.coverage-stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}
.stat-box {
    display: flex;
    flex-direction: column;
    padding: 0.75rem 1rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
}
.stat-label {
    font-size: 0.75rem;
    color: #64748b;
    margin-bottom: 0.25rem;
}
.stat-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
}
.stat-status {
    font-size: 0.75rem;
    color: #64748b;
    margin-top: 0.25rem;
}
.report-mode-badge {
    font-size: 0.95rem;
}
.report-mode-badge.complete { color: #166534; }
.report-mode-badge.complete_with_limitations { color: #854d0e; }
.report-mode-badge.partial { color: #991b1b; }

.limitations-alert {
    margin-top: 1rem;
    padding: 0.85rem 1rem;
    background: #fefce8;
    border: 1px solid #fde047;
    border-radius: 0.375rem;
    color: #713f12;
}
.alert-icon-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}
.limitations-alert ul {
    margin: 0;
    padding-left: 1.25rem;
    font-size: 0.875rem;
}
.manual-review-note {
    margin: 0.5rem 0 0 0;
    font-size: 0.8rem;
    color: #854d0e;
}

.checks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}
.check-card {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    background: #ffffff;
}
.check-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}
.check-icon-title {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}
.check-icon-title h4 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
}
.check-desc {
    margin: 0.2rem 0 0 0;
    font-size: 0.8rem;
    color: #64748b;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.2rem 0.5rem;
    border-radius: 9999px;
    white-space: nowrap;
}
.status-running { background: #dbeafe; color: #1e40af; }
.status-retry { background: #fef3c7; color: #92400e; }
.status-success { background: #dcfce7; color: #166534; }
.status-warning { background: #fef9c3; color: #854d0e; }
.status-danger { background: #fee2e2; color: #991b1b; }
.status-pending { background: #f1f5f9; color: #475569; }

.check-error-box {
    margin: 0.5rem 0;
    padding: 0.4rem 0.6rem;
    background: #fff1f2;
    border-left: 3px solid #f43f5e;
    color: #881337;
    border-radius: 0.25rem;
}
.check-card-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}
.btn-sm {
    font-size: 0.8rem;
    padding: 0.35rem 0.65rem;
}

.modal-backdrop {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
}
.modal-card {
    background: #ffffff;
    border-radius: 0.5rem;
    padding: 1.5rem;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}
.modal-card h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1.15rem;
}
.modal-subtitle {
    margin: 0 0 1.25rem 0;
    color: #64748b;
    font-size: 0.875rem;
}
.scope-fieldset {
    border: none;
    padding: 0;
    margin: 0 0 1.5rem 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.scope-checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    cursor: pointer;
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
}
.checkbox-info strong {
    display: block;
    font-size: 0.9rem;
}
.checkbox-info small {
    color: #64748b;
    font-size: 0.8rem;
}
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}
.pair-modal-container {
    margin-top: 1.5rem;
}

.volume-details-section {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    margin: 0.5rem 0;
}
.volume-reference-card {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.65rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
}
.volume-ref-thumb {
    width: 48px;
    height: 66px;
    object-fit: cover;
    border-radius: 0.25rem;
    border: 1px solid #cbd5e1;
    flex-shrink: 0;
}
.volume-ref-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 0;
}
.volume-ref-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}
.ref-source-badge {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 0.1rem 0.4rem;
    border-radius: 0.25rem;
    background: #dbeafe;
    color: #1e40af;
}
.ref-cat-badge {
    font-size: 0.68rem;
    font-weight: 600;
    padding: 0.1rem 0.4rem;
    border-radius: 0.25rem;
    background: #f1f5f9;
    color: #475569;
}
.volume-ref-title {
    font-size: 0.8rem;
    color: #0f172a;
    line-height: 1.25;
}
.volume-progression-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    color: #334155;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    padding: 0.2rem 0.5rem;
    border-radius: 0.25rem;
    margin-top: 0.15rem;
}
.prog-arrow {
    color: #64748b;
    font-weight: 700;
}
.volume-components-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.4rem;
}
.volume-comp-chip {
    display: flex;
    flex-direction: column;
    padding: 0.4rem 0.55rem;
    border-radius: 0.3rem;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}
.volume-comp-chip.comp-match {
    background: #f0fdf4;
    border-color: #bbf7d0;
    color: #166534;
}
.volume-comp-chip.comp-mismatch {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}
.comp-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.68rem;
    opacity: 0.85;
}
.comp-page {
    font-weight: 600;
}
.comp-val {
    font-size: 0.78rem;
    margin-top: 0.1rem;
}
.volume-quick-verify-box {
    padding: 0.65rem;
    background: #f8fafc;
    border: 1px dashed #94a3b8;
    border-radius: 0.375rem;
}
.quick-verify-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 0.4rem;
}
.quick-verify-row {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}
.quick-verify-input {
    width: 90px;
    padding: 0.3rem 0.5rem;
    font-size: 0.8rem;
    border: 1px solid #cbd5e1;
    border-radius: 0.25rem;
    background: #ffffff;
}
</style>
