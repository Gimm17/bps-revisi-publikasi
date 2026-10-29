<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, ref } from "vue";
import {
    Search,
    FileText,
    ArrowUpRight,
    Play,
    X,
    CheckSquare,
    Clock,
} from "lucide-vue-next";
import StatusBadge from "./StatusBadge.vue";
import type { Publication, Paginated, Shared } from "../types";

const props = defineProps<{
    publications: Paginated<Publication>;
    filters?: { q?: string; status?: string };
}>();

const page = usePage<Shared>();
const isAdmin = computed(() => page.props.auth?.user?.role === "admin");

const q = ref(props.filters?.q ?? "");
const status = ref(props.filters?.status ?? "");
const selectedIds = ref<string[]>([]);
const batchAuditMode = ref<"" | "ai" | "machine" | "fusion">("");
const batchBusy = ref(false);

const MAX_BATCH = 15;

function isSelectable(pub: Publication): boolean {
    return ["draft", "partial_failure", "failed"].includes(pub.status);
}

const selectableOnPage = computed(() =>
    props.publications.data.filter((pub) => isSelectable(pub)),
);

const hasActiveOrQueuedOnPage = computed(() =>
    props.publications.data.some((pub) =>
        [
            "batch_queued",
            "processing",
            "processing_machine",
            "processing_ai",
            "reconciling",
            "cross_checking",
            "exporting",
        ].includes(pub.status),
    ),
);

const queuedCountOnPage = computed(
    () =>
        props.publications.data.filter((pub) => pub.status === "batch_queued")
            .length,
);

function toggleSelect(id: string) {
    const idx = selectedIds.value.indexOf(id);
    if (idx >= 0) {
        selectedIds.value.splice(idx, 1);
        return;
    }
    if (selectedIds.value.length >= MAX_BATCH) {
        return;
    }
    selectedIds.value.push(id);
}

function selectAllReadyOnPage() {
    const ids = selectableOnPage.value.map((p) => p.id);
    const allAlreadySelected =
        ids.length > 0 && ids.every((id) => selectedIds.value.includes(id));

    if (allAlreadySelected) {
        selectedIds.value = selectedIds.value.filter((id) => !ids.includes(id));
        return;
    }

    for (const id of ids) {
        if (selectedIds.value.length >= MAX_BATCH) break;
        if (!selectedIds.value.includes(id)) {
            selectedIds.value.push(id);
        }
    }
}

function clearSelection() {
    selectedIds.value = [];
}

function startBatchSelected() {
    if (!selectedIds.value.length || batchBusy.value) return;
    batchBusy.value = true;
    const payload: Record<string, unknown> = {
        publication_ids: selectedIds.value,
    };
    if (batchAuditMode.value) {
        payload.audit_mode = batchAuditMode.value;
    }

    router.post("/publications/batch-start", payload, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
        },
        onFinish: () => {
            batchBusy.value = false;
        },
    });
}

function cancelBatchItem(pubId: string) {
    if (batchBusy.value) return;
    batchBusy.value = true;
    router.post(
        `/publications/${pubId}/batch-cancel`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                batchBusy.value = false;
            },
        },
    );
}

function search() {
    router.get(
        "/publications",
        { q: q.value, status: status.value },
        { preserveState: true, replace: true },
    );
}

let pollTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    pollTimer = setInterval(() => {
        if (hasActiveOrQueuedOnPage.value && !batchBusy.value) {
            router.reload({
                only: ["publications", "metrics"],
            });
        }
    }, 5000);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
    <form class="list-toolbar" @submit.prevent="search">
        <label class="search-field">
            <Search :size="19" />
            <input
                v-model="q"
                aria-label="Cari judul publikasi"
                placeholder="Cari publikasi atau daerah..."
            />
        </label>
        <select v-model="status" aria-label="Filter status" @change="search">
            <option value="">Semua status</option>
            <option value="draft">Siap diperiksa</option>
            <option value="batch_queued">Dalam antrean batch</option>
            <option value="processing">Sedang diperiksa</option>
            <option value="partial_failure">Perlu dilanjutkan</option>
            <option value="review_ready">Siap ditinjau</option>
            <option value="final">Final</option>
        </select>
        <button class="button secondary" type="submit">Cari</button>
        <button
            v-if="selectableOnPage.length > 0"
            type="button"
            class="button secondary batch-quick-select-btn"
            @click="selectAllReadyOnPage"
        >
            <CheckSquare :size="16" />
            Pilih Siap Diperiksa ({{ selectableOnPage.length }})
        </button>
    </form>

    <!-- INFO ANTREAN BATCH SERVER -->
    <div v-if="queuedCountOnPage > 0" class="batch-queue-notice">
        <Clock :size="18" />
        <div>
            <strong>
                {{ queuedCountOnPage }} dokumen sedang berada dalam antrean
                batch server.
            </strong>
            <span>
                Pemeriksaan dijalankan otomatis
                <strong>satu per satu secara berurutan</strong> di server. Anda
                bebas menutup browser / meninggalkan halaman ini, dan antrean
                manual lainnya tetap tidak terganggu.
            </span>
        </div>
    </div>

    <!-- BAR AKSI BATCH SAAT ADA DOKUMEN TERPILIH -->
    <div v-if="selectedIds.length > 0" class="batch-action-bar">
        <div class="batch-action-info">
            <span class="batch-count-badge">{{ selectedIds.length }}</span>
            <div>
                <strong>
                    {{ selectedIds.length }} publikasi dipilih untuk pemeriksaan
                    batch
                </strong>
                <small>
                    Maksimal {{ MAX_BATCH }} dokumen · Dikerjakan satu per satu
                    di server (bisa ditinggal / tutup web)
                </small>
            </div>
        </div>

        <div class="batch-action-controls">
            <select
                v-model="batchAuditMode"
                aria-label="Metode pemeriksaan batch"
                class="batch-mode-select"
            >
                <option value="">Metode: Ikuti setelan dokumen</option>
                <option value="ai">Metode: Full Vision AI</option>
                <option value="machine">Metode: Mesin Kilat (0 Token)</option>
                <option v-if="isAdmin" value="fusion">
                    Metode: Fusion Dual-Engine
                </option>
            </select>

            <button
                type="button"
                class="button primary"
                :disabled="batchBusy"
                @click="startBatchSelected"
            >
                <Play :size="16" />
                {{
                    batchBusy
                        ? "Memproses antrean..."
                        : `Periksa Batch (${selectedIds.length} PDF)`
                }}
            </button>

            <button
                type="button"
                class="button secondary"
                :disabled="batchBusy"
                @click="clearSelection"
            >
                <X :size="16" />
                Batal Pilih
            </button>
        </div>
    </div>

    <div v-if="!publications.data.length" class="empty-state">
        <span class="empty-icon"><FileText :size="38" /></span>
        <h2>{{ q ? "Publikasi belum ditemukan" : "Belum ada publikasi" }}</h2>
        <p>
            {{
                q
                    ? "Coba kata kunci lain atau ubah filter status."
                    : "Unggah PDF pertama Anda (bisa langsung drag & drop hingga 15 PDF sekaligus)."
            }}
        </p>
        <Link v-if="!q" href="/publications/create" class="button primary">
            Unggah publikasi
        </Link>
    </div>

    <div v-else class="publication-table">
        <div class="table-heading table-heading-batch">
            <span class="col-check">Pilih</span>
            <span>Publikasi</span>
            <span>Kemajuan</span>
            <span>Status</span>
            <span>Temuan</span>
            <span></span>
        </div>

        <div
            v-for="pub in publications.data"
            :key="pub.id"
            class="publication-row publication-row-batch"
            :class="{ 'row-selected': selectedIds.includes(pub.id) }"
        >
            <div class="col-check" @click.stop>
                <input
                    v-if="isSelectable(pub)"
                    type="checkbox"
                    :checked="selectedIds.includes(pub.id)"
                    :disabled="
                        !selectedIds.includes(pub.id) &&
                        selectedIds.length >= MAX_BATCH
                    "
                    :aria-label="`Pilih ${pub.title} untuk pemeriksaan batch`"
                    @change="toggleSelect(pub.id)"
                />
            </div>

            <Link :href="`/publications/${pub.id}`" class="publication-name">
                <span class="file-icon"><FileText :size="23" /></span>
                <div>
                    <strong>{{ pub.title }}</strong>
                    <small>
                        {{ pub.region }} · {{ pub.year }} ·
                        {{ pub.page_count }} halaman
                    </small>
                </div>
            </Link>

            <Link :href="`/publications/${pub.id}`" class="progress-cell">
                <progress
                    :value="pub.checked_pages_count ?? 0"
                    :max="pub.page_count"
                    :aria-label="`Kemajuan ${pub.title}`"
                />
                <small>
                    {{ pub.checked_pages_count ?? 0 }} /
                    {{ pub.page_count }} halaman
                </small>
            </Link>

            <div class="status-cell-wrap">
                <StatusBadge :status="pub.status" />
                <button
                    v-if="pub.status === 'batch_queued'"
                    type="button"
                    class="batch-cancel-inline-btn"
                    :disabled="batchBusy"
                    title="Keluarkan dari antrean batch"
                    @click.stop="cancelBatchItem(pub.id)"
                >
                    Batal Antre
                </button>
            </div>

            <Link :href="`/publications/${pub.id}`" class="finding-count">
                {{ pub.findings_count ?? 0 }}<small> temuan</small>
            </Link>

            <Link
                :href="`/publications/${pub.id}`"
                aria-label="Buka detail publikasi"
            >
                <ArrowUpRight :size="20" aria-hidden="true" />
            </Link>
        </div>
    </div>

    <nav
        v-if="publications.links.length > 3"
        class="pagination"
        aria-label="Halaman publikasi"
    >
        <template v-for="(link, i) in publications.links" :key="i">
            <Link
                v-if="link.url"
                :href="link.url"
                :class="['button secondary', { selected: link.active }]"
            >
                {{
                    i === 0
                        ? "Sebelumnya"
                        : i === publications.links.length - 1
                          ? "Berikutnya"
                          : link.label
                }}
            </Link>
        </template>
    </nav>
</template>

<style scoped>
.batch-quick-select-btn {
    white-space: nowrap;
}
.batch-queue-notice {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
    border-radius: 14px;
    padding: 13px 18px;
    margin-bottom: 16px;
    font-size: 12.5px;
    line-height: 1.5;
}
.batch-queue-notice strong {
    display: block;
    margin-bottom: 2px;
}
.batch-action-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    background: #e5efe4;
    border: 1.5px solid #305e58;
    border-radius: 16px;
    padding: 14px 18px;
    margin-bottom: 18px;
    box-shadow: 0 4px 14px rgba(48, 94, 88, 0.1);
}
.batch-action-info {
    display: flex;
    align-items: center;
    gap: 12px;
}
.batch-Action-info strong {
    display: block;
    font-size: 13.5px;
}
.batch-action-info small {
    display: block;
    margin-top: 2px;
    font-size: 11.5px;
}
.batch-count-badge {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #305e58;
    color: #ffffff;
    font-weight: 700;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.batch-action-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.batch-mode-select {
    width: auto;
    min-width: 220px;
    border-radius: 99px;
    padding: 8px 14px;
    font-size: 12.5px;
}
.table-heading-batch,
.publication-row-batch {
    grid-template-columns: 36px minmax(230px, 2.7fr) 1fr 1.25fr 0.55fr 24px;
}
.col-check {
    display: flex;
    align-items: center;
    justify-content: center;
}
.row-selected {
    background: #f2f7f5;
    border-radius: 12px;
}
.status-cell-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.batch-cancel-inline-btn {
    background: #ffffff;
    border: 1px solid #93c5fd;
    color: #1e40af;
    border-radius: 99px;
    padding: 3px 9px;
    font-size: 10.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}
.batch-cancel-inline-btn:hover {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #b91c1c;
}
@media (max-width: 768px) {
    .table-heading-batch {
        display: none;
    }
    .publication-row-batch {
        grid-template-columns: 28px 1fr;
        gap: 10px;
    }
}
</style>
