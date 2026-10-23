<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import {
    BookOpenCheck,
    ShieldCheck,
    LayoutGrid,
    Table as TableIcon,
    BarChart2,
    Languages,
    BookMarked,
    Search,
    ChevronDown,
    ChevronUp,
    CheckCircle2,
    AlertTriangle,
    Info,
    ArrowRight,
    FileSpreadsheet,
    Bot,
    Image as ImageIcon,
    Columns2,
    GitCompare,
    FileSearch,
    Sparkles,
    Check,
    X,
    SlidersHorizontal,
} from "lucide-vue-next";
import AppLayout from "../../Layouts/AppLayout.vue";
import type {
    RuleGuideItem,
    RuleCategory,
    WorkflowStage,
    RuleGuideSummary,
} from "../../types";

const props = defineProps<{
    version: string;
    target_version: string;
    summary: RuleGuideSummary;
    categories: Record<string, RuleCategory>;
    stages: WorkflowStage[];
    rules: RuleGuideItem[];
}>();

// State
const searchQuery = ref("");
const selectedCategory = ref<string>("all");
const selectedSeverity = ref<string>("all");
const selectedMode = ref<string>("all");
const expandedRules = ref<Record<string, boolean>>({});
const activeVisualTab = ref<"cover" | "table" | "spread">("cover");
const activeStageStep = ref<number>(1);

// Helper for expand/collapse
const toggleRule = (code: string) => {
    expandedRules.value[code] = !expandedRules.value[code];
};

const expandAll = () => {
    props.rules.forEach((r) => {
        expandedRules.value[r.code] = true;
    });
};

const collapseAll = () => {
    expandedRules.value = {};
};

// Filtered rules
const filteredRules = computed(() => {
    return props.rules.filter((rule) => {
        // Search query
        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase();
            const matchCode = rule.code.toLowerCase().includes(q);
            const matchTitle = rule.title.toLowerCase().includes(q);
            const matchSummary = rule.summary.toLowerCase().includes(q);
            const matchHow = rule.how_it_works.toLowerCase().includes(q);
            const matchLogic = rule.logic.toLowerCase().includes(q);
            const matchSpec = rule.spec_ref.toLowerCase().includes(q);
            if (!matchCode && !matchTitle && !matchSummary && !matchHow && !matchLogic && !matchSpec) {
                return false;
            }
        }

        // Category filter
        if (selectedCategory.value !== "all" && rule.category !== selectedCategory.value) {
            return false;
        }

        // Severity filter
        if (selectedSeverity.value !== "all" && rule.severity !== selectedSeverity.value) {
            return false;
        }

        // Mode filter
        if (selectedMode.value !== "all" && rule.mode !== selectedMode.value) {
            return false;
        }

        return true;
    });
});

// Category icon component mapper
const getCategoryIcon = (iconName: string) => {
    switch (iconName) {
        case "BookMarked":
            return BookMarked;
        case "ShieldCheck":
            return ShieldCheck;
        case "LayoutGrid":
            return LayoutGrid;
        case "Table":
            return TableIcon;
        case "BarChart2":
            return BarChart2;
        case "Languages":
            return Languages;
        default:
            return BookOpenCheck;
    }
};

const getStageIcon = (iconName: string) => {
    switch (iconName) {
        case "FileSearch":
            return FileSearch;
        case "Image":
            return ImageIcon;
        case "Bot":
            return Bot;
        case "Columns2":
            return Columns2;
        case "GitCompare":
            return GitCompare;
        case "FileSpreadsheet":
            return FileSpreadsheet;
        default:
            return Sparkles;
    }
};

const getSeverityLabel = (sev: string) => {
    switch (sev) {
        case "wajib":
            return "Wajib Diperbaiki";
        case "saran":
            return "Saran Kualitas";
        case "verifikasi":
            return "Perlu Verifikasi";
        default:
            return sev;
    }
};

const getModeLabel = (mode: string) => {
    switch (mode) {
        case "deterministic":
            return "Deterministik (Kode Murni)";
        case "ai_page":
            return "Vision AI (Per Halaman)";
        case "ai_pair":
            return "Vision AI (Pasangan Kover-Judul)";
        case "external_evidence":
            return "Rujukan Eksternal & Web";
        case "manual":
            return "Telaah Manual";
        default:
            return mode;
    }
};
</script>

<template>
    <Head title="Katalog & Logika Aturan Pemeriksaan" />
    <AppLayout>
        <!-- Hero Section -->
        <section class="welcome-band rules-hero">
            <div>
                <div class="rules-version-badge">
                    <Sparkles :size="15" />
                    <span>Authoritative Rules V2 · {{ version }}</span>
                </div>
                <h1>Katalog & Logika Aturan Periksa</h1>
                <p>
                    Pelajari seluruh aturan audit mutu publikasi BPS (KCDA & Daerah Dalam Angka).
                    Pahami bagaimana tiap aturan dievaluasi, logika algoritma & AI di baliknya,
                    serta gambaran visual standar baku yang ditetapkan.
                </p>
                <div class="hero-actions">
                    <a href="#pipeline" class="button secondary compact">
                        <GitCompare :size="16" />
                        <span>Lihat Alur Kerja Sistem</span>
                    </a>
                    <a href="#visual-guides" class="button secondary compact">
                        <ImageIcon :size="16" />
                        <span>Panduan Visual Baku</span>
                    </a>
                    <a href="#rules-catalogue" class="button primary compact">
                        <Search :size="16" />
                        <span>Jelajahi {{ summary.total_rules }} Aturan</span>
                    </a>
                </div>
            </div>
            <div class="welcome-art" aria-hidden="true">
                <span class="paper-shape">
                    <BookOpenCheck :size="64" />
                    <i></i><i></i><i></i>
                </span>
                <span class="art-spark">✦</span>
                <span class="art-dot"></span>
            </div>
        </section>

        <!-- Summary Metrics Cards -->
        <div class="metrics rules-metrics">
            <div>
                <BookOpenCheck :size="24" />
                <strong>{{ summary.total_rules }}</strong>
                <span>Total Aturan Terdaftar</span>
            </div>
            <div class="metric-wajib">
                <AlertTriangle :size="24" />
                <strong>{{ summary.mandatory_count }}</strong>
                <span>Aturan Wajib (Blokir Ekspor)</span>
            </div>
            <div class="metric-saran">
                <Info :size="24" />
                <strong>{{ summary.advisory_count }}</strong>
                <span>Saran Kualitas Layout</span>
            </div>
            <div class="metric-verifikasi">
                <GitCompare :size="24" />
                <strong>{{ summary.verification_count }}</strong>
                <span>Verifikasi Angka / Data</span>
            </div>
        </div>

        <!-- Section 1: Alur Kerja & Pipeline Sistem -->
        <section id="pipeline" class="rules-card-section">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Arsitektur Audit Otomatis</span>
                    <h2>Bagaimana Sistem Memeriksa Publikasi?</h2>
                    <p>
                        Pemeriksaan tidak dilakukan dengan OCR teks mentah semata, melainkan melalui 6 tahap orkestrasi
                        multimodal yang menggabungkan Poppler, Vision LLM, algoritma deterministik, dan verifikasi web resmi BPS.
                    </p>
                </div>
            </div>

            <!-- Pipeline Visual Steps -->
            <div class="pipeline-grid">
                <div
                    v-for="st in stages"
                    :key="st.step"
                    :class="['pipeline-step-card', { active: activeStageStep === st.step }]"
                    @click="activeStageStep = st.step"
                >
                    <div class="pipeline-header">
                        <span class="pipeline-number">0{{ st.step }}</span>
                        <component :is="getStageIcon(st.icon)" :size="22" class="pipeline-icon" />
                    </div>
                    <h4>{{ st.title }}</h4>
                    <span class="pipeline-badge">{{ st.badge }}</span>
                    <p class="pipeline-desc">{{ st.description }}</p>
                    <div class="pipeline-logic-box">
                        <strong>Logika Kunci:</strong>
                        <span>{{ st.logic }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Section 2: Gambaran Visual Standar Publikasi BPS -->
        <section id="visual-guides" class="rules-card-section">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Ilustrasi & Gambaran Baku</span>
                    <h2>Gambaran Visual Standar Publikasi BPS</h2>
                    <p>
                        Visualisasi konkret elemen kover, layout cetak buku 2-up, dan kaidah tabel terbuka
                        agar mudah dipahami oleh penyusun dan penelaah publikasi.
                    </p>
                </div>
            </div>

            <!-- Visual Tabs Navigation -->
            <div class="visual-tabs">
                <button
                    type="button"
                    :class="['visual-tab-btn', { active: activeVisualTab === 'cover' }]"
                    @click="activeVisualTab = 'cover'"
                >
                    <BookMarked :size="18" />
                    <span class="hidden sm:inline">Anatomi Kover & Halaman Judul</span>
                    <span class="inline sm:hidden">Kover & Judul</span>
                </button>
                <button
                    type="button"
                    :class="['visual-tab-btn', { active: activeVisualTab === 'table' }]"
                    @click="activeVisualTab = 'table'"
                >
                    <TableIcon :size="18" />
                    <span class="hidden sm:inline">Format Tabel Terbuka BPS</span>
                    <span class="inline sm:hidden">Format Tabel</span>
                </button>
                <button
                    type="button"
                    :class="['visual-tab-btn', { active: activeVisualTab === 'spread' }]"
                    @click="activeVisualTab = 'spread'"
                >
                    <Columns2 :size="18" />
                    <span class="hidden sm:inline">Tata Letak Cetak 2-Up & Halaman Romawi</span>
                    <span class="inline sm:hidden">Tata Letak 2-Up</span>
                </button>
            </div>

            <!-- Visual Tab Content 1: Kover -->
            <div v-if="activeVisualTab === 'cover'" class="visual-mockup-wrapper">
                <div class="mockup-explanation">
                    <h3>Standar Kover Depan, Halaman Judul & Kover Belakang</h3>
                    <p>
                        Kover merupakan gerbang identitas publikasi resmi BPS. Seluruh komponen penomoran, hierarki judul,
                        dan logo memiliki ketentuan baku yang wajib dipatuhi:
                    </p>
                    <ul class="visual-checklist">
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Nomor Katalog:</strong> Pojok kanan atas, format satu bahasa: <code>Katalog: 1102001.72</code>, dwibahasa: <code>Katalog/<em>Catalogue</em>: 1102001.72</code>.
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>ISSN Kover:</strong> Di bawah katalog, <strong>wajib tanpa titik dua</strong>: <code>ISSN 0215-6768</code>.
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Judul Dwibahasa:</strong> Judul Indonesia dan Inggris kontras jelas terhadap foto kover, font non-dekoratif.
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Logo BPS:</strong> Ditempatkan di bagian bawah, <strong>bebas efek digital</strong> (dilarang ada drop-shadow, emboss, atau outline).
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Halaman Judul:</strong> Berlatar <strong>putih bersih</strong> (#FFFFFF) tanpa foto/gambar kover yang ikut terbawa.
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Barcode Kover Belakang:</strong> Barcode resmi ISSN terletak di kover belakang dengan teks angka terbaca.
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="mockup-diagram cover-diagram">
                    <div class="mockup-book-cover">
                        <div class="mockup-katalog-box">
                            <span class="diagram-tag">§2.2 Katalog (Kanan Atas)</span>
                            <div class="mockup-meta-line">Katalog/<em>Catalogue</em>: 1102001.72</div>
                            <div class="mockup-meta-line">ISSN 2549-1234 <span class="badge-mini-pass">Tanpa Titik Dua</span></div>
                        </div>
                        <div class="mockup-title-box">
                            <span class="diagram-tag">§2.4 Judul Dwibahasa & Tahun Data</span>
                            <div class="mockup-main-title">KECAMATAN AMPANA KOTA DALAM ANGKA</div>
                            <div class="mockup-sub-title"><em>Ampana Kota Subdistrict in Figures</em></div>
                            <div class="mockup-year">2026</div>
                        </div>
                        <div class="mockup-photo-area">
                            <div class="mockup-scrim-note">Area Foto Kover dengan Perlindungan Kontras Teks</div>
                        </div>
                        <div class="mockup-logo-box">
                            <span class="diagram-tag">§2.1 Logo BPS Flat (Tanpa Shadow)</span>
                            <div class="mockup-logo-brand">
                                <span class="mockup-logo-shape"></span>
                                <strong>BADAN PUSAT STATISTIK KABUPATEN TOJO UNA-UNA</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Visual Tab Content 2: Tabel -->
            <div v-if="activeVisualTab === 'table'" class="visual-mockup-wrapper">
                <div class="mockup-explanation">
                    <h3>Standar Format Tabel Terbuka BPS (Open Table Format)</h3>
                    <p>
                        Tabel publikasi BPS dirancang untuk keterbacaan data yang bersih, elegan, dan profesional.
                        BPS secara tegas melarang tabel kotak jaring tertutup:
                    </p>
                    <ul class="visual-checklist">
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>3 Garis Horizontal Utama:</strong> (1) Garis atas header, (2) Garis bawah header, dan (3) Garis bawah tabel (penutup Total).
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Tanpa Garis Vertikal:</strong> Sama sekali tidak diperbolehkan ada garis vertikal pemisah kolom (tabel terbuka).
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Judul & Satuan:</strong> Mencantumkan Apa, Di mana, Satuan Unit dalam kurung (ha, jiwa, ton), dan Tahun Data.
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Konsistensi Baris Total:</strong> Nilai pada baris Total wajib sama dengan jumlahan baris komponen (|sum - total| &le; 0.01).
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Simbol Statistik:</strong> Sel kosong wajib diisi tanda minus (-) bila nol, atau titik-titik (...) bila belum tersedia.
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="mockup-diagram table-diagram">
                    <div class="mockup-bps-table">
                        <div class="mockup-table-title-area">
                            <strong>Tabel 3.1</strong> Luas Lahan Sawah Menurut Desa di Kecamatan Ampana Kota (ha), 2025
                            <div class="mockup-table-title-en"><em>Table 3.1 Wetland Area by Village in Ampana Kota Subdistrict (ha), 2025</em></div>
                        </div>
                        <table class="bps-sample-table">
                            <thead>
                                <tr class="border-top-thick">
                                    <th>Desa / Kelurahan<br><em>Village / Urban Village</em></th>
                                    <th>Irigasi<br><em>Irrigated</em></th>
                                    <th>Non Irigasi<br><em>Non Irrigated</em></th>
                                    <th>Jumlah<br><em>Total</em></th>
                                </tr>
                                <tr class="table-sub-header border-bottom-thin">
                                    <th>(1)</th>
                                    <th>(2)</th>
                                    <th>(3)</th>
                                    <th>(4) = (2) + (3)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>001. Labuan</td>
                                    <td class="text-right">120,50</td>
                                    <td class="text-right">45,20</td>
                                    <td class="text-right">165,70</td>
                                </tr>
                                <tr>
                                    <td>002. Bailo</td>
                                    <td class="text-right">85,00</td>
                                    <td class="text-right">-</td>
                                    <td class="text-right">85,00</td>
                                </tr>
                                <tr>
                                    <td>003. Sansarino</td>
                                    <td class="text-right">210,30</td>
                                    <td class="text-right">62,80</td>
                                    <td class="text-right">273,10</td>
                                </tr>
                                <tr>
                                    <td>004. Bonerato</td>
                                    <td class="text-right">...</td>
                                    <td class="text-right">...</td>
                                    <td class="text-right">...</td>
                                </tr>
                                <tr class="total-row border-top-thin border-bottom-thick">
                                    <td><strong>Jumlah / <em>Total</em></strong></td>
                                    <td class="text-right"><strong>415,80</strong></td>
                                    <td class="text-right"><strong>108,00</strong></td>
                                    <td class="text-right"><strong>523,80</strong></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="mockup-table-notes">
                            <div>Catatan / <em>Note</em>: - : data tidak ada / <em>data not available</em>; ... : data belum tersedia</div>
                            <div>Sumber / <em>Source</em>: Dinas Pertanian Kabupaten Tojo Una-Una</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Visual Tab Content 3: Spread / Layout Cetak -->
            <div v-if="activeVisualTab === 'spread'" class="visual-mockup-wrapper">
                <div class="mockup-explanation">
                    <h3>Kaidah Penomoran Halaman & Tata Letak Buku Cetak (2-Up Spread)</h3>
                    <p>
                        Publikasi BPS dicetak bolak-balik dengan layout buku berpasangan (halaman genap di kiri,
                        halaman ganjil di kanan). Aturan penomoran menjaga agar struktur buku tidak cacat saat dijilid:
                    </p>
                    <ul class="visual-checklist">
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Pendahuluan Berakhir Genap:</strong> Bagian romawi wajib berakhir genap (misal: xxiv). Jika berakhir ganjil, Bab 1 akan jatuh di halaman kiri (salah).
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Awal Bab Selalu di Kanan (Ganjil):</strong> Bab 1, Bab 2, dan halaman pembatas bab wajib selalu dimulai pada halaman ganjil (sisi kanan).
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Running Title:</strong> Halaman genap (kiri) = Judul Buku; Halaman ganjil (kanan) = Judul Bab aktif.
                            </div>
                        </li>
                        <li>
                            <CheckCircle2 :size="16" class="text-success checklist-icon" />
                            <div class="checklist-text">
                                <strong>Halaman Kosong Penyeimbang:</strong> Halaman kosong (blank page) penyeimbang cetak <strong>dilarang memiliki running title atau nomor halaman</strong>.
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="mockup-diagram spread-diagram">
                    <div class="book-spread-container">
                        <!-- Halaman Kiri (Genap) -->
                        <div class="book-page page-left">
                            <div class="page-header-running">
                                <span class="page-number-left">24</span>
                                <span class="running-text">KECAMATAN AMPANA KOTA DALAM ANGKA 2026</span>
                            </div>
                            <div class="page-inner-content">
                                <div class="dummy-text-block"></div>
                                <div class="dummy-table-block"></div>
                                <div class="dummy-text-block short"></div>
                            </div>
                            <div class="page-gutter-note gutter-left">← Sisi Lipatan Jilid (Gutter Margin)</div>
                        </div>
                        <!-- Halaman Kanan (Ganjil) -->
                        <div class="book-page page-right">
                            <div class="page-header-running">
                                <span class="running-text">KEPENDUDUKAN DAN KETENAGAKERJAAN</span>
                                <span class="page-number-right">25</span>
                            </div>
                            <div class="page-inner-content">
                                <div class="chapter-start-badge">Awal Bab Baru (Ganjil)</div>
                                <div class="dummy-chapter-title">BAB 2. KEPENDUDUKAN</div>
                                <div class="dummy-text-block"></div>
                                <div class="dummy-table-block"></div>
                            </div>
                            <div class="page-gutter-note gutter-right">Sisi Lipatan Jilid (Gutter Margin) →</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Section 3: Katalog Seluruh Aturan & Filter Interaktif -->
        <section id="rules-catalogue" class="rules-card-section">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Daftar Lengkap</span>
                    <h2>Katalog 36 Aturan Audit Publikasi BPS</h2>
                    <p>
                        Cari dan pelajari rincian logika, kriteria kelulusan, dan rekomendasi perbaikan
                        untuk setiap kode aturan.
                    </p>
                </div>
                <div class="expand-actions">
                    <button type="button" class="button secondary compact" @click="expandAll">
                        Buka Semua
                    </button>
                    <button type="button" class="button secondary compact" @click="collapseAll">
                        Tutup Semua
                    </button>
                </div>
            </div>

            <!-- Filter Controls Box -->
            <div class="rules-filter-bar">
                <!-- Search Box -->
                <div class="rules-search-box">
                    <Search :size="18" class="search-icon" />
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Cari kode aturan, judul, logika, atau nomor pedoman (§)..."
                        aria-label="Cari aturan"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        class="clear-btn"
                        @click="searchQuery = ''"
                        aria-label="Hapus pencarian"
                    >
                        <X :size="16" />
                    </button>
                </div>

                <!-- Secondary Filters -->
                <div class="rules-filter-row">
                    <!-- Category Tabs -->
                    <div class="filter-group">
                        <label>Kategori:</label>
                        <select v-model="selectedCategory" aria-label="Filter kategori">
                            <option value="all">Semua Kategori ({{ props.rules.length }})</option>
                            <option
                                v-for="(cat, key) in categories"
                                :key="key"
                                :value="key"
                            >
                                {{ cat.label }}
                            </option>
                        </select>
                    </div>

                    <!-- Severity Filter -->
                    <div class="filter-group">
                        <label>Keparahan:</label>
                        <select v-model="selectedSeverity" aria-label="Filter keparahan">
                            <option value="all">Semua Keparahan</option>
                            <option value="wajib">Wajib Diperbaiki</option>
                            <option value="saran">Saran Kualitas</option>
                            <option value="verifikasi">Perlu Verifikasi</option>
                        </select>
                    </div>

                    <!-- Mode Filter -->
                    <div class="filter-group">
                        <label>Metode Eksekusi:</label>
                        <select v-model="selectedMode" aria-label="Filter metode">
                            <option value="all">Semua Metode</option>
                            <option value="deterministic">Deterministik (Kode Murni)</option>
                            <option value="ai_page">Vision AI (Per Halaman)</option>
                            <option value="ai_pair">Vision AI (Pasangan Kover-Judul)</option>
                            <option value="external_evidence">Rujukan Eksternal & Web</option>
                            <option value="manual">Telaah Manual</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Filter Results Status -->
            <div class="rules-results-count">
                Menampilkan <strong>{{ filteredRules.length }}</strong> dari total {{ rules.length }} aturan
                <span v-if="searchQuery || selectedCategory !== 'all' || selectedSeverity !== 'all' || selectedMode !== 'all'" class="filter-reset-link" @click="searchQuery = ''; selectedCategory = 'all'; selectedSeverity = 'all'; selectedMode = 'all'">
                    · Reset filter
                </span>
            </div>

            <!-- Rules Accordion Cards -->
            <div v-if="filteredRules.length" class="rules-list-container">
                <article
                    v-for="rule in filteredRules"
                    :key="rule.code"
                    :class="['rule-card', `rule-severity-${rule.severity}`, { expanded: expandedRules[rule.code] }]"
                >
                    <div class="rule-card-header" @click="toggleRule(rule.code)">
                        <div class="rule-header-top">
                            <div class="rule-code-wrapper">
                                <span class="rule-code-badge">{{ rule.code }}</span>
                                <span class="rule-spec-badge">{{ rule.spec_ref }}</span>
                            </div>
                            <div class="rule-badges-group">
                                <span :class="['badge-severity', `severity-${rule.severity}`]">
                                    {{ getSeverityLabel(rule.severity) }}
                                </span>
                                <span class="badge-mode">
                                    {{ getModeLabel(rule.mode) }}
                                </span>
                            </div>
                        </div>

                        <div class="rule-header-main">
                            <h3 class="rule-title">{{ rule.title }}</h3>
                            <button
                                type="button"
                                class="rule-toggle-btn"
                                :aria-expanded="expandedRules[rule.code]"
                                :aria-label="expandedRules[rule.code] ? 'Tutup rincian' : 'Buka rincian'"
                            >
                                <ChevronUp v-if="expandedRules[rule.code]" :size="20" />
                                <ChevronDown v-else :size="20" />
                            </button>
                        </div>

                        <p class="rule-summary">{{ rule.summary }}</p>
                    </div>

                    <!-- Expandable Details Content -->
                    <div v-if="expandedRules[rule.code]" class="rule-card-body">
                        <div class="rule-detail-grid">
                            <!-- Column 1: Cara Kerja & Logika -->
                            <div class="detail-column">
                                <div class="detail-block">
                                    <h4><FileSearch :size="16" /> Cara Kerja Pemeriksaan</h4>
                                    <p>{{ rule.how_it_works }}</p>
                                </div>
                                <div class="detail-block">
                                    <h4><Bot :size="16" /> Logika & Kriteria</h4>
                                    <p>{{ rule.logic }}</p>
                                </div>
                                <div class="detail-block">
                                    <div class="criteria-card pass">
                                        <CheckCircle2 :size="16" />
                                        <div>
                                            <strong>Kondisi Lolos:</strong>
                                            <span>{{ rule.criteria_pass }}</span>
                                        </div>
                                    </div>
                                    <div class="criteria-card fail">
                                        <AlertTriangle :size="16" />
                                        <div>
                                            <strong>Kondisi Pelanggaran:</strong>
                                            <span>{{ rule.criteria_fail }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Column 2: Contoh Kasus & Rekomendasi -->
                            <div class="detail-column">
                                <div class="example-box good">
                                    <div class="example-header">
                                        <Check :size="16" />
                                        <strong>Contoh Sesuai Standar:</strong>
                                    </div>
                                    <div class="example-content">{{ rule.example_good }}</div>
                                </div>

                                <div class="example-box bad">
                                    <div class="example-header">
                                        <X :size="16" />
                                        <strong>Contoh Kesalahan:</strong>
                                    </div>
                                    <div class="example-content">{{ rule.example_bad }}</div>
                                </div>

                                <div class="recommendation-box">
                                    <strong><Sparkles :size="15" /> Rekomendasi Perbaikan:</strong>
                                    <p>{{ rule.recommendation }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <!-- Empty Search State -->
            <div v-else class="rules-empty-state">
                <Search :size="48" class="empty-icon" />
                <h3>Tidak Ada Aturan yang Cocok</h3>
                <p>
                    Tidak ditemukan aturan yang memenuhi kriteria pencarian "<strong>{{ searchQuery }}</strong>"
                    atau kombinasi filter yang dipilih.
                </p>
                <button
                    type="button"
                    class="button secondary"
                    @click="searchQuery = ''; selectedCategory = 'all'; selectedSeverity = 'all'; selectedMode = 'all'"
                >
                    Reset Filter Pencarian
                </button>
            </div>
        </section>

        <!-- Footer Help Note -->
        <div class="rules-footer-help">
            <BookOpenCheck :size="24" />
            <div>
                <h4>Ingin Menguji Dokumen Publikasi Sekarang?</h4>
                <p>
                    Unggah naskah PDF publikasi Anda untuk memulai audit otomatis menyeluruh berdasarkan
                    36 aturan baku di atas.
                </p>
            </div>
            <Link href="/publications/create" class="button primary">
                Mulai Pemeriksaan Baru
            </Link>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Scoped styles for Rules Guide page */
.rules-hero {
    margin-bottom: 24px;
}
.rules-version-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #eef7f5;
    color: #1b6355;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid #cce5de;
    margin-bottom: 12px;
}
.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 18px;
}
.rules-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
    margin-bottom: 30px;
}
.rules-metrics > div {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.rules-metrics > div strong {
    font-size: 26px;
    font-family: "Outfit", sans-serif;
    color: var(--ink);
}
.rules-metrics > div span {
    font-size: 13px;
    color: var(--muted);
}
.rules-metrics .metric-wajib strong {
    color: #b91c1c;
}
.rules-metrics .metric-saran strong {
    color: #b45309;
}
.rules-metrics .metric-verifikasi strong {
    color: #1d4ed8;
}

/* Sections */
.rules-card-section {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 28px;
    margin-bottom: 32px;
}
.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 24px;
}
.section-heading h2 {
    font-size: 22px;
    margin-top: 4px;
}
.eyebrow {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #8b5cf6;
}

/* Pipeline Grid */
.pipeline-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 16px;
}
.pipeline-step-card {
    background: #faf8f5;
    border: 1px solid #e5e0d8;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.2s ease;
    cursor: pointer;
    display: flex;
    flex-direction: column;
}
.pipeline-step-card:hover,
.pipeline-step-card.active {
    background: #ffffff;
    border-color: #305e58;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
}
.pipeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.pipeline-number {
    font-family: "Outfit", sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: #305e58;
}
.pipeline-icon {
    color: #305e58;
}
.pipeline-step-card h4 {
    font-size: 16px;
    margin-bottom: 6px;
}
.pipeline-badge {
    align-self: flex-start;
    font-size: 11px;
    font-weight: 600;
    background: #eef2f6;
    color: #334155;
    padding: 2px 8px;
    border-radius: 6px;
    margin-bottom: 10px;
}
.pipeline-desc {
    font-size: 13px;
    color: #555;
    margin: 0 0 14px;
    flex-grow: 1;
}
.pipeline-logic-box {
    background: #f1ede6;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 12px;
    color: #333;
}
.pipeline-logic-box strong {
    display: block;
    color: #222;
    margin-bottom: 2px;
}

/* Visual Mockup Tabs */
.visual-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    border-bottom: 2px solid #e5e0d8;
    padding-bottom: 12px;
    margin-bottom: 24px;
}
.visual-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 10px;
    font-weight: 600;
    color: #666;
    transition: all 0.15s ease;
}
.visual-tab-btn:hover {
    background: #f4f0e9;
    color: #222;
}
.visual-tab-btn.active {
    background: #305e58;
    color: #ffffff;
}

.visual-mockup-wrapper {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 30px;
    align-items: start;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
@media (max-width: 900px) {
    .visual-mockup-wrapper {
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
    }
}
.mockup-explanation {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.mockup-explanation h3 {
    font-size: 19px;
    margin-bottom: 10px;
    word-break: break-word;
    overflow-wrap: anywhere;
}
.mockup-explanation p {
    word-break: break-word;
    overflow-wrap: anywhere;
}
.visual-checklist {
    list-style: none;
    padding: 0;
    margin: 16px 0 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.visual-checklist li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13px;
    line-height: 1.5;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.checklist-icon {
    flex-shrink: 0;
    margin-top: 3px;
}
.checklist-text {
    flex: 1 1 auto;
    min-width: 0;
    word-break: break-word;
    overflow-wrap: anywhere;
}
.checklist-text code,
.visual-checklist code {
    background: #f1ede6;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    display: inline;
    word-break: break-word;
    overflow-wrap: anywhere;
    white-space: normal;
}
.text-success {
    color: #15803d;
    margin-top: 2px;
}

/* Mockup Diagrams */
.mockup-diagram {
    background: #f8f6f0;
    border: 1px solid #d9d3c7;
    border-radius: 14px;
    padding: 24px;
    display: flex;
    justify-content: center;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
    overflow: hidden;
}
.mockup-book-cover {
    width: 100%;
    max-width: 280px;
    background: #ffffff;
    border: 1px solid #c5bea8;
    border-radius: 6px;
    box-shadow: 4px 6px 18px rgba(0, 0, 0, 0.12);
    padding: 20px 16px;
    display: flex;
    flex-direction: column;
    position: relative;
    min-height: 380px;
    box-sizing: border-box;
}
.diagram-tag {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #0369a1;
    background: #e0f2fe;
    padding: 2px 6px;
    border-radius: 4px;
    margin-bottom: 4px;
    width: fit-content;
}
.mockup-katalog-box {
    margin-left: auto;
    text-align: right;
    margin-bottom: 20px;
}
.mockup-meta-line {
    font-size: 9px;
    font-family: monospace;
    color: #333;
}
.badge-mini-pass {
    background: #dcfce7;
    color: #166534;
    font-size: 8px;
    font-weight: bold;
    padding: 1px 4px;
    border-radius: 3px;
}
.mockup-title-box {
    margin-bottom: 20px;
}
.mockup-main-title {
    font-family: "Outfit", sans-serif;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.3;
    color: #111;
}
.mockup-sub-title {
    font-size: 10px;
    color: #555;
    margin-top: 3px;
}
.mockup-year {
    font-size: 13px;
    font-weight: 700;
    color: #b91c1c;
    margin-top: 4px;
}
.mockup-photo-area {
    background: #dbeafe;
    border: 1px dashed #93c5fd;
    border-radius: 4px;
    height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 10px;
    text-align: center;
    margin-bottom: auto;
}
.mockup-scrim-note {
    font-size: 10px;
    color: #1e40af;
    font-weight: 500;
}
.mockup-logo-box {
    margin-top: 14px;
    border-top: 1px solid #eee;
    padding-top: 10px;
}
.mockup-logo-brand {
    display: flex;
    align-items: center;
    gap: 8px;
}
.mockup-logo-shape {
    width: 24px;
    height: 24px;
    background: linear-gradient(135deg, #0284c7, #16a34a, #ea580c);
    border-radius: 4px;
}
.mockup-logo-brand strong {
    font-size: 8px;
    line-height: 1.2;
    color: #222;
}

/* Table Diagram */
.mockup-bps-table {
    width: 100%;
    background: #ffffff;
    border-radius: 8px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}
.mockup-table-title-area {
    font-size: 12px;
    line-height: 1.4;
    margin-bottom: 10px;
}
.mockup-table-title-en {
    font-size: 11px;
    color: #666;
}
.bps-sample-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}
.border-top-thick {
    border-top: 2px solid #222;
}
.border-bottom-thick {
    border-bottom: 2px solid #222;
}
.border-top-thin {
    border-top: 1px solid #555;
}
.border-bottom-thin {
    border-bottom: 1px solid #555;
}
.bps-sample-table th,
.bps-sample-table td {
    padding: 6px 10px;
    border-left: none;
    border-right: none;
}
.bps-sample-table thead th {
    text-align: left;
    font-weight: 600;
}
.table-sub-header th {
    text-align: center;
    font-size: 10px;
    color: #666;
}
.bps-sample-table .text-right {
    text-align: right;
}
.total-row td {
    background: #fafaf9;
}
.mockup-table-notes {
    font-size: 10px;
    color: #666;
    margin-top: 8px;
}

/* Spread Diagram */
.book-spread-container {
    display: flex;
    gap: 4px;
    background: #c5bea8;
    padding: 6px;
    border-radius: 6px;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
}
.book-page {
    width: 180px;
    height: 250px;
    background: #ffffff;
    padding: 14px 12px;
    display: flex;
    flex-direction: column;
    position: relative;
}
.page-left {
    border-radius: 4px 0 0 4px;
}
.page-right {
    border-radius: 0 4px 4px 0;
}
.page-header-running {
    display: flex;
    justify-content: space-between;
    font-size: 7px;
    font-weight: 600;
    color: #555;
    border-bottom: 1px solid #ddd;
    padding-bottom: 4px;
    margin-bottom: 12px;
}
.dummy-text-block {
    height: 8px;
    background: #e2e8f0;
    border-radius: 2px;
    margin-bottom: 6px;
}
.dummy-text-block.short {
    width: 60%;
}
.dummy-table-block {
    height: 60px;
    background: #f1f5f9;
    border: 1px dashed #cbd5e1;
    border-radius: 2px;
    margin: 10px 0;
}
.dummy-chapter-title {
    font-family: "Outfit", sans-serif;
    font-size: 9px;
    font-weight: 700;
    margin-bottom: 10px;
}
.chapter-start-badge {
    font-size: 7px;
    background: #dcfce7;
    color: #15803d;
    padding: 2px 4px;
    border-radius: 2px;
    margin-bottom: 4px;
    font-weight: 700;
}
.page-gutter-note {
    font-size: 7px;
    color: #888;
    margin-top: auto;
    text-align: center;
}

/* Rules Filters */
.rules-filter-bar {
    background: #faf8f5;
    border: 1px solid #e5e0d8;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
}
.rules-search-box {
    position: relative;
    margin-bottom: 14px;
}
.rules-search-box .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #888;
}
.rules-search-box input {
    padding-left: 42px;
    padding-right: 40px;
    font-size: 14px;
    height: 44px;
}
.clear-btn {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #888;
    cursor: pointer;
}
.rules-filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}
.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 220px;
}
.filter-group label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
    white-space: nowrap;
    margin: 0;
}
.filter-group select {
    min-height: 38px;
    padding: 6px 10px;
    font-size: 13px;
}
.rules-results-count {
    font-size: 13px;
    color: #666;
    margin-bottom: 20px;
}
.filter-reset-link {
    color: #b91c1c;
    cursor: pointer;
    font-weight: 600;
}
.filter-reset-link:hover {
    text-decoration: underline;
}

/* Rule Card Accordion */
.rules-list-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.rule-card {
    background: #ffffff;
    border: 1px solid #e0dad0;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s ease;
}
.rule-card:hover {
    border-color: #305e58;
}
.rule-card.expanded {
    border-color: #305e58;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
}
.rule-card-header {
    padding: 20px 24px;
    cursor: pointer;
    background: #ffffff;
}
.rule-header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 10px;
}
.rule-code-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}
.rule-code-badge {
    font-family: monospace;
    font-size: 12px;
    font-weight: 700;
    background: #2b2d2f;
    color: #ffffff;
    padding: 3px 8px;
    border-radius: 6px;
}
.rule-spec-badge {
    font-size: 11px;
    font-weight: 600;
    color: #666;
    background: #f1ede6;
    padding: 3px 8px;
    border-radius: 6px;
}
.rule-badges-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.badge-severity {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
}
.severity-wajib {
    background: #fee2e2;
    color: #991b1b;
}
.severity-saran {
    background: #fef3c7;
    color: #92400e;
}
.severity-verifikasi {
    background: #dbeafe;
    color: #1e40af;
}
.badge-mode {
    font-size: 11px;
    font-weight: 600;
    background: #f1f5f9;
    color: #475569;
    padding: 3px 10px;
    border-radius: 999px;
}
.rule-header-main {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}
.rule-title {
    font-size: 18px;
    color: var(--ink);
    margin: 0;
}
.rule-toggle-btn {
    background: none;
    border: none;
    color: #888;
    padding: 4px;
    border-radius: 6px;
}
.rule-card:hover .rule-toggle-btn {
    color: #305e58;
}
.rule-summary {
    font-size: 14px;
    color: #555;
    margin: 8px 0 0;
}

/* Rule Card Body Details */
.rule-card-body {
    border-top: 1px solid #eee8df;
    background: #faf8f5;
    padding: 24px;
}
.rule-detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
@media (max-width: 850px) {
    .rule-detail-grid {
        grid-template-columns: 1fr;
    }
}
.detail-column {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.detail-block h4 {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    margin-bottom: 6px;
    color: #305e58;
}
.detail-block p {
    font-size: 13px;
    line-height: 1.55;
    margin: 0;
    color: #333;
}

.criteria-card {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 12px;
    line-height: 1.45;
    margin-bottom: 8px;
}
.criteria-card.pass {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}
.criteria-card.fail {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.example-box {
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 12px;
    line-height: 1.5;
}
.example-box.good {
    background: #f0fdf4;
    border-left: 4px solid #16a34a;
    color: #14532d;
}
.example-box.bad {
    background: #fef2f2;
    border-left: 4px solid #dc2626;
    color: #7f1d1d;
}
.example-header {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
    margin-bottom: 4px;
}
.example-content {
    font-family: inherit;
}
.recommendation-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 12px;
    color: #1e40af;
}
.recommendation-box strong {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
}
.recommendation-box p {
    margin: 0;
    color: #1e3a8a;
}

/* Empty State */
.rules-empty-state {
    text-align: center;
    padding: 48px 24px;
    background: #faf8f5;
    border-radius: 12px;
    border: 1px dashed #d5cfc5;
}
.empty-icon {
    color: #999;
    margin-bottom: 12px;
}
.rules-empty-state h3 {
    margin-bottom: 6px;
}

/* Footer Help Note */
.rules-footer-help {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    background: #f4efe6;
    border: 1px solid #ddd6c8;
    border-radius: 14px;
    padding: 24px 28px;
    margin-top: 20px;
    flex-wrap: wrap;
}
.rules-footer-help h4 {
    margin-bottom: 4px;
    font-size: 16px;
}
.rules-footer-help p {
    margin: 0;
    font-size: 13px;
}

/* Mobile Responsive Refinements */
@media (max-width: 768px) {
    .rules-card-section {
        padding: 16px 12px;
        border-radius: 12px;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        overflow-x: clip;
    }
    .section-heading {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .section-heading h2 {
        font-size: 19px;
    }
    .rules-metrics {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 8px !important;
        margin-bottom: 22px;
    }
    .rules-metrics > div {
        padding: 12px 10px !important;
        border-radius: 14px !important;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        gap: 3px;
        min-height: 82px;
    }
    .rules-metrics > div svg {
        width: 20px;
        height: 20px;
    }
    .rules-metrics > div strong {
        font-size: 22px !important;
        line-height: 1.1;
    }
    .rules-metrics > div span {
        font-size: 11px !important;
        line-height: 1.25;
        color: var(--muted);
    }
    .pipeline-grid {
        grid-template-columns: 1fr;
    }
    .visual-tabs {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 6px;
        overflow-x: visible;
        padding-bottom: 10px;
        margin-bottom: 16px;
    }
    .visual-tab-btn {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 8px 4px;
        font-size: 11px;
        border-radius: 10px;
        gap: 4px;
        white-space: normal;
        min-width: 0;
        word-break: break-word;
    }
    .rules-filter-row {
        flex-direction: column;
        gap: 12px;
    }
    .filter-group {
        min-width: 100%;
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    .filter-group select {
        width: 100%;
    }
    .rules-footer-help {
        flex-direction: column;
        align-items: stretch;
        padding: 20px;
        gap: 16px;
        text-align: left;
    }
    .rules-footer-help .button {
        width: 100%;
        justify-content: center;
    }
    .mockup-diagram {
        padding: 12px 6px;
        width: 100%;
        max-width: 100%;
    }
    .mockup-book-cover {
        width: 100%;
        max-width: 270px;
        padding: 16px 10px;
    }
    .mockup-katalog-box {
        margin-bottom: 14px;
    }
    .mockup-meta-line {
        font-size: 8.5px;
        word-break: break-all;
    }
    .mockup-title-box {
        margin-bottom: 14px;
    }
    .mockup-main-title {
        font-size: 12px;
    }
    .mockup-sub-title {
        font-size: 9.5px;
    }
    .mockup-bps-table {
        padding: 10px 6px;
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        box-sizing: border-box;
    }
    .bps-sample-table th,
    .bps-sample-table td {
        padding: 5px 6px;
        font-size: 10px;
    }
    .book-spread-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        max-width: 100%;
        padding: 6px;
    }
    .book-page {
        width: 130px;
        height: 190px;
        padding: 8px 6px;
        flex-shrink: 0;
    }
    .rule-card-header {
        padding: 16px;
    }
    .rule-card-body {
        padding: 16px;
    }
    .rule-title {
        font-size: 15px;
    }
    .rule-header-top {
        gap: 6px;
    }
    .rule-badges-group {
        flex-wrap: wrap;
    }
}

@media (max-width: 480px) {
    .rules-metrics {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 6px !important;
    }
    .rules-metrics > div {
        padding: 10px 8px !important;
    }
    .rules-metrics > div strong {
        font-size: 20px !important;
    }
    .rules-metrics > div span {
        font-size: 10.5px !important;
    }
    .hero-actions {
        flex-direction: column;
    }
    .hero-actions .button {
        width: 100%;
        justify-content: center;
    }
}
</style>
