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
    Upload,
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
import AdditionalChecksPanel from "../../Components/AdditionalChecksPanel.vue";
import ReferenceEvidencePanel from "../../Components/ReferenceEvidencePanel.vue";
import { askConfirmation, confirmation } from "../../Components/confirmation";
import {
    label,
    type Publication,
    type PageRecord,
    type Finding,
    type ExportRecord,
    type Employee,
    type Shared,
    type CoverageSummary,
    type CheckRunRecord,
    type FindingAssessmentRecord,
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
    publicationAgents?: { id: number; slot: number; name: string; model: string }[] | null;
    readyAgentsCount?: number;
    coverage?: CoverageSummary | null;
    checkRuns?: CheckRunRecord[] | null;
    assessments?: FindingAssessmentRecord[] | null;
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
const agreement = ref("");
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
const incomplete = computed(() =>
    props.pages.filter((p) => p.status !== "complete"),
);
const latestExport = computed(() =>
    props.exports.find((e) => e.status === "complete"),
);
const showDownloadModal = ref(false);
const downloadTargetExport = ref<ExportRecord | null>(null);
const downloadFormat = ref<"standard" | "internal">("standard");

function triggerDownloadModal(exportRecord?: ExportRecord | null) {
    downloadTargetExport.value = exportRecord || latestExport.value || null;
    if (downloadTargetExport.value) {
        showDownloadModal.value = true;
    }
}
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
            (!agreement.value || f.agreement === agreement.value) &&
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
async function canLeave() {
    return (
        !editor.value?.dirty() ||
        (await askConfirmation({
            title: "Tinggalkan perubahan?",
            message:
                "Perubahan temuan Anda belum disimpan. Jika dilanjutkan, perubahan ini akan ditinggalkan.",
            note: "Hasil pemeriksaan yang sudah tersimpan tetap aman.",
            confirmLabel: "Tinggalkan perubahan",
            cancelLabel: "Kembali menyunting",
            tone: "warning",
        }))
    );
}
async function switchSection(next: string) {
    if (await canLeave()) section.value = next;
}
async function pageTo(n: number) {
    if (n < 1 || n > props.publication.page_count || !(await canLeave()))
        return;
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
async function choose(f: Finding) {
    if (!(await canLeave())) return;
    selectedId.value = f.id;
    adding.value = false;
    mobileTab.value = "findings";
}
async function add() {
    if (!(await canLeave())) return;
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
async function rebindAi() {
    if (
        !busy.value &&
        (await canLeave()) &&
        (await askConfirmation({
            title: "Gunakan koneksi AI terbaru?",
            message:
                "Halaman berikutnya akan memakai koneksi yang sudah diuji admin. Model, penyedia, dan biaya dapat berubah.",
            note: "Halaman selesai tidak diperiksa ulang. Pergantian koneksi dicatat dalam riwayat.",
            confirmLabel: "Gunakan koneksi terbaru",
            tone: "refresh",
        }))
    ) {
        action("/rebind-ai", { confirm: true });
    }
}
async function exportPartial() {
    if (busy.value || !(await canLeave())) return;
    if (
        await askConfirmation({
            title: "Buat Excel sementara?",
            message: `${checked.value} dari ${props.publication.page_count} halaman sudah selesai dan akan dimasukkan ke Excel.`,
            detail: "File diberi label BELUM LENGKAP. Halaman lainnya dan pemeriksaan silang tidak dianggap selesai.",
            note: "Hasil tersimpan tidak dihapus. Anda tetap bisa melanjutkan pemeriksaan nanti.",
            confirmLabel: "Buat Excel sementara",
            tone: "export",
        })
    ) {
        section.value = "exports";
        action("/exports/partial", { confirm: true });
    }
}
async function confirmFinal(lock: boolean) {
    if (busy.value || !(await canLeave())) return;
    if (
        await askConfirmation({
            title: lock ? "Kunci hasil final?" : "Buka kembali penyuntingan?",
            message: lock
                ? "Hasil pemeriksaan akan dikunci. Temuan tidak dapat disunting sampai hasil dibuka kembali oleh pemeriksa yang berwenang."
                : "Temuan dapat disunting kembali. Perubahan berikutnya perlu ditinjau sebelum hasil dikunci lagi.",
            note: "Hasil pemeriksaan dan riwayat perubahan tetap tersimpan.",
            confirmLabel: lock ? "Kunci hasil final" : "Buka penyuntingan",
            tone: "lock",
        })
    )
        action(lock ? "/lock" : "/unlock");
}
function uploadReviewed(exp: ExportRecord, ev: Event) {
    const target = ev.target as HTMLInputElement;
    const file = target?.files?.[0];
    if (!file) return;
    const form = new FormData();
    form.append("file", file);
    router.post(`${root.value}/exports/${exp.id}/reviewed`, form, {
        onSuccess: () => {
            if (target) target.value = "";
        },
    });
}

let timer: ReturnType<typeof setInterval>;
let removeNavigationGuard: (() => void) | undefined;
let navigationApproved = false;
onMounted(() => {
    removeNavigationGuard = router.on("before", (event) => {
        if (
            event.detail.visit.method === "get" &&
            event.detail.visit.url.pathname !== root.value &&
            editor.value?.dirty()
        ) {
            if (navigationApproved) {
                navigationApproved = false;
                return;
            }
            const visit = event.detail.visit;
            void canLeave().then((accepted) => {
                if (accepted) {
                    navigationApproved = true;
                    router.visit(visit.url, visit);
                }
            });
            return false;
        }
    });
    timer = setInterval(() => {
        if (
            (running.value ||
                props.exports.some((e) =>
                    ["pending", "processing"].includes(e.status),
                )) &&
            !editor.value?.dirty() &&
            !busy.value &&
            !confirmation.value
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
                    {{ publication.rule_version }} · Revisi {{ publication.revision }}
                    <template v-if="publicationAgents && publicationAgents.length">
                        · Agen Aktif: <strong v-for="(ag, idx) in publicationAgents" :key="ag.id">{{ ag.name }}{{ idx < publicationAgents.length - 1 ? ', ' : '' }}</strong>
                    </template>
                    <template v-if="publication.cross_verify_mode">
                        · Mode Verifikasi: <em>{{ publication.cross_verify_mode === 'high_risk' ? 'Resiko Tinggi & Tabel' : (publication.cross_verify_mode === 'all_pages' ? 'Semua Halaman' : 'Nonaktif') }}</em>
                    </template>
                    <template v-if="publication.audit_mode">
                        · Metode: <strong>{{ publication.audit_mode === 'machine' ? '⚡ Mesin Kilat' : (publication.audit_mode === 'fusion' ? '🧬 Fusion Dual-Engine' : '✨ Full Vision AI') }}</strong>
                    </template>
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
                ><button
                    v-if="latestExport"
                    type="button"
                    class="button primary"
                    @click="triggerDownloadModal(latestExport)"
                >
                    <Download :size="17" />{{
                        latestExport.validation?.mode === "partial"
                            ? "Unduh Excel sementara"
                            : "Unduh Excel"
                    }}</button
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
        <div
            v-if="publication.error || incomplete.length"
            class="notice warning"
            role="status"
        >
            <p>
                <strong>Pemeriksaan belum lengkap.</strong>
                {{ publication.error }} {{ incomplete.length }} halaman belum
                selesai; {{ blocked.length }} gagal atau perlu verifikasi. Hasil
                yang sudah tersimpan tetap dipertahankan. Excel lengkap menunggu
                seluruh halaman dan pemeriksaan silang selesai.
            </p>
            <details v-if="incomplete.length">
                <summary>Lihat halaman yang belum selesai</summary>
                <ul>
                    <li v-for="p in incomplete" :key="p.physical_page">
                        <button
                            class="button secondary"
                            @click="pageTo(p.physical_page)"
                        >
                            PDF {{ p.physical_page }}
                        </button>
                        {{ label(p.status)
                        }}{{ p.error ? ` — ${p.error}` : "" }}
                    </li>
                </ul>
            </details>
            <button
                v-if="canEdit && checked > 0 && !ready"
                class="button secondary"
                :disabled="busy"
                :aria-busy="busy"
                @click="exportPartial"
            >
                <FileSpreadsheet :size="18" />{{
                    busy ? "Memproses..." : "Buat Excel sementara"
                }}
            </button>
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
                            }}</span>
                            <span>
                                <strong>PDF {{ p.physical_page }}</strong>
                                <small>
                                    {{ p.printed_page ? `Tercetak ${p.printed_page}` : label(p.status) }}
                                    <template v-if="p.primary_agent_slot">
                                        · A{{ p.primary_agent_slot }}<span v-if="p.verifier_agent_slot">·A{{ p.verifier_agent_slot }}</span>
                                    </template>
                                </small>
                            </span>
                            <i
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
                    <div class="viewer-footer" style="flex-wrap: wrap; gap: 8px;">
                        <StatusBadge :status="currentPage.status" />
                        <small>
                            {{ currentPage.page_type ?? "Jenis halaman belum ditentukan" }}
                            {{ currentPage.printed_page ? ` · Tercetak ${currentPage.printed_page}` : "" }}
                        </small>
                        <span v-if="currentPage.primary_agent" class="badge" style="background: #e9ecef; color: #495057;">
                            Dibaca: {{ currentPage.primary_agent.name }}
                        </span>
                        <template v-if="currentPage.verification">
                            <span v-if="currentPage.verification.status === 'completed'" class="badge confirmed" :title="`Verifikasi silang selesai oleh ${currentPage.verification.agent?.name ?? 'Agent 2'}`">
                                ✓ Diverifikasi: {{ currentPage.verification.agent?.name ?? 'Agent 2' }}
                            </span>
                            <span v-else-if="currentPage.verification.status === 'running'" class="badge analyzing">
                                Sedang diverifikasi: {{ currentPage.verification.agent?.name ?? 'Agent' }}...
                            </span>
                            <span v-else-if="currentPage.verification.status === 'skipped'" class="badge single-agent" :title="currentPage.verification.error ?? ''">
                                Verifikasi silang: dilewati
                            </span>
                        </template>
                        <button
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
                    <p
                        v-if="currentPage.error"
                        class="notice warning"
                        role="status"
                    >
                        {{ currentPage.error }}
                    </p>
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
                        <div class="form-grid" style="margin-top: 6px;">
                            <select
                                v-model="agreement"
                                aria-label="Filter verifikasi agen"
                            >
                                <option value="">Semua status agen</option>
                                <option value="confirmed">2 Agen Sepakat (Confirmed)</option>
                                <option value="single_agent">Hanya 1 Agen (Single Agent)</option>
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
                            <div class="inline-actions" style="margin-bottom: 4px; gap: 6px;">
                                <span :class="['severity', f.severity]">{{
                                    f.severity === "wajib"
                                        ? "Wajib diperbaiki"
                                        : f.severity === "saran"
                                          ? "Saran"
                                          : "Perlu verifikasi"
                                }}</span>
                                <span v-if="f.agreement === 'confirmed'" class="badge confirmed" title="Dikonfirmasi oleh 2 Agen AI">
                                    2 Agen
                                </span>
                                <span v-else-if="f.agreement === 'single_agent'" class="badge single-agent" title="Hanya terdeteksi oleh 1 Agen AI">
                                    1 Agen
                                </span>
                            </div>
                            <strong>{{ f.observed }}</strong>
                            <small>{{ f.rule_code }} · {{ label(f.review_status) }}</small>
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
                </aside>
            </div>
            <AdditionalChecksPanel
                :publication-id="publication.id"
                :can-edit="canEdit"
                :coverage="coverage"
                :check-runs="checkRuns"
            />
        </template>
        <section v-if="section === 'exports'" class="exports-panel">
            <div class="section-heading">
                <div>
                    <h2>Lembar revisi yang siap dibagikan</h2>
                    <p>
                        Excel lengkap dibuat otomatis setelah semua halaman dan
                        pemeriksaan silang selesai. Excel sementara dapat dibuat
                        dari hasil tersimpan tanpa menunggu AI.
                    </p>
                </div>
                <button
                    class="button primary"
                    :disabled="!ready || busy"
                    @click="action('/exports')"
                >
                    <FileSpreadsheet :size="18" />Buat Excel versi baru
                </button>
                <button
                    v-if="canEdit && !ready && checked > 0"
                    class="button secondary"
                    :disabled="busy"
                    :aria-busy="busy"
                    @click="exportPartial"
                >
                    <FileSpreadsheet :size="18" />{{
                        busy ? "Memproses..." : "Buat Excel sementara"
                    }}
                </button>
            </div>
            <div v-if="!exports.length" class="empty-state">
                <FileSpreadsheet :size="44" />
                <h3>Excel belum tersedia</h3>
                <p>
                    {{
                        checked < publication.page_count
                            ? "Lanjutkan pemeriksaan, atau buat Excel sementara dari halaman yang sudah selesai."
                            : "Pemeriksaan silang dan pembuatan Excel akan berjalan otomatis."
                    }}
                </p>
            </div>
            <article v-for="e in exports" :key="e.id" class="export-item">
                <span class="file-icon"><FileSpreadsheet /></span>
                <div>
                    <h3>
                        {{
                            e.validation?.mode === "partial"
                                ? "Excel sementara — BELUM LENGKAP"
                                : e.validation?.mode === "complete_with_limitations"
                                ? "Hasil pemeriksaan — DENGAN KETERBATASAN"
                                : "Hasil pemeriksaan"
                        }}
                        · Versi {{ e.version }}
                    </h3>
                    <p v-if="e.validation?.mode === 'partial'">
                        {{ e.validation.checked_pages }} dari
                        {{ e.validation.total_pages }} halaman diperiksa saat
                        file dibuat. Belum selesai: PDF
                        {{
                            e.validation.unchecked_pages
                                ?.map((p) => p.physical_page)
                                .join(", ") ||
                            "tidak ada; pemeriksaan silang belum dinyatakan lengkap"
                        }}.
                    </p>
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
                <StatusBadge :status="e.status" />
                <div class="export-actions" style="display: flex; gap: 8px; align-items: center;">
                    <button
                        v-if="e.status === 'complete'"
                        type="button"
                        class="button secondary"
                        @click="triggerDownloadModal(e)"
                    >
                        <Download :size="17" />Unduh
                    </button>
                    <label
                        v-if="e.status === 'complete' && canReview"
                        class="button secondary"
                        style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
                        title="Unggah Excel yang telah Anda sunting/hapus temuan yang tidak valid"
                    >
                        <Upload :size="17" />Unggah Balik Review
                        <input
                            type="file"
                            accept=".xlsx"
                            style="display: none;"
                            @change="(ev) => uploadReviewed(e, ev)"
                        />
                    </label>
                </div>
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
                    @click="confirmFinal(true)"
                >
                    <Lock :size="17" />Kunci hasil final</button
                ><button
                    v-else
                    class="button secondary"
                    :disabled="busy"
                    @click="confirmFinal(false)"
                >
                    <Unlock :size="17" />Buka untuk penyuntingan
                </button>
            </div>
            <ReferenceEvidencePanel
                :publication-id="publication.id"
                :can-review="canReview"
                :check-runs="checkRuns"
                :assessments="assessments ?? []"
            />
        </section>

        <!-- Modal Pilihan Format Download Excel -->
        <Teleport to="body">
            <div 
                v-if="showDownloadModal && downloadTargetExport" 
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
                @click.self="showDownloadModal = false"
            >
                <div class="bg-white dark:bg-slate-900 rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 text-left">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-base font-bold text-slate-900 dark:text-slate-100 flex items-center gap-2">
                            <FileSpreadsheet class="text-emerald-600 dark:text-emerald-400" :size="20" />
                            Pilih Format Laporan Excel
                        </h3>
                        <button 
                            @click="showDownloadModal = false" 
                            class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-lg font-bold leading-none"
                        >
                            &times;
                        </button>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4 leading-relaxed">
                        Tentukan tingkat detail metadata pemeriksaan pada file Excel (.xlsx):
                    </p>

                    <div class="space-y-3 mb-6">
                        <!-- Opsi 1: Format Standar Resmi BPS (Default) -->
                        <div 
                            @click="downloadFormat = 'standard'"
                            :class="[
                                'p-3.5 rounded-xl border-2 cursor-pointer transition flex items-start gap-3',
                                downloadFormat === 'standard' 
                                    ? 'border-indigo-600 bg-indigo-50/40 dark:bg-indigo-950/20 ring-1 ring-indigo-500/20' 
                                    : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/50'
                            ]"
                        >
                            <input type="radio" value="standard" v-model="downloadFormat" class="mt-1" />
                            <div>
                                <strong class="text-xs font-bold block text-slate-900 dark:text-slate-100">Format Standar Resmi BPS (Default)</strong>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-tight block mt-0.5">
                                    Sesuai format baku instrumen pemeriksaan BPS tanpa kolom teknis sistem. Siap dibagikan ke pimpinan atau daerah.
                                </span>
                            </div>
                        </div>

                        <!-- Opsi 2: Format Internal Diagnostik -->
                        <div 
                            @click="downloadFormat = 'internal'"
                            :class="[
                                'p-3.5 rounded-xl border-2 cursor-pointer transition flex items-start gap-3',
                                downloadFormat === 'internal' 
                                    ? 'border-emerald-600 bg-emerald-50/40 dark:bg-emerald-950/20 ring-1 ring-emerald-500/20' 
                                    : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/50'
                            ]"
                        >
                            <input type="radio" value="internal" v-model="downloadFormat" class="mt-1" />
                            <div>
                                <strong class="text-xs font-bold block text-slate-900 dark:text-slate-100 flex items-center gap-1.5">
                                    Format Audit Lengkap (Internal)
                                    <span class="text-[9px] px-1.5 py-0.2 bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 rounded font-semibold">Diagnostik</span>
                                </strong>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-tight block mt-0.5">
                                    Menyertakan baris <b>"Terdeteksi Oleh"</b> (Mesin Heuristik, Vision AI, atau Keduanya) serta status rekonsiliasi Fusion.
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <button 
                            type="button" 
                            @click="showDownloadModal = false" 
                            class="button secondary text-xs py-2 px-3"
                        >
                            Batal
                        </button>
                        <a 
                            :href="`${root}/exports/${downloadTargetExport.id}?format=${downloadFormat}`"
                            @click="showDownloadModal = false"
                            class="button primary text-xs py-2 px-3 flex items-center gap-1.5"
                        >
                            <Download :size="15" />
                            Unduh File (.xlsx)
                        </a>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
