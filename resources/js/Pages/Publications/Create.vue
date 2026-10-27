<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import {
    UploadCloud,
    FileSpreadsheet,
    ArrowRight,
    ShieldCheck,
} from "lucide-vue-next";
import { ref } from "vue";
import AppLayout from "../../Layouts/AppLayout.vue";
const form = useForm({
    title: "",
    region: "",
    year: new Date().getFullYear(),
    kind: "kcda",
    audit_mode: "ai",
    notes: "",
    pdf: null as File | null,
    template: null as File | null,
});
const step = ref(1);
const fileError = ref("");
function choose(event: Event, type: "pdf" | "template") {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (file && file.size > (type === "pdf" ? 150 : 10) * 1024 * 1024) {
        fileError.value =
            "Ukuran file melebihi batas. PDF maksimal 150 MB; template maksimal 10 MB.";
        return;
    }
    form[type] = file;
    fileError.value = "";
    if (type === "pdf" && file && !form.title)
        form.title = file.name.replace(/\.pdf$/i, "").replaceAll("-", " ");
}
function next() {
    if (!form.pdf) {
        fileError.value = "Pilih PDF publikasi terlebih dahulu.";
        return;
    }
    step.value = 2;
}
function submit() {
    form.post("/publications", {
        forceFormData: true,
        onError: () => (step.value = 2),
    });
}
</script>
<template>
    <Head title="Pemeriksaan baru" /><AppLayout
        ><nav class="breadcrumb" aria-label="Jejak navigasi">
            <Link href="/publications">Publikasi</Link
            ><span>/ Pemeriksaan baru</span>
        </nav>
        <div class="page-heading">
            <h1>Mulai dari satu publikasi</h1>
            <p>Unggah PDF, lengkapi identitas, lalu periksa setiap halaman.</p>
        </div>
        <div class="upload-layout">
            <section class="upload-main">
                <nav class="step-bar" aria-label="Tahap unggah">
                    <button :class="{ selected: step === 1 }" @click="step = 1">
                        <b>1</b>Dokumen</button
                    ><span></span
                    ><button :class="{ selected: step === 2 }" @click="next">
                        <b>2</b>Identitas
                    </button>
                </nav>
                <form @submit.prevent="submit">
                    <div v-show="step === 1">
                        <label class="dropzone" for="pdf"
                            ><span class="upload-symbol"
                                ><UploadCloud :size="38" /></span
                            ><strong>{{
                                form.pdf?.name ?? "Pilih PDF publikasi Anda"
                            }}</strong
                            ><span>{{
                                form.pdf
                                    ? `${(form.pdf.size / 1048576).toFixed(1)} MB · Klik untuk mengganti`
                                    : "PDF maksimal 150 MB · hingga 500 halaman"
                            }}</span
                            ><span class="button secondary">Pilih file PDF</span
                            ><input
                                id="pdf"
                                type="file"
                                accept="application/pdf,.pdf"
                                @change="choose($event, 'pdf')"
                        /></label>
                        <div class="template-upload">
                            <FileSpreadsheet :size="25" />
                            <div>
                                <label for="template"
                                    >Template Excel
                                    <small>(opsional)</small></label
                                >
                                <p>
                                    Gunakan template sendiri, atau template
                                    pemeriksaan standar yang sudah tersedia.
                                </p>
                                <input
                                    id="template"
                                    type="file"
                                    accept=".xlsx"
                                    @change="choose($event, 'template')"
                                /><small v-if="form.template">{{
                                    form.template.name
                                }}</small>
                            </div>
                        </div>
                        <p v-if="fileError" class="field-error" role="alert">
                            {{ fileError }}
                        </p>
                        <div class="form-actions">
                            <Link href="/publications" class="button secondary"
                                >Batal</Link
                            ><button
                                type="button"
                                class="button primary"
                                @click="next"
                            >
                                Lanjutkan<ArrowRight :size="18" />
                            </button>
                        </div>
                    </div>
                    <div v-show="step === 2" class="form-stack">
                        <h2>Identitas publikasi</h2>
                        <label for="title">Judul publikasi</label
                        ><input
                            id="title"
                            v-model="form.title"
                            required
                            maxlength="255"
                        />
                        <div class="form-grid">
                            <div>
                                <label for="region">Daerah</label
                                ><input
                                    id="region"
                                    v-model="form.region"
                                    required
                                    maxlength="150"
                                    placeholder="Contoh: Kecamatan Ampana Kota"
                                />
                            </div>
                            <div>
                                <label for="year">Tahun</label
                                ><input
                                    id="year"
                                    type="number"
                                    v-model="form.year"
                                    min="2000"
                                    max="2100"
                                    required
                                />
                            </div>
                        </div>
                        <label for="kind">Jenis publikasi</label
                        ><select id="kind" v-model="form.kind">
                            <option value="kcda">Kecamatan Dalam Angka</option>
                            <option value="kabda">
                                Kabupaten/Kota Dalam Angka
                            </option>
                            <option value="provda">Provinsi Dalam Angka</option>
                            <option value="other">
                                Publikasi lainnya
                            </option></select
                        ><label for="notes"
                            >Catatan untuk pemeriksa
                            <small>(opsional)</small></label
                        ><textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="3000"
                        />

                        <div class="mode-selection">
                            <label>Metode Pemeriksaan</label>
                            <div class="mode-grid">
                                <!-- Mode 1: Full AI -->
                                <div 
                                    @click="form.audit_mode = 'ai'"
                                    :class="['mode-card', { selected: form.audit_mode === 'ai' }]"
                                >
                                    <div class="mode-card-header">
                                        <span class="mode-pill default">Default</span>
                                        <span class="radio-indicator">
                                            <span v-if="form.audit_mode === 'ai'" class="radio-dot"></span>
                                        </span>
                                    </div>
                                    <strong class="mode-title">Full Vision AI</strong>
                                    <p class="mode-desc">
                                        Inspeksi mendalam tiap halaman dengan mata Vision AI multimodal.
                                    </p>
                                </div>

                                <!-- Mode 2: Mesin Kilat -->
                                <div 
                                    @click="form.audit_mode = 'machine'"
                                    :class="['mode-card', { selected: form.audit_mode === 'machine' }]"
                                >
                                    <div class="mode-card-header">
                                        <span class="mode-pill token">0 Token</span>
                                        <span class="radio-indicator">
                                            <span v-if="form.audit_mode === 'machine'" class="radio-dot"></span>
                                        </span>
                                    </div>
                                    <strong class="mode-title">Mesin Kilat</strong>
                                    <p class="mode-desc">
                                        100% regex & aturan baku BPS. Selesai dalam 3 detik tanpa kuota AI.
                                    </p>
                                </div>

                                <!-- Mode 3: Fusion Dual-Engine (Khusus Admin) -->
                                <div 
                                    @click="$page.props.auth?.user?.role === 'admin' ? form.audit_mode = 'fusion' : null"
                                    :class="[
                                        'mode-card',
                                        { 
                                            selected: form.audit_mode === 'fusion',
                                            disabled: $page.props.auth?.user?.role !== 'admin'
                                        }
                                    ]"
                                >
                                    <div class="mode-card-header">
                                        <span :class="['mode-pill', $page.props.auth?.user?.role === 'admin' ? 'fusion' : 'locked']">
                                            {{ $page.props.auth?.user?.role === 'admin' ? 'Double Teliti' : 'Khusus Admin' }}
                                        </span>
                                        <span class="radio-indicator">
                                            <span v-if="form.audit_mode === 'fusion'" class="radio-dot"></span>
                                        </span>
                                    </div>
                                    <strong class="mode-title">Fusion Dual-Engine</strong>
                                    <p class="mode-desc">
                                        Audit Mesin + AI, komparasi selisih, dan auto-learning otomatis.
                                    </p>
                                </div>
                            </div>
                            <p v-if="form.errors.audit_mode" class="field-error" role="alert" style="margin-top: 6px;">
                                {{ form.errors.audit_mode }}
                            </p>
                        </div>

                        <div class="notice neutral">
                            <strong>{{ form.pdf?.name }}</strong
                            ><br />Jumlah halaman akan dihitung dan divalidasi
                            dari PDF asli.
                        </div>
                        <div class="form-actions">
                            <button
                                type="button"
                                class="button secondary"
                                @click="step = 1"
                            >
                                Kembali</button
                            ><button
                                class="button primary"
                                :disabled="form.processing"
                            >
                                {{
                                    form.processing
                                        ? "Memvalidasi dokumen..."
                                        : "Simpan & validasi dokumen"
                                }}
                            </button>
                        </div>
                        <progress
                            v-if="form.progress"
                            :value="form.progress.percentage"
                            max="100"
                            aria-label="Kemajuan unggah"
                        />
                    </div>
                </form>
            </section>
            <aside class="upload-aside">
                <span class="aside-sticker"><ShieldCheck :size="35" /></span>
                <h2>Teliti, satu halaman<br />demi satu halaman.</h2>
                <p>
                    Setiap lembar mendapat status tersendiri. Halaman yang belum
                    terbaca tidak akan dianggap selesai.
                </p>
                <ol>
                    <li>Dokumen disimpan privat.</li>
                    <li>AI membaca teks, tabel, dan layout.</li>
                    <li>Temuan dilengkapi bukti halaman.</li>
                    <li>Excel otomatis, tetap bisa diedit.</li>
                </ol>
                <p class="small">
                    Isi publikasi akan dikirim ke penyedia AI yang telah diatur
                    admin ketika pemeriksaan dimulai.
                </p>
            </aside>
        </div></AppLayout
    >
</template>

<style scoped>
.mode-selection {
    margin: 18px 0 20px;
}
.mode-selection label {
    margin-bottom: 8px;
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--ink, #302b2a);
}
.mode-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
@media (max-width: 768px) {
    .mode-grid {
        grid-template-columns: 1fr;
    }
}
.mode-card {
    background: #ffffff;
    border: 1.5px solid var(--line, #d7d1c8);
    border-radius: 10px;
    padding: 14px 14px 12px;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    text-align: left;
    position: relative;
    user-select: none;
}
.mode-card:hover {
    border-color: #beb7a5;
    background: #faf8f5;
}
.mode-card.selected {
    border: 2px solid #305e58;
    background: #f2f7f5;
    box-shadow: 0 2px 6px rgba(48, 94, 88, 0.12);
}
.mode-card.disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background: #fbfaf8;
    border-color: #e5e0d8;
}
.mode-card.disabled:hover {
    border-color: #e5e0d8;
    background: #fbfaf8;
}
.mode-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.mode-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink, #302b2a);
    display: block;
    line-height: 1.3;
}
.mode-desc {
    font-size: 11.5px;
    color: var(--muted, #5a5150);
    margin-top: 4px;
    line-height: 1.45;
}
.mode-pill {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 2px 7px;
    border-radius: 99px;
    line-height: 1.2;
}
.mode-pill.default {
    background: #ede8dc;
    color: #4e4644;
}
.mode-pill.token {
    background: #fef3c7;
    color: #92400e;
}
.mode-pill.fusion {
    background: #dcfce7;
    color: #166534;
}
.mode-pill.locked {
    background: #f1f5f9;
    color: #64748b;
}
.radio-indicator {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 1.5px solid var(--line, #d7d1c8);
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    flex-shrink: 0;
}
.mode-card.selected .radio-indicator {
    border-color: #305e58;
}
.radio-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #305e58;
}
</style>
