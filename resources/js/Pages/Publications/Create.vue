<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import {
    UploadCloud,
    FileSpreadsheet,
    ArrowRight,
    ShieldCheck,
    FileText,
    Trash2,
    Plus,
    Play,
    CheckCircle2,
    AlertCircle,
    Loader2,
    Layers,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import AppLayout from "../../Layouts/AppLayout.vue";
import type { Shared } from "../../types";

interface BatchPdfItem {
    uid: string;
    file: File;
    title: string;
    region: string;
    year: number;
    kind: "kcda" | "kabda" | "provda" | "other";
    status: "ready" | "uploading" | "done" | "error";
    errorMessage?: string;
    createdId?: string;
}

const MAX_BATCH_FILES = 15;
const page = usePage<Shared>();
const isAdmin = computed(() => page.props.auth?.user?.role === "admin");

const form = useForm({
    title: "",
    region: "",
    year: new Date().getFullYear(),
    kind: "kcda" as "kcda" | "kabda" | "provda" | "other",
    audit_mode: "ai" as "ai" | "machine" | "fusion",
    notes: "",
    pdf: null as File | null,
    template: null as File | null,
});

const batchItems = ref<BatchPdfItem[]>([]);
const isDragging = ref(false);
const step = ref(1);
const fileError = ref("");
const batchUploading = ref(false);
const batchCurrentIndex = ref(0);
const batchTotalCount = ref(0);

const isBatchMode = computed(() => batchItems.value.length > 1);

function toTitleCase(input: string): string {
    return input
        .split(/\s+/)
        .filter(Boolean)
        .map((word) => {
            if (/^\d+$/.test(word)) return word;
            if (/^(BPS|RI|DKI|DI|NAD|NTB|NTT|KBLI|KBJI|PDRB)$/i.test(word)) {
                return word.toUpperCase();
            }
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        })
        .join(" ");
}

function parsePdfMetadata(file: File): Omit<BatchPdfItem, "uid" | "file" | "status"> {
    const rawName = file.name
        .replace(/\.pdf$/i, "")
        .replace(/[_-]+/g, " ")
        .replace(/\s+/g, " ")
        .trim();

    const cleanedTitle = toTitleCase(rawName);
    const yearMatch = cleanedTitle.match(/\b(20\d{2})\b/);
    const year = yearMatch ? Number(yearMatch[1]) : new Date().getFullYear();

    let kind: "kcda" | "kabda" | "provda" | "other" = "kcda";
    let region = "";

    const kcdaMatch = cleanedTitle.match(
        /\b(Kecamatan\s+[A-Za-z0-9\s.'-]+?)(?:\s+Dalam\s+Angka|\s+20\d{2}|$)/i,
    );
    const kabdaMatch = cleanedTitle.match(
        /\b((?:Kabupaten|Kota)\s+[A-Za-z0-9\s.'-]+?)(?:\s+Dalam\s+Angka|\s+20\d{2}|$)/i,
    );
    const provdaMatch = cleanedTitle.match(
        /\b(Provinsi\s+[A-Za-z0-9\s.'-]+?)(?:\s+Dalam\s+Angka|\s+20\d{2}|$)/i,
    );

    if (kcdaMatch) {
        kind = "kcda";
        region = kcdaMatch[1].trim();
    } else if (kabdaMatch) {
        kind = "kabda";
        region = kabdaMatch[1].trim();
    } else if (provdaMatch) {
        kind = "provda";
        region = provdaMatch[1].trim();
    } else {
        const dalamAngkaPrefix = cleanedTitle.match(
            /^([A-Za-z0-9\s.'-]+?)\s+Dalam\s+Angka/i,
        );
        if (dalamAngkaPrefix) {
            const candidate = dalamAngkaPrefix[1].trim();
            region = /^Kecamatan\b/i.test(candidate)
                ? candidate
                : `Kecamatan ${candidate}`;
            kind = "kcda";
        } else {
            region = cleanedTitle
                .replace(/\b20\d{2}\b/g, "")
                .replace(/\s+/g, " ")
                .trim()
                .slice(0, 100);
            kind = "other";
        }
    }

    return {
        title: cleanedTitle.slice(0, 255),
        region: (region || "Wilayah Publikasi").slice(0, 150),
        year,
        kind,
    };
}

function syncSingleFormFromFirstItem() {
    const first = batchItems.value[0];
    if (!first) {
        form.pdf = null;
        return;
    }
    form.pdf = first.file;
    form.title = first.title;
    form.region = first.region;
    form.year = first.year;
    form.kind = first.kind;
}

function addPdfFiles(fileList: FileList | File[]) {
    fileError.value = "";
    const incoming = Array.from(fileList);
    if (!incoming.length) return;

    const validFiles: File[] = [];
    for (const file of incoming) {
        const isPdf =
            file.type === "application/pdf" ||
            file.name.toLowerCase().endsWith(".pdf");
        if (!isPdf) {
            fileError.value = `File "${file.name}" bukan dokumen PDF.`;
            continue;
        }
        if (file.size > 150 * 1024 * 1024) {
            fileError.value = `Ukuran "${file.name}" melebihi batas maksimal 150 MB per PDF.`;
            continue;
        }
        validFiles.push(file);
    }

    if (!validFiles.length) return;

    const remainingSlots = MAX_BATCH_FILES - batchItems.value.length;
    if (remainingSlots <= 0) {
        fileError.value = `Maksimal ${MAX_BATCH_FILES} file PDF sekaligus dalam satu sesi unggah.`;
        return;
    }

    if (validFiles.length > remainingSlots) {
        fileError.value = `Maksimal ${MAX_BATCH_FILES} PDF sekaligus. Hanya ${remainingSlots} file pertama yang ditambahkan.`;
    }

    const toAdd = validFiles.slice(0, remainingSlots);
    for (const file of toAdd) {
        const duplicate = batchItems.value.some(
            (item) =>
                item.file.name === file.name && item.file.size === file.size,
        );
        if (duplicate) continue;

        const meta = parsePdfMetadata(file);
        batchItems.value.push({
            uid: `${file.name}-${file.size}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
            file,
            title: meta.title,
            region: meta.region,
            year: meta.year,
            kind: meta.kind,
            status: "ready",
        });
    }

    syncSingleFormFromFirstItem();
}

function choosePdfInput(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files?.length) {
        addPdfFiles(input.files);
    }
    input.value = "";
}

function onDropPdf(event: DragEvent) {
    isDragging.value = false;
    if (event.dataTransfer?.files?.length) {
        addPdfFiles(event.dataTransfer.files);
    }
}

function removePdfItem(index: number) {
    batchItems.value.splice(index, 1);
    syncSingleFormFromFirstItem();
}

function clearAllPdfs() {
    batchItems.value = [];
    form.pdf = null;
    fileError.value = "";
}

function chooseTemplate(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (file && file.size > 10 * 1024 * 1024) {
        fileError.value = "Ukuran template Excel maksimal 10 MB.";
        return;
    }
    form.template = file;
    fileError.value = "";
}

function applyGlobalKindToAll() {
    for (const item of batchItems.value) {
        item.kind = form.kind;
    }
}

function applyGlobalYearToAll() {
    for (const item of batchItems.value) {
        item.year = form.year;
    }
}

function next() {
    if (!batchItems.value.length) {
        fileError.value = "Pilih minimal 1 file PDF publikasi terlebih dahulu.";
        return;
    }
    if (batchItems.value.length === 1) {
        syncSingleFormFromFirstItem();
    }
    step.value = 2;
}

function getCsrfHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
    };
    const metaToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content");
    if (metaToken) {
        headers["X-CSRF-TOKEN"] = metaToken;
    }
    const xsrfMatch = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (xsrfMatch) {
        headers["X-XSRF-TOKEN"] = decodeURIComponent(xsrfMatch[1]);
    }
    return headers;
}

async function uploadSingleItemViaJson(item: BatchPdfItem): Promise<string> {
    const fd = new FormData();
    fd.append("title", item.title.trim() || item.file.name.replace(/\.pdf$/i, ""));
    fd.append("region", item.region.trim() || "Wilayah Publikasi");
    fd.append("year", String(item.year || new Date().getFullYear()));
    fd.append("kind", item.kind);
    fd.append("audit_mode", form.audit_mode);
    if (form.notes.trim()) {
        fd.append("notes", form.notes.trim());
    }
    fd.append("pdf", item.file);
    if (form.template) {
        fd.append("template", form.template);
    }
    fd.append("batch_item", "1");

    const response = await fetch("/publications", {
        method: "POST",
        headers: getCsrfHeaders(),
        body: fd,
        credentials: "same-origin",
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const firstError =
            payload?.errors && typeof payload.errors === "object"
                ? (Object.values(payload.errors).flat()[0] as string)
                : payload?.message;
        throw new Error(
            firstError || `Gagal mengunggah dokumen (HTTP ${response.status}).`,
        );
    }

    if (!payload?.id) {
        throw new Error("Respons server tidak menyertakan ID publikasi.");
    }

    return String(payload.id);
}

async function submitBatch(autoQueue: boolean) {
    if (batchUploading.value || form.processing) return;
    fileError.value = "";

    // Jika hanya 1 dokumen dan tidak autoQueue, gunakan alur standar yang langsung mengarahkan ke halaman detail dokumen
    if (batchItems.value.length === 1 && !autoQueue) {
        form.pdf = batchItems.value[0].file;
        form.post("/publications", {
            forceFormData: true,
            onError: () => (step.value = 2),
        });
        return;
    }

    // Jika hanya 1 dokumen dan autoQueue = true, sincronkan dari form ke item[0]
    if (batchItems.value.length === 1) {
        batchItems.value[0].title = form.title;
        batchItems.value[0].region = form.region;
        batchItems.value[0].year = form.year;
        batchItems.value[0].kind = form.kind;
    }

    // Validasi setiap baris terlebih dahulu
    for (let i = 0; i < batchItems.value.length; i++) {
        const item = batchItems.value[i];
        if (!item.title.trim() || !item.region.trim()) {
            fileError.value = `Baris #${i + 1} (${item.file.name}): Judul dan Daerah wajib diisi.`;
            return;
        }
    }

    batchUploading.value = true;
    batchTotalCount.value = batchItems.value.length;
    batchCurrentIndex.value = 0;

    const createdIds: string[] = [];

    for (let i = 0; i < batchItems.value.length; i++) {
        const item = batchItems.value[i];
        batchCurrentIndex.value = i + 1;

        if (item.status === "done" && item.createdId) {
            createdIds.push(item.createdId);
            continue;
        }

        item.status = "uploading";
        item.errorMessage = undefined;

        try {
            const pubId = await uploadSingleItemViaJson(item);
            item.status = "done";
            item.createdId = pubId;
            createdIds.push(pubId);
        } catch (err: unknown) {
            item.status = "error";
            item.errorMessage =
                err instanceof Error ? err.message : "Gagal mengunggah PDF.";
        }
    }

    batchUploading.value = false;

    const failedCount = batchItems.value.filter(
        (item) => item.status === "error",
    ).length;

    if (failedCount > 0 && createdIds.length === 0) {
        fileError.value =
            "Semua dokumen gagal diunggah. Periksa pesan kesalahan pada tabel di bawah.";
        return;
    }

    if (failedCount > 0) {
        fileError.value = `${createdIds.length} dokumen berhasil diunggah, namun ${failedCount} dokumen gagal. Perbaiki yang gagal lalu klik ulang, atau buka daftar publikasi.`;
        return;
    }

    if (autoQueue && createdIds.length > 0) {
        router.post("/publications/batch-start", {
            publication_ids: createdIds,
            audit_mode: form.audit_mode,
        });
        return;
    }

    router.visit("/publications");
}
</script>

<template>
    <Head title="Pemeriksaan baru" />
    <AppLayout>
        <nav class="breadcrumb" aria-label="Jejak navigasi">
            <Link href="/publications">Publikasi</Link>
            <span>/ Pemeriksaan baru</span>
        </nav>

        <div class="page-heading">
            <h1>
                {{
                    isBatchMode
                        ? `Unggah Batch (${batchItems.length} Publikasi)`
                        : "Mulai Pemeriksaan Publikasi"
                }}
            </h1>
            <p>
                Tarik &amp; lepas (drag &amp; drop) hingga
                <strong>{{ MAX_BATCH_FILES }} file PDF sekaligus</strong>.
                Pemeriksaan batch berjalan otomatis satu per satu di server
                tanpa mengganggu antrean manual.
            </p>
        </div>

        <div class="upload-layout" :class="{ 'upload-layout-wide': step === 2 && isBatchMode }">
            <section class="upload-main">
                <nav class="step-bar" aria-label="Tahap unggah">
                    <button
                        type="button"
                        :class="{ selected: step === 1 }"
                        :disabled="batchUploading"
                        @click="step = 1"
                    >
                        <b>1</b>Dokumen PDF
                        <span v-if="batchItems.length" class="step-count-pill">
                            {{ batchItems.length }}
                        </span>
                    </button>
                    <span></span>
                    <button
                        type="button"
                        :class="{ selected: step === 2 }"
                        :disabled="batchUploading"
                        @click="next"
                    >
                        <b>2</b>Identitas &amp; Antrean
                    </button>
                </nav>

                <form @submit.prevent="submitBatch(false)">
                    <!-- STEP 1: DRAG & DROP MULTI-PDF -->
                    <div v-show="step === 1">
                        <label
                            class="dropzone"
                            :class="{
                                'dropzone-dragging': isDragging,
                                'dropzone-compact': batchItems.length > 0,
                            }"
                            for="pdf"
                            @dragenter.prevent="isDragging = true"
                            @dragover.prevent="isDragging = true"
                            @dragleave.prevent="isDragging = false"
                            @drop.prevent="onDropPdf"
                        >
                            <span class="upload-symbol">
                                <UploadCloud :size="38" />
                            </span>
                            <strong>
                                {{
                                    batchItems.length === 0
                                        ? "Tarik & lepas PDF publikasi di sini (1–15 PDF)"
                                        : `Tambah PDF lainnya (${batchItems.length}/${MAX_BATCH_FILES} dokumen terpilih)`
                                }}
                            </strong>
                            <span>
                                Bisa pilih langsung banyak file sekaligus · Maks.
                                150 MB per PDF · hingga 500 halaman
                            </span>
                            <span class="button secondary">
                                <Plus v-if="batchItems.length > 0" :size="16" />
                                {{
                                    batchItems.length === 0
                                        ? "Pilih File PDF (Bisa Banyak)"
                                        : "Tambah File PDF"
                                }}
                            </span>
                            <input
                                id="pdf"
                                type="file"
                                multiple
                                accept="application/pdf,.pdf"
                                @change="choosePdfInput"
                            />
                        </label>

                        <!-- DAFTAR FILE PDF YANG DIPILIH -->
                        <div v-if="batchItems.length > 0" class="selected-files-box">
                            <div class="selected-files-header">
                                <div>
                                    <strong>
                                        {{ batchItems.length }} dari maksimal
                                        {{ MAX_BATCH_FILES }} file PDF siap
                                        diproses
                                    </strong>
                                    <small>
                                        Judul, daerah, dan tahun otomatis
                                        dikenali dari nama file dan dapat
                                        diedit di langkah berikutnya.
                                    </small>
                                </div>
                                <button
                                    type="button"
                                    class="button secondary btn-sm"
                                    @click="clearAllPdfs"
                                >
                                    <Trash2 :size="14" /> Hapus Semua
                                </button>
                            </div>

                            <ul class="selected-files-list">
                                <li
                                    v-for="(item, idx) in batchItems"
                                    :key="item.uid"
                                    class="selected-file-row"
                                >
                                    <span class="file-row-index">{{ idx + 1 }}</span>
                                    <FileText :size="18" class="file-row-icon" />
                                    <div class="file-row-meta">
                                        <strong>{{ item.file.name }}</strong>
                                        <small>
                                            {{ (item.file.size / 1048576).toFixed(1) }} MB ·
                                            Terdeteksi: {{ item.region }} ({{ item.year }})
                                        </small>
                                    </div>
                                    <button
                                        type="button"
                                        class="icon-button"
                                        title="Hapus file ini"
                                        @click="removePdfItem(idx)"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div class="template-upload">
                            <FileSpreadsheet :size="25" />
                            <div>
                                <label for="template">
                                    Template Excel <small>(opsional)</small>
                                </label>
                                <p>
                                    Gunakan template sendiri, atau template
                                    pemeriksaan standar yang sudah tersedia.
                                </p>
                                <input
                                    id="template"
                                    type="file"
                                    accept=".xlsx"
                                    @change="chooseTemplate"
                                />
                                <small v-if="form.template">{{
                                    form.template.name
                                }}</small>
                            </div>
                        </div>

                        <p v-if="fileError" class="field-error" role="alert">
                            {{ fileError }}
                        </p>

                        <div class="form-actions">
                            <Link href="/publications" class="button secondary">
                                Batal
                            </Link>
                            <button
                                type="button"
                                class="button primary"
                                @click="next"
                            >
                                Lanjutkan ({{ batchItems.length || 0 }} PDF)
                                <ArrowRight :size="18" />
                            </button>
                        </div>
                    </div>

                    <!-- STEP 2: IDENTITAS & METODE PEMERIKSAAN -->
                    <div v-show="step === 2" class="form-stack">
                        <!-- JIKA HANYA 1 FILE PDF -->
                        <template v-if="!isBatchMode">
                            <h2>Identitas publikasi</h2>
                            <label for="title">Judul publikasi</label>
                            <input
                                id="title"
                                v-model="form.title"
                                required
                                maxlength="255"
                            />
                            <div class="form-grid">
                                <div>
                                    <label for="region">Daerah</label>
                                    <input
                                        id="region"
                                        v-model="form.region"
                                        required
                                        maxlength="150"
                                        placeholder="Contoh: Kecamatan Ampana Kota"
                                    />
                                </div>
                                <div>
                                    <label for="year">Tahun</label>
                                    <input
                                        id="year"
                                        v-model="form.year"
                                        type="number"
                                        min="2000"
                                        max="2100"
                                        required
                                    />
                                </div>
                            </div>
                            <label for="kind">Jenis publikasi</label>
                            <select id="kind" v-model="form.kind">
                                <option value="kcda">Kecamatan Dalam Angka</option>
                                <option value="kabda">
                                    Kabupaten/Kota Dalam Angka
                                </option>
                                <option value="provda">Provinsi Dalam Angka</option>
                                <option value="other">Publikasi lainnya</option>
                            </select>
                            <label for="notes">
                                Catatan untuk pemeriksa <small>(opsional)</small>
                            </label>
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="3"
                                maxlength="3000"
                            />
                        </template>

                        <!-- JIKA MULTI-PDF (2 - 15 DOKUMEN SEKALIGUS) -->
                        <template v-else>
                            <div class="batch-header-banner">
                                <div class="batch-header-title">
                                    <Layers :size="20" />
                                    <div>
                                        <h2>
                                            Identitas Batch ({{ batchItems.length }}
                                            Publikasi PDF)
                                        </h2>
                                        <p>
                                            Periksa atau sesuaikan judul &amp; daerah
                                            tiap PDF di bawah. Saat dijalankan dalam
                                            antrean batch, server akan memeriksa
                                            <strong>satu per satu secara berurutan</strong>
                                            di latar belakang meskipun tab browser
                                            ditutup.
                                        </p>
                                    </div>
                                </div>

                                <div class="batch-bulk-controls">
                                    <div>
                                        <label for="bulk-kind">
                                            Set Jenis untuk Semua
                                        </label>
                                        <select
                                            id="bulk-kind"
                                            v-model="form.kind"
                                            @change="applyGlobalKindToAll"
                                        >
                                            <option value="kcda">
                                                Kecamatan Dalam Angka
                                            </option>
                                            <option value="kabda">
                                                Kabupaten/Kota Dalam Angka
                                            </option>
                                            <option value="provda">
                                                Provinsi Dalam Angka
                                            </option>
                                            <option value="other">
                                                Publikasi lainnya
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="bulk-year">
                                            Set Tahun untuk Semua
                                        </label>
                                        <input
                                            id="bulk-year"
                                            v-model="form.year"
                                            type="number"
                                            min="2000"
                                            max="2100"
                                            @change="applyGlobalYearToAll"
                                        />
                                    </div>
                                    <div>
                                        <label for="bulk-notes">
                                            Catatan Pemeriksa (Semua PDF)
                                        </label>
                                        <input
                                            id="bulk-notes"
                                            v-model="form.notes"
                                            placeholder="Opsional..."
                                            maxlength="3000"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="batch-table-wrapper">
                                <table class="batch-edit-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 36px">#</th>
                                            <th style="min-width: 170px">File PDF</th>
                                            <th style="min-width: 220px">Judul Publikasi</th>
                                            <th style="min-width: 180px">Daerah</th>
                                            <th style="width: 92px">Tahun</th>
                                            <th style="min-width: 155px">Jenis</th>
                                            <th style="width: 110px">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(item, idx) in batchItems"
                                            :key="item.uid"
                                            :class="{
                                                'row-uploading': item.status === 'uploading',
                                                'row-done': item.status === 'done',
                                                'row-error': item.status === 'error',
                                            }"
                                        >
                                            <td class="cell-num">{{ idx + 1 }}</td>
                                            <td class="cell-file">
                                                <strong>{{ item.file.name }}</strong>
                                                <small>
                                                    {{ (item.file.size / 1048576).toFixed(1) }} MB
                                                </small>
                                                <small
                                                    v-if="item.errorMessage"
                                                    class="row-error-text"
                                                >
                                                    {{ item.errorMessage }}
                                                </small>
                                            </td>
                                            <td>
                                                <input
                                                    v-model="item.title"
                                                    :disabled="batchUploading || item.status === 'done'"
                                                    required
                                                    maxlength="255"
                                                />
                                            </td>
                                            <td>
                                                <input
                                                    v-model="item.region"
                                                    :disabled="batchUploading || item.status === 'done'"
                                                    required
                                                    maxlength="150"
                                                />
                                            </td>
                                            <td>
                                                <input
                                                    v-model="item.year"
                                                    type="number"
                                                    min="2000"
                                                    max="2100"
                                                    :disabled="batchUploading || item.status === 'done'"
                                                    required
                                                />
                                            </td>
                                            <td>
                                                <select
                                                    v-model="item.kind"
                                                    :disabled="batchUploading || item.status === 'done'"
                                                >
                                                    <option value="kcda">KcDA</option>
                                                    <option value="kabda">KabDA/KotaDA</option>
                                                    <option value="provda">ProvDA</option>
                                                    <option value="other">Lainnya</option>
                                                </select>
                                            </td>
                                            <td class="cell-status">
                                                <span
                                                    v-if="item.status === 'ready'"
                                                    class="status-pill ready"
                                                >
                                                    Siap
                                                </span>
                                                <span
                                                    v-else-if="item.status === 'uploading'"
                                                    class="status-pill uploading"
                                                >
                                                    <Loader2 :size="13" class="spin-icon" />
                                                    Unggah...
                                                </span>
                                                <span
                                                    v-else-if="item.status === 'done'"
                                                    class="status-pill done"
                                                >
                                                    <CheckCircle2 :size="13" />
                                                    Tersimpan
                                                </span>
                                                <span
                                                    v-else-if="item.status === 'error'"
                                                    class="status-pill error"
                                                >
                                                    <AlertCircle :size="13" />
                                                    Gagal
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <!-- METODE PEMERIKSAAN (BERLAKU UNTUK SINGLE MAUPUN BATCH) -->
                        <div class="mode-selection">
                            <label>
                                Metode Pemeriksaan
                                <span v-if="isBatchMode">
                                    (Diterapkan ke {{ batchItems.length }} dokumen)
                                </span>
                            </label>
                            <div class="mode-grid">
                                <!-- Mode 1: Full AI -->
                                <div
                                    :class="[
                                        'mode-card',
                                        { selected: form.audit_mode === 'ai' },
                                    ]"
                                    @click="form.audit_mode = 'ai'"
                                >
                                    <div class="mode-card-header">
                                        <span class="mode-pill default">Default</span>
                                        <span class="radio-indicator">
                                            <span
                                                v-if="form.audit_mode === 'ai'"
                                                class="radio-dot"
                                            ></span>
                                        </span>
                                    </div>
                                    <strong class="mode-title">Full Vision AI</strong>
                                    <p class="mode-desc">
                                        Inspeksi mendalam tiap halaman dengan mata
                                        Vision AI multimodal.
                                    </p>
                                </div>

                                <!-- Mode 2: Mesin Kilat -->
                                <div
                                    :class="[
                                        'mode-card',
                                        { selected: form.audit_mode === 'machine' },
                                    ]"
                                    @click="form.audit_mode = 'machine'"
                                >
                                    <div class="mode-card-header">
                                        <span class="mode-pill token">0 Token</span>
                                        <span class="radio-indicator">
                                            <span
                                                v-if="form.audit_mode === 'machine'"
                                                class="radio-dot"
                                            ></span>
                                        </span>
                                    </div>
                                    <strong class="mode-title">Mesin Kilat</strong>
                                    <p class="mode-desc">
                                        100% regex &amp; aturan baku BPS. Selesai
                                        dalam hitungan detik tanpa kuota AI.
                                    </p>
                                </div>

                                <!-- Mode 3: Fusion Dual-Engine (Khusus Admin) -->
                                <div
                                    :class="[
                                        'mode-card',
                                        {
                                            selected: form.audit_mode === 'fusion',
                                            disabled: !isAdmin,
                                        },
                                    ]"
                                    @click="isAdmin ? (form.audit_mode = 'fusion') : null"
                                >
                                    <div class="mode-card-header">
                                        <span
                                            :class="[
                                                'mode-pill',
                                                isAdmin ? 'fusion' : 'locked',
                                            ]"
                                        >
                                            {{
                                                isAdmin
                                                    ? "Double Teliti"
                                                    : "Khusus Admin"
                                            }}
                                        </span>
                                        <span class="radio-indicator">
                                            <span
                                                v-if="form.audit_mode === 'fusion'"
                                                class="radio-dot"
                                            ></span>
                                        </span>
                                    </div>
                                    <strong class="mode-title">Fusion Dual-Engine</strong>
                                    <p class="mode-desc">
                                        Audit Mesin + AI, komparasi selisih, dan
                                        auto-learning otomatis.
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="form.errors.audit_mode"
                                class="field-error"
                                role="alert"
                                style="margin-top: 6px"
                            >
                                {{ form.errors.audit_mode }}
                            </p>
                        </div>

                        <div v-if="!isBatchMode" class="notice neutral">
                            <strong>{{ form.pdf?.name }}</strong>
                            <br />Jumlah halaman akan dihitung dan divalidasi dari
                            PDF asli.
                        </div>

                        <!-- PROGRESS UNGGAH BATCH -->
                        <div v-if="batchUploading" class="batch-upload-progress">
                            <div class="batch-upload-progress-text">
                                <Loader2 :size="18" class="spin-icon" />
                                <strong>
                                    Mengunggah &amp; memvalidasi dokumen
                                    {{ batchCurrentIndex }} dari
                                    {{ batchTotalCount }}...
                                </strong>
                            </div>
                            <progress
                                :value="batchCurrentIndex"
                                :max="batchTotalCount"
                                aria-label="Kemajuan unggah batch"
                            />
                            <small>
                                Mohon tunggu hingga semua PDF selesai diunggah ke
                                server. Setelah masuk antrean batch, Anda bebas
                                menutup browser.
                            </small>
                        </div>

                        <p v-if="fileError" class="field-error" role="alert">
                            {{ fileError }}
                        </p>

                        <div class="form-actions form-actions-wrap">
                            <button
                                type="button"
                                class="button secondary"
                                :disabled="form.processing || batchUploading"
                                @click="step = 1"
                            >
                                Kembali
                            </button>

                            <div class="inline-actions">
                                <button
                                    type="submit"
                                    class="button secondary"
                                    :disabled="form.processing || batchUploading"
                                >
                                    {{
                                        form.processing || batchUploading
                                            ? "Memvalidasi dokumen..."
                                            : isBatchMode
                                              ? `Simpan Semua Draft (${batchItems.length} PDF)`
                                              : "Simpan & validasi dokumen"
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="button primary"
                                    :disabled="form.processing || batchUploading"
                                    @click="submitBatch(true)"
                                >
                                    <Play :size="16" />
                                    {{
                                        isBatchMode
                                            ? `Simpan & Periksa Batch (${batchItems.length} PDF Satu per Satu)`
                                            : "Simpan & Langsung Periksa"
                                    }}
                                </button>
                            </div>
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
                    <li>Bisa drag &amp; drop hingga 15 PDF sekaligus.</li>
                    <li>
                        Antrean batch dikerjakan <strong>satu per satu</strong> di
                        server tanpa membuat antrean manual macet.
                    </li>
                    <li>
                        Bisa ditinggal / tutup browser setelah dokumen masuk
                        antrean batch.
                    </li>
                    <li>Excel resmi otomatis dibuat untuk setiap publikasi.</li>
                </ol>
                <p class="small">
                    Isi publikasi akan dikirim ke penyedia AI yang telah diatur
                    admin ketika giliran pemeriksaan dimulai.
                </p>
            </aside>
        </div>
    </AppLayout>
</template>

<style scoped>
.upload-layout-wide {
    grid-template-columns: 1fr;
}
.upload-layout-wide .upload-aside {
    display: none;
}
.step-count-pill {
    background: #305e58;
    color: #ffffff;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 99px;
}
.dropzone-dragging {
    border-color: #305e58 !important;
    background: #e3f2ed !important;
    transform: scale(1.005);
}
.dropzone-compact {
    min-height: 185px;
    padding: 18px;
}
.selected-files-box {
    margin-top: 18px;
    border: 1px solid var(--line, #d7d1c8);
    border-radius: 16px;
    background: #fcfbf7;
    overflow: hidden;
}
.selected-files-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    background: #f4f0e4;
    border-bottom: 1px solid var(--line, #d7d1c8);
}
.selected-files-header strong {
    display: block;
    font-size: 13.5px;
}
.selected-files-header small {
    display: block;
    margin-top: 2px;
    font-size: 11.5px;
}
.btn-sm {
    min-height: 34px;
    padding: 6px 12px;
    font-size: 12px;
}
.selected-files-list {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 320px;
    overflow-y: auto;
}
.selected-file-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    border-bottom: 1px solid #ece7da;
}
.selected-file-row:last-child {
    border-bottom: 0;
}
.file-row-index {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #e6e0d2;
    font-size: 11.5px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.file-row-icon {
    color: #305e58;
    flex-shrink: 0;
}
.file-row-meta {
    flex: 1;
    min-width: 0;
}
.file-row-meta strong {
    display: block;
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.file-row-meta small {
    display: block;
    font-size: 11.5px;
}
.batch-header-banner {
    background: #f6f3e8;
    border: 1px solid var(--line, #d7d1c8);
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 18px;
}
.batch-header-title {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.batch-header-title h2 {
    margin: 0;
    font-size: 20px;
}
.batch-header-title p {
    margin: 4px 0 0;
    font-size: 12.5px;
}
.batch-bulk-controls {
    display: grid;
    grid-template-columns: 1fr 130px 1.2fr;
    gap: 12px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #e4decb;
}
@media (max-width: 768px) {
    .batch-bulk-controls {
        grid-template-columns: 1fr;
    }
}
.batch-bulk-controls label {
    margin: 0 0 5px;
    font-size: 11.5px;
    font-weight: 600;
}
.batch-table-wrapper {
    overflow-x: auto;
    border: 1px solid var(--line, #d7d1c8);
    border-radius: 14px;
    background: #ffffff;
    margin-bottom: 18px;
}
.batch-edit-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
}
.batch-edit-table th {
    text-align: left;
    padding: 10px 12px;
    background: #f4f0e4;
    border-bottom: 1px solid var(--line, #d7d1c8);
    font-size: 11.5px;
    font-weight: 700;
    color: var(--ink, #302b2a);
}
.batch-edit-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #eee9dc;
    vertical-align: middle;
}
.batch-edit-table tr:last-child td {
    border-bottom: 0;
}
.batch-edit-table input,
.batch-edit-table select {
    min-height: 36px;
    padding: 6px 10px;
    font-size: 12.5px;
    border-radius: 8px;
}
.cell-num {
    font-weight: 700;
    color: var(--muted);
    text-align: center;
}
.cell-file strong {
    display: block;
    font-size: 12px;
    max-width: 210px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cell-file small {
    display: block;
    font-size: 11px;
}
.row-error-text {
    color: #b91c1c !important;
    font-weight: 600;
    white-space: normal;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 9px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.status-pill.ready {
    background: #ede7d9;
    color: #4e4644;
}
.status-pill.uploading {
    background: #dbeafe;
    color: #1e40af;
}
.status-pill.done {
    background: #dcfce7;
    color: #166534;
}
.status-pill.error {
    background: #fee2e2;
    color: #b91c1c;
}
.spin-icon {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}
.batch-upload-progress {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 14px 18px;
    margin: 14px 0;
}
.batch-upload-progress-text {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #1e40af;
    margin-bottom: 8px;
}
.form-actions-wrap {
    flex-wrap: wrap;
}
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
