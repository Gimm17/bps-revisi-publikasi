<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import {
    UploadCloud,
    FileSpreadsheet,
    ArrowRight,
    ShieldCheck,
    Sparkles,
    Zap,
    Lock,
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

                        <div class="mode-selection my-4">
                            <label class="block font-semibold text-sm mb-2 text-slate-800 dark:text-slate-200">Metode Pemeriksaan</label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <!-- Mode 1: Full AI -->
                                <div 
                                    @click="form.audit_mode = 'ai'"
                                    :class="[
                                        'p-3.5 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between text-left',
                                        form.audit_mode === 'ai' 
                                            ? 'border-indigo-600 bg-indigo-50/50 dark:bg-indigo-950/20 ring-1 ring-indigo-500/20' 
                                            : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 bg-white dark:bg-slate-900'
                                    ]"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300">Default</span>
                                        <Sparkles :size="16" class="text-indigo-600 dark:text-indigo-400" />
                                    </div>
                                    <strong class="text-sm font-bold text-slate-900 dark:text-slate-100">Full Vision AI</strong>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-snug">
                                        Inspeksi mendalam tiap halaman dengan mata Vision AI multimodal.
                                    </p>
                                </div>

                                <!-- Mode 2: Mesin Kilat -->
                                <div 
                                    @click="form.audit_mode = 'machine'"
                                    :class="[
                                        'p-3.5 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between text-left',
                                        form.audit_mode === 'machine' 
                                            ? 'border-amber-500 bg-amber-50/50 dark:bg-amber-950/20 ring-1 ring-amber-500/20' 
                                            : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 bg-white dark:bg-slate-900'
                                    ]"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300">0 Token</span>
                                        <Zap :size="16" class="text-amber-600 dark:text-amber-400" />
                                    </div>
                                    <strong class="text-sm font-bold text-slate-900 dark:text-slate-100">Mesin Kilat</strong>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-snug">
                                        100% regex & aturan baku BPS. Selesai dalam 3 detik tanpa kuota AI.
                                    </p>
                                </div>

                                <!-- Mode 3: Fusion Dual-Engine (Khusus Admin) -->
                                <div 
                                    @click="$page.props.auth?.user?.role === 'admin' ? form.audit_mode = 'fusion' : null"
                                    :class="[
                                        'p-3.5 rounded-xl border-2 transition flex flex-col justify-between text-left',
                                        $page.props.auth?.user?.role !== 'admin'
                                            ? 'opacity-60 cursor-not-allowed border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950'
                                            : (form.audit_mode === 'fusion' 
                                                ? 'border-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 ring-1 ring-emerald-500/20 cursor-pointer' 
                                                : 'border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 bg-white dark:bg-slate-900 cursor-pointer')
                                    ]"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <span 
                                            :class="[
                                                'text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full',
                                                $page.props.auth?.user?.role === 'admin' 
                                                    ? 'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300' 
                                                    : 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400'
                                            ]"
                                        >
                                            {{ $page.props.auth?.user?.role === 'admin' ? 'Double Teliti' : 'Admin Only' }}
                                        </span>
                                        <ShieldCheck v-if="$page.props.auth?.user?.role === 'admin'" :size="16" class="text-emerald-600 dark:text-emerald-400" />
                                        <Lock v-else :size="16" class="text-slate-400" />
                                    </div>
                                    <strong class="text-sm font-bold text-slate-900 dark:text-slate-100">Fusion Dual-Engine</strong>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 leading-snug">
                                        Audit Mesin + AI, komparasi selisih, dan auto-learning otomatis.
                                    </p>
                                </div>
                            </div>
                            <p v-if="form.errors.audit_mode" class="field-error mt-1 text-red-500 text-xs" role="alert">
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
