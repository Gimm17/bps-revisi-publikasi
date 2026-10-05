<script setup lang="ts">
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { computed, ref, onMounted, onUnmounted } from "vue";
import {
    Play,
    RotateCw,
    ZoomIn,
    ZoomOut,
    ChevronLeft,
    ChevronRight,
    Download,
    Plus,
    Lock,
    Unlock,
    FileSpreadsheet,
    CheckCircle2,
    Search,
} from "lucide-vue-next";
import AppLayout from "../../Layouts/AppLayout.vue";
import StatusBadge from "../../Components/StatusBadge.vue";
import FindingEditor from "../../Components/FindingEditor.vue";
import {
    label,
    type Publication,
    type PageRecord,
    type Finding,
    type ExportRecord,
    type Employee,
    type Shared,
} from "../../types";
const props = defineProps<{
    publication: Publication;
    pages: PageRecord[];
    currentPage: PageRecord;
    findings: Finding[];
    exports: ExportRecord[];
    rules: Record<string, string>;
    canEdit: boolean;
    canReview: boolean;
    employees: Employee[];
    assignedUsers: number[];
}>();
const shared = usePage<Shared>();
const section = ref("workspace");
const mobileTab = ref("document");
const zoom = ref(1);
const rotation = ref(0);
const selectedId = ref<number | null>(null);
const adding = ref(false);
const query = ref("");
const severity = ref("");
const review = ref("");
const allPages = ref(false);
const imageError = ref(false);
const editor = ref<InstanceType<typeof FindingEditor> | null>(null);
const assigned = ref([...props.assignedUsers]);
const busy = ref(false);
const root = computed(() => `/publications/${props.publication.id}`);
const checked = computed(
    () => props.pages.filter((p) => p.status === "complete").length,
);
const blocked = computed(() =>
    props.pages.filter((p) =>
        ["failed", "needs_verification"].includes(p.status),
    ),
);
const selected = computed(
    () => props.findings.find((f) => f.id === selectedId.value) ?? null,
);
const activeFindings = computed(() => props.findings.filter((f) => !f.deleted));
const filtered = computed(() =>
    activeFindings.value.filter(
        (f) =>
            (allPages.value ||
                f.evidences.some(
                    (e) => e.physical_page === props.currentPage.physical_page,
                )) &&
            (!severity.value || f.severity === severity.value) &&
            (!review.value || f.review_status === review.value) &&
            `${f.observed} ${f.recommendation} ${f.rule_code}`
                .toLowerCase()
                .includes(query.value.toLowerCase()),
    ),
);
const boxes = computed(
    () =>
        selected.value?.evidences.filter(
            (e) =>
                e.physical_page === props.currentPage.physical_page && e.bbox,
        ) ?? [],
);
const running = computed(() =>
    ["processing", "cross_checking", "exporting"].includes(
        props.publication.status,
    ),
);
const ready = computed(() =>
    ["review_ready", "final"].includes(props.publication.status),
);
const remainingReview = computed(
    () =>
        activeFindings.value.filter((f) =>
            ["unreviewed", "needs_verification"].includes(f.review_status),
        ).length,
);
function canLeave() {
    return (
        !editor.value?.dirty() ||
        window.confirm("Perubahan temuan belum disimpan. Tinggalkan perubahan?")
    );
}
function switchSection(next: string) {
    if (canLeave()) section.value = next;
}
function pageTo(n: number) {
    if (n < 1 || n > props.publication.page_count || !canLeave()) return;
    router.get(
        root.value,
        { page: n },
        {
            preserveState: true,
            preserveScroll: true,
            only: ["currentPage"],
            onSuccess: () => {
                imageError.value = false;
                zoom.value = 1;
                rotation.value = 0;
                adding.value = false;
                mobileTab.value = "document";
            },
        },
    );
}
function choose(f: Finding) {
    if (!canLeave()) return;
    selectedId.value = f.id;
    adding.value = false;
    mobileTab.value = "findings";
}
function add() {
    if (!canLeave()) return;
    selectedId.value = null;
    adding.value = true;
    mobileTab.value = "findings";
}
function action(
    suffix: string,
    data: Record<string, string | number | boolean | number[]> = {},
) {
    busy.value = true;
    router.post(root.value + suffix, data, {
        preserveScroll: true,
        onFinish: () => (busy.value = false),
    });
}
function beforeUnload(e: BeforeUnloadEvent) {
    if (editor.value?.dirty()) {
        e.preventDefault();
    }
}
function rebindAi() {
    if (
        canLeave() &&
        window.confirm(
            "Gunakan koneksi AI terbaru yang sudah diuji admin? Model, penyedia, dan biaya halaman berikutnya dapat berubah. Halaman selesai tidak diperiksa ulang; pergantian dicatat dalam riwayat.",
        )
    ) {
        action("/rebind-ai", { confirm: true });
    }
}
let timer: ReturnType<typeof setInterval>;
let removeNavigationGuard: (() => void) | undefined;
onMounted(() => {
    removeNavigationGuard = router.on("before", (event) => {
        if (
            event.detail.visit.method === "get" &&
            event.detail.visit.url.pathname !== root.value &&
            !canLeave()
        )
            return false;
    });
    timer = setInterval(() => {
        if (
            (running.value ||
                props.exports.some((e) =>
                    ["pending", "processing"].includes(e.status),
                )) &&
            !editor.value?.dirty() &&
            !busy.value
        )
            router.reload({
                only: [
                    "publication",
                    "pages",
                    "currentPage",
                    "findings",
                    "exports",
                ],
            });
    }, 5000);
    window.addEventListener("beforeunload", beforeUnload);
});
onUnmounted(() => {
    removeNavigationGuard?.();
    clearInterval(timer);
    window.removeEventListener("beforeunload", beforeUnload);
});
</script>
<template>
    <Head :title="publication.region" /><AppLayout wide
        ><nav class="breadcrumb" aria-label="Jejak navigasi">
            <Link href="/publications">Publikasi</Link
            ><span>/ {{ publication.region }}</span>
        </nav>
        <div class="audit-heading">
            <div>
                <h1>{{ publication.title }}</h1>
                <p>
                    {{ publication.page_count }} halaman ·
                    {{ publication.rule_version }} · Revisi
                    {{ publication.revision }}
                </p>
            </div>
            <div class="inline-actions">
                <StatusBadge :status="publication.status" /><button
                    v-if="canEdit && !ready"
                    class="button primary"
                    :disabled="busy || running"
                    @click="action('/start')"
                >
                    <Play :size="17" />{{
                        running
                            ? "Sedang diperiksa"
                            : publication.status === "draft"
                              ? "Mulai pemeriksaan"
                              : "Lanjutkan pemeriksaan"
                    }}</button
                ><button
                    v-if="canEdit && publication.status === 'partial_failure'"
                    class="button secondary"
                    :disabled="busy"
                    @click="rebindAi"
                >
                    Gunakan koneksi AI terbaru</button
                ><a
                    v-if="exports.find((e) => e.status === 'complete')"
                    :href="`${root}/exports/${exports.find((e) => e.status === 'complete')?.id}`"
                    class="button primary"
                    ><Download :size="17" />Unduh Excel</a
                >
            </div>
        </div>
        <div class="audit-progress" aria-live="polite">
            <CheckCircle2 :size="21" />
            <div>
                <strong
                    >{{ checked }} dari {{ publication.page_count }} halaman
                    selesai diperiksa</strong
                ><progress
                    :value="checked"
                    :max="publication.page_count"
                    aria-label="Cakupan pemeriksaan"
                />
            </div>
            <span
                >{{
                    Math.round((checked / publication.page_count) * 100)
                }}%</span
            >
        </div>
        <div v-if="publication.error || blocked.length" class="notice warning">
            <strong>Pemeriksaan belum lengkap.</strong> {{ publication.error }}
            {{
                blocked.length
                    ? `${blocked.length} halaman perlu dicoba ulang atau diverifikasi.`
                    : ""
            }}
            Excel final belum boleh dibuat sebelum seluruh halaman selesai.
        </div>
        <nav class="section-tabs" aria-label="Bagian pemeriksaan">
            <button
                :class="{ selected: section === 'workspace' }"
                @click="switchSection('workspace')"
            >
                Ruang pemeriksaan
                <span>{{ activeFindings.length }}</span></button
            ><button
                :class="{ selected: section === 'exports' }"
                @click="switchSection('exports')"
            >
                Hasil Excel <span>{{ exports.length }}</span></button
            ><button
                v-if="shared.props.auth.user.role === 'admin' || canReview"
                :class="{ selected: section === 'manage' }"
                @click="switchSection('manage')"
            >
                Pengelolaan
            </button>
        </nav>
        <template v-if="section === 'workspace'"
            ><nav
                class="mobile-workspace-tabs"
                aria-label="Tampilan ruang pemeriksaan"
            >
                <button
                    v-for="(text, key) in {
                        pages: 'Halaman',
                        document: 'Dokumen',
                        findings: 'Temuan',
                    }"
                    :key="key"
                    :class="{ selected: mobileTab === key }"
                    @click="mobileTab = key"
                >
                    {{ text }}
                </button>
            </nav>
            <div class="workspace">
                <aside
                    :class="[
                        'page-rail',
                        { mobileVisible: mobileTab === 'pages' },
                    ]"
                    aria-label="Daftar halaman"
                >
                    <div class="rail-heading">
                        <h2>Halaman</h2>
                        <small
                            >{{ checked }} / {{ publication.page_count }}</small
                        >
                    </div>
                    <div class="page-list">
                        <button
                            v-for="p in pages"
                            :key="p.physical_page"
                            :class="[
                                'page-tile',
                                {
                                    selected:
                                        p.physical_page ===
                                        currentPage.physical_page,
                                },
                            ]"
                            @click="pageTo(p.physical_page)"
                            :aria-label="`Halaman PDF ${p.physical_page}, ${label(p.status)}`"
                            :aria-current="
                                p.physical_page === currentPage.physical_page
                                    ? 'page'
                                    : undefined
                            "
                        >
                            <img
                                v-if="p.has_image"
                                :src="`${root}/pages/${p.physical_page}/image?thumbnail=1`"
                                alt=""
                                loading="lazy"
                            /><span v-else class="page-placeholder">{{
                                p.physical_page
                            }}</span
                            ><span
                                ><strong>PDF {{ p.physical_page }}</strong
                                ><small>{{
                                    p.printed_page
                                        ? `Tercetak ${p.printed_page}`
                                        : label(p.status)
                                }}</small></span
                            ><i
                                :class="['status-dot', p.status]"
                                aria-hidden="true"
                            ></i>
                        </button>
                    </div>
                </aside>
                <section
                    :class="[
                        'document-viewer',
                        { mobileVisible: mobileTab === 'document' },
                    ]"
                    aria-label="Dokumen dan bukti"
                >
                    <div class="viewer-toolbar">
                        <div class="inline-actions">
                            <button
                                class="icon-button"
                                @click="pageTo(currentPage.physical_page - 1)"
                                :disabled="currentPage.physical_page <= 1"
                                aria-label="Halaman sebelumnya"
                            >
                                <ChevronLeft :size="19" /></button
                            ><label class="page-jump"
                                >PDF
                                <input
                                    type="number"
                                    :value="currentPage.physical_page"
                                    min="1"
                                    :max="publication.page_count"
                                    aria-label="Buka nomor halaman PDF"
                                    @change="
                                        pageTo(
                                            Number(
                                                (
                                                    $event.target as HTMLInputElement
                                                ).value,
                                            ),
                                        )
                                    "
                                />
                                / {{ publication.page_count }}</label
                            ><button
                                class="icon-button"
                                @click="pageTo(currentPage.physical_page + 1)"
                                :disabled="
                                    currentPage.physical_page >=
                                    publication.page_count
                                "
                                aria-label="Halaman berikutnya"
                            >
                                <ChevronRight :size="19" />
                            </button>
                        </div>
                        <div class="inline-actions">
                            <button
                                class="icon-button"
                                @click="zoom = Math.max(0.5, zoom - 0.25)"
                                aria-label="Perkecil dokumen"
                            >
                                <ZoomOut :size="18" /></button
                            ><button
                                class="zoom-label"
                                @click="zoom = 1"
                                aria-label="Kembalikan ukuran dokumen"
                            >
                                {{ Math.round(zoom * 100) }}%</button
                            ><button
                                class="icon-button"
                                @click="zoom = Math.min(3, zoom + 0.25)"
                                aria-label="Perbesar dokumen"
                            >
                                <ZoomIn :size="18" /></button
                            ><button
                                class="icon-button"
                                @click="rotation = (rotation + 90) % 360"
                                aria-label="Putar dokumen"
                            >
                                <RotateCw :size="18" />
                            </button>
                        </div>
                    </div>
                    <div class="document-stage">
                        <figure
                            v-if="currentPage.image_path && !imageError"
                            class="page-image"
                            :style="{
                                width: `${zoom * 100}%`,
                                transform: `rotate(${rotation}deg)`,
                            }"
                        >
                            <img
                                :src="`${root}/pages/${currentPage.physical_page}/image`"
                                :alt="`Halaman PDF ${currentPage.physical_page} dari ${publication.title}`"
                                @error="imageError = true"
                            /><span
                                v-for="(e, i) in boxes"
                                :key="i"
                                class="evidence-box"
                                :style="{
                                    left: `${e.bbox![0] * 100}%`,
                                    top: `${e.bbox![1] * 100}%`,
                                    width: `${(e.bbox![2] - e.bbox![0]) * 100}%`,
                                    height: `${(e.bbox![3] - e.bbox![1]) * 100}%`,
                                }"
                                aria-label="Area bukti temuan"
                            ></span>
                        </figure>
                        <div v-else class="viewer-empty">
                            <Search :size="45" />
                            <h2>
                                {{
                                    imageError
                                        ? "Gambar belum bisa dimuat"
                                        : "Halaman menunggu pemeriksaan"
                                }}
                            </h2>
                            <p>
                                {{
                                    imageError
                                        ? "Coba muat ulang halaman atau buka PDF asli."
                                        : "Gambar halaman muncul saat pemeriksaan dimulai. Anda tetap bisa membuka PDF asli."
                                }}
                            </p>
                            <a
                                :href="`${root}/pdf#page=${currentPage.physical_page}`"
                                target="_blank"
                                rel="noopener"
                                class="button secondary"
                                >Buka PDF asli</a
                            >
                        </div>
                    </div>
                    <div class="viewer-footer">
                        <StatusBadge :status="currentPage.status" /><small
                            >{{
                                currentPage.page_type ??
                                "Jenis halaman belum ditentukan"
                            }}{{
                                currentPage.printed_page
                                    ? ` · Tercetak ${currentPage.printed_page}`
                                    : ""
                            }}</small
                        ><button
                            v-if="
                                canEdit &&
                                ['failed', 'needs_verification'].includes(
                                    currentPage.status,
                                )
                            "
                            class="button secondary"
                            :disabled="busy"
                            @click="
                                action(
                                    `/pages/${currentPage.physical_page}/retry`,
                                )
                            "
                        >
                            Coba ulang halaman
                        </button>
                    </div>
                    <details
                        v-if="currentPage.result?.text"
                        class="extracted-text"
                    >
                        <summary>Teks yang dibaca AI</summary>
                        <pre>{{ currentPage.result.text }}</pre>
                    </details>
                </section>
                <aside
                    :class="[
                        'findings-pane',
                        { mobileVisible: mobileTab === 'findings' },
                    ]"
                    aria-label="Temuan pemeriksaan"
                >
                    <div class="rail-heading">
                        <h2>
                            Temuan <span>{{ filtered.length }}</span>
                        </h2>
                        <button
                            v-if="canEdit"
                            class="icon-button"
                            @click="add"
                            aria-label="Tambahkan temuan manual"
                        >
                            <Plus :size="20" />
                        </button>
                    </div>
                    <div class="finding-filters">
                        <label class="search-field"
                            ><Search :size="16" /><input
                                v-model="query"
                                placeholder="Cari temuan..."
                                aria-label="Cari temuan"
                        /></label>
                        <div class="form-grid">
                            <select
                                v-model="severity"
                                aria-label="Filter jenis temuan"
                            >
                                <option value="">Semua jenis</option>
                                <option value="wajib">Wajib</option>
                                <option value="saran">Saran</option>
                                <option value="verifikasi">
                                    Verifikasi
                                </option></select
                            ><select
                                v-model="review"
                                aria-label="Filter keputusan"
                            >
                                <option value="">Semua keputusan</option>
                                <option value="unreviewed">
                                    Belum ditinjau
                                </option>
                                <option value="accepted">Diterima</option>
                                <option value="rejected">Ditolak</option>
                                <option value="needs_verification">
                                    Verifikasi
                                </option>
                            </select>
                        </div>
                        <label class="checkbox"
                            ><input
                                type="checkbox"
                                v-model="allPages"
                            />Tampilkan seluruh halaman</label
                        >
                    </div>
                    <div class="finding-list">
                        <button
                            v-for="f in filtered"
                            :key="f.id"
                            :class="[
                                'finding-item',
                                { selected: selectedId === f.id },
                            ]"
                            @click="choose(f)"
                        >
                            <span :class="['severity', f.severity]">{{
                                f.severity === "wajib"
                                    ? "Wajib diperbaiki"
                                    : f.severity === "saran"
                                      ? "Saran"
                                      : "Perlu verifikasi"
                            }}</span
                            ><strong>{{ f.observed }}</strong
                            ><small
                                >{{ f.rule_code }} ·
                                {{ label(f.review_status) }}</small
                            >
                        </button>
                        <p v-if="!filtered.length" class="pane-empty">
                            {{
                                checked === 0
                                    ? "Belum ada hasil pembacaan. Mulai pemeriksaan untuk melihat temuan."
                                    : "Tidak ada temuan pada filter ini. Pilih seluruh halaman atau tambahkan catatan manual."
                            }}
                        </p>
                    </div>
                    <FindingEditor
                        v-if="selected || adding"
                        :key="
                            selected
                                ? `${selected.id}-${selected.version}`
                                : 'new'
                        "
                        ref="editor"
                        :finding="selected"
                        :publication="publication"
                        :rules="rules"
                        :can-edit="canEdit"
                        :page="currentPage.physical_page"
                        @evidence="pageTo"
                        @saved="adding = false"
                    />
                </aside></div
        ></template>
        <section v-if="section === 'exports'" class="exports-panel">
            <div class="section-heading">
                <div>
                    <h2>Lembar revisi yang siap dibagikan</h2>
                    <p>
                        Excel dibuat otomatis setelah semua halaman dan
                        pemeriksaan silang selesai.
                    </p>
                </div>
                <button
                    class="button primary"
                    :disabled="!ready || busy"
                    @click="action('/exports')"
                >
                    <FileSpreadsheet :size="18" />Buat Excel versi baru
                </button>
            </div>
            <div v-if="!exports.length" class="empty-state">
                <FileSpreadsheet :size="44" />
                <h3>Excel belum tersedia</h3>
                <p>
                    {{
                        checked < publication.page_count
                            ? "Selesaikan seluruh halaman terlebih dahulu."
                            : "Pemeriksaan silang dan pembuatan Excel akan berjalan otomatis."
                    }}
                </p>
            </div>
            <article v-for="e in exports" :key="e.id" class="export-item">
                <span class="file-icon"><FileSpreadsheet /></span>
                <div>
                    <h3>Hasil pemeriksaan · Versi {{ e.version }}</h3>
                    <p>
                        Revisi {{ e.publication_revision }} ·
                        {{ e.finding_count ?? 0 }} temuan ·
                        {{ new Date(e.created_at).toLocaleString("id-ID") }}
                    </p>
                    <small
                        v-if="e.publication_revision < publication.revision"
                        class="unsaved"
                        >Belum memuat perubahan terbaru. Buat versi baru.</small
                    >
                    <p v-if="e.error" class="field-error">{{ e.error }}</p>
                    <details v-if="e.checksum">
                        <summary>Checksum file</summary>
                        <code>{{ e.checksum }}</code>
                    </details>
                </div>
                <StatusBadge :status="e.status" /><a
                    v-if="e.status === 'complete'"
                    :href="`${root}/exports/${e.id}`"
                    class="button secondary"
                    ><Download :size="17" />Unduh</a
                >
            </article>
        </section>
        <section v-if="section === 'manage'" class="manage-panel">
            <div v-if="shared.props.auth.user.role === 'admin'">
                <h2>Penugasan pegawai</h2>
                <p>
                    Pegawai yang ditugaskan bisa membuka dan memeriksa publikasi
                    ini.
                </p>
                <label v-for="u in employees" :key="u.id" class="checkbox"
                    ><input
                        type="checkbox"
                        :value="u.id"
                        v-model="assigned"
                    />{{ u.name }} <small>{{ u.role }}</small></label
                ><button
                    class="button secondary"
                    :disabled="busy"
                    @click="action('/assign', { users: assigned })"
                >
                    Simpan penugasan
                </button>
            </div>
            <div v-if="canReview">
                <h2>Keputusan final</h2>
                <p>
                    {{ remainingReview }} temuan masih membutuhkan keputusan.
                    Hasil dikunci setelah semuanya ditinjau dan Excel revisi
                    terbaru selesai dibuat.
                </p>
                <button
                    v-if="!publication.locked_at"
                    class="button primary"
                    :disabled="!ready || remainingReview > 0 || busy"
                    @click="action('/lock')"
                >
                    <Lock :size="17" />Kunci hasil final</button
                ><button
                    v-else
                    class="button secondary"
                    :disabled="busy"
                    @click="action('/unlock')"
                >
                    <Unlock :size="17" />Buka untuk penyuntingan
                </button>
            </div>
        </section>
    </AppLayout>
</template>
