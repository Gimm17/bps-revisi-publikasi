<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import {
    Upload,
    FileText,
    ExternalLink,
    Check,
    X,
    FileCheck,
    AlertCircle,
    CheckCircle
} from "lucide-vue-next";
import type { FindingAssessmentRecord } from "../types";

const props = defineProps<{
    publicationId: string;
    canReview: boolean;
    checkRuns?: any[] | null;
    assessments?: FindingAssessmentRecord[] | null;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const series = ref("");
const year = ref<number | "">("");
const targetUrl = ref("");
const uploading = ref(false);
const assessmentBusy = ref<number | null>(null);

function uploadReference() {
    if (!fileInput.value?.files?.[0]) return;
    const form = new FormData();
    form.append("file", fileInput.value.files[0]);
    if (series.value) form.append("series", series.value);
    if (year.value) form.append("year", String(year.value));
    if (targetUrl.value) form.append("target_url", targetUrl.value);

    uploading.value = true;
    router.post(`/publications/${props.publicationId}/references`, form, {
        preserveScroll: true,
        onFinish: () => {
            uploading.value = false;
            if (fileInput.value) fileInput.value.value = "";
            series.value = "";
            year.value = "";
            targetUrl.value = "";
        },
    });
}

function handleAssessment(assessmentId: number, decision: "accepted" | "rejected") {
    assessmentBusy.value = assessmentId;
    router.patch(
        `/publications/${props.publicationId}/assessments/${assessmentId}`,
        { decision },
        {
            preserveScroll: true,
            onFinish: () => (assessmentBusy.value = null),
        }
    );
}
</script>

<template>
    <div class="reference-evidence-panel" role="region" aria-label="Pengelolaan bukti dan rujukan">
        <!-- Section: Assessment Proposals from System -->
        <div v-if="assessments && assessments.length > 0" class="panel-section">
            <div class="section-title-row">
                <FileCheck :size="18" />
                <h3>Usulan Penyesuaian Temuan dari Sistem</h3>
            </div>
            <p class="section-desc">
                Pemeriksaan tambahan aturan V2 mendeteksi usulan koreksi atau pembatalan temuan. Keputusan akhir tetap berada di tangan peninjau.
            </p>

            <div class="assessments-list">
                <div
                    v-for="a in assessments"
                    :key="a.id"
                    class="assessment-card"
                    :class="{ 'reviewed-card': a.reviewed_at }"
                >
                    <div class="assessment-info">
                        <div class="decision-header">
                            <span class="decision-tag">
                                {{ a.proposed_decision === 'dismiss' ? 'Usulan Pembatalan' : 'Usulan Perubahan' }}
                            </span>
                            <small v-if="a.reviewed_at" class="reviewed-stamp">
                                <CheckCircle :size="14" />
                                Ditinjau oleh {{ a.reviewer?.name ?? 'Peninjau' }}
                            </small>
                        </div>
                        <p class="assessment-reason">
                            <strong>Alasan sistem:</strong> {{ a.reason }}
                        </p>
                    </div>

                    <div v-if="canReview && !a.reviewed_at" class="assessment-actions">
                        <button
                            type="button"
                            class="button primary btn-sm accept-btn"
                            :disabled="assessmentBusy === a.id"
                            @click="handleAssessment(a.id, 'accepted')"
                        >
                            <Check :size="14" />
                            <span>Terima Usulan</span>
                        </button>
                        <button
                            type="button"
                            class="button secondary btn-sm reject-btn"
                            :disabled="assessmentBusy === a.id"
                            @click="handleAssessment(a.id, 'rejected')"
                        >
                            <X :size="14" />
                            <span>Tolak Usulan</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section: Reference Documents List -->
        <div class="panel-section">
            <div class="section-title-row">
                <FileText :size="18" />
                <h3>Dokumen Rujukan Terverifikasi</h3>
            </div>
            <p class="section-desc">
                Daftar publikasi pembanding resmi BPS atau unggahan manual pegawai yang dijadikan dasar perbandingan volume.
            </p>

            <div v-if="checkRuns && checkRuns.some((r: any) => r.references?.length > 0)" class="references-list">
                <template v-for="run in checkRuns" :key="run.id">
                    <div
                        v-for="ref in run.references"
                        :key="ref.id"
                        class="reference-item-card"
                    >
                        <div class="ref-item-main">
                            <div class="ref-provenance-badge" :class="ref.provenance">
                                {{ ref.provenance === 'official_browser' ? 'Unduhan Resmi BPS' : 'Unggahan Pengguna' }}
                            </div>
                            <strong class="ref-title">{{ ref.series }} ({{ ref.year }})</strong>
                            <a
                                v-if="ref.source_url"
                                :href="ref.source_url"
                                target="_blank"
                                rel="noreferrer"
                                class="ref-url"
                            >
                                <ExternalLink :size="12" />
                                <span>{{ ref.source_url }}</span>
                            </a>
                            <div class="ref-status-pills">
                                <span class="badge" :class="ref.access_status">Akses: {{ ref.access_status }}</span>
                                <span class="badge" :class="ref.read_status">Pembacaan: {{ ref.read_status }}</span>
                            </div>
                        </div>

                        <!-- Artifacts list -->
                        <div v-if="ref.artifact_manifest?.length" class="ref-artifacts">
                            <span class="artifacts-label">Bukti digital tersimpan:</span>
                            <div class="artifacts-row">
                                <a
                                    v-for="art in ref.artifact_manifest"
                                    :key="art.id || art.artifact_type"
                                    :href="`/publications/${publicationId}/references/${ref.id}/artifacts/${art.id || art.artifact_type}`"
                                    target="_blank"
                                    class="artifact-link"
                                >
                                    <FileText :size="14" />
                                    <span>{{ art.artifact_type === 'cover_image' ? 'Kover Rujukan' : 'Halaman PDF' }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div v-else class="empty-refs-notice">
                <AlertCircle :size="16" />
                <span>Belum ada dokumen rujukan yang tersimpan untuk publikasi ini.</span>
            </div>
        </div>

        <!-- Section: Manual Reference Upload -->
        <div class="panel-section upload-section">
            <div class="section-title-row">
                <Upload :size="18" />
                <h3>Unggah Dokumen Rujukan Manual</h3>
            </div>
            <p class="section-desc">
                Bila rujukan resmi tidak dapat ditemukan secara otomatis, Anda dapat mengunggah file PDF atau foto kover publikasi edisi sebelumnya.
            </p>

            <form @submit.prevent="uploadReference" class="upload-form">
                <div class="form-group">
                    <label for="ref-file">File Dokumen (PDF, JPG, PNG maks 20MB)</label>
                    <input
                        id="ref-file"
                        ref="fileInput"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        required
                        class="form-control"
                    />
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="ref-series">Nama / Seri Publikasi</label>
                        <input
                            id="ref-series"
                            v-model="series"
                            type="text"
                            placeholder="Contoh: Kecamatan Ampana Kota Dalam Angka"
                            class="form-control"
                        />
                    </div>
                    <div class="form-group">
                        <label for="ref-year">Tahun Publikasi</label>
                        <input
                            id="ref-year"
                            v-model="year"
                            type="number"
                            min="1990"
                            max="2050"
                            placeholder="Contoh: 2025"
                            class="form-control"
                        />
                    </div>
                </div>

                <div class="form-group">
                    <label for="ref-url">Alamat URL Publikasi di Website BPS (opsional)</label>
                    <input
                        id="ref-url"
                        v-model="targetUrl"
                        type="url"
                        placeholder="https://...bps.go.id/publication/..."
                        class="form-control"
                    />
                </div>

                <button
                    type="submit"
                    class="button primary upload-btn"
                    :disabled="uploading"
                >
                    <Upload :size="16" />
                    <span>{{ uploading ? "Mengunggah..." : "Unggah Rujukan Manual" }}</span>
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.reference-evidence-panel {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.panel-section {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1.25rem;
}
.section-title-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
    color: #1e293b;
}
.section-title-row h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 600;
}
.section-desc {
    margin: 0 0 1rem 0;
    font-size: 0.85rem;
    color: #64748b;
}
.assessments-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.assessment-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
}
.reviewed-card {
    opacity: 0.75;
}
.decision-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.35rem;
}
.decision-tag {
    font-size: 0.75rem;
    font-weight: 700;
    background: #fef3c7;
    color: #92400e;
    padding: 0.15rem 0.4rem;
    border-radius: 0.25rem;
    text-transform: uppercase;
}
.reviewed-stamp {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    color: #166534;
}
.assessment-reason {
    margin: 0;
    font-size: 0.875rem;
    color: #334155;
}
.assessment-actions {
    display: flex;
    gap: 0.5rem;
}
.accept-btn {
    background: #16a34a;
}
.reject-btn {
    border-color: #fca5a5;
    color: #b91c1c;
}
.references-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.reference-item-card {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    background: #f8fafc;
}
.ref-provenance-badge {
    display: inline-block;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 0.15rem 0.4rem;
    border-radius: 0.25rem;
    margin-bottom: 0.25rem;
}
.ref-provenance-badge.official_browser {
    background: #dbeafe;
    color: #1e40af;
}
.ref-provenance-badge.user_upload {
    background: #f3e8ff;
    color: #6b21a8;
}
.ref-title {
    font-size: 0.95rem;
    color: #0f172a;
}
.ref-url {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.8rem;
    color: #2563eb;
    text-decoration: underline;
    margin-top: 0.2rem;
    word-break: break-all;
}
.ref-status-pills {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.4rem;
}
.ref-status-pills .badge {
    font-size: 0.75rem;
    padding: 0.15rem 0.4rem;
    border-radius: 0.25rem;
    background: #e2e8f0;
    color: #334155;
}
.artifacts-label {
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 600;
    display: block;
    margin-bottom: 0.35rem;
}
.artifacts-row {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.artifact-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.8rem;
    color: #0f172a;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 0.25rem;
    padding: 0.25rem 0.5rem;
    text-decoration: none;
}
.artifact-link:hover {
    background: #f1f5f9;
}
.empty-refs-notice {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    color: #64748b;
    padding: 1rem;
    background: #f8fafc;
    border-radius: 0.375rem;
}
.upload-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.form-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1rem;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.form-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
}
.form-control {
    padding: 0.5rem;
    border: 1px solid #cbd5e1;
    border-radius: 0.375rem;
    font-size: 0.875rem;
}
.upload-btn {
    align-self: flex-start;
}
</style>
