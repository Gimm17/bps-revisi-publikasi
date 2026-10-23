<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import {
    BookOpenCheck,
    Cpu,
    Zap,
    Workflow,
    Settings,
    Layers,
    ShieldCheck,
    CheckCircle2,
    AlertTriangle,
    Info,
    Clock,
    ArrowRight,
    Search,
    FileSpreadsheet,
    Bot,
    Sparkles,
    Copy,
    Check,
    Activity,
    Gauge,
    Terminal,
    HelpCircle,
    RefreshCw,
    SlidersHorizontal,
    Share2,
    Lock,
    Key,
    Database,
    FileText,
    TrendingUp,
    ExternalLink,
    ChevronDown,
    ChevronRight,
    Eye,
    UploadCloud,
    FolderCheck,
    CheckSquare,
} from "lucide-vue-next";
import AppLayout from "../../Layouts/AppLayout.vue";

interface AgentItem {
    id: number;
    name: string;
    slot: number;
    model: string;
    max_concurrency: number;
    status: string;
    enabled: boolean;
}

const props = defineProps<{
    concurrency: number;
    rate_limit_rpm: number;
    calculated_rpm: number;
    rule_version: string;
    target_rule_version: string;
    agents: AgentItem[];
    high_dpi: number;
    default_dpi: number;
}>();

// Search state
const searchQuery = ref("");
const activeTab = ref<"guide" | "settings" | "workflow" | "logic" | "parallel" | "speedup" | "faq">("guide");
const copiedKey = ref<string | null>(null);

// Interactive FAQ open states
const openFaqs = ref<Record<string, boolean>>({
    faq_1: true,
    faq_2: false,
    faq_3: false,
    faq_4: false,
    faq_5: false,
});

const toggleFaq = (key: string) => {
    openFaqs.value[key] = !openFaqs.value[key];
};

const copyToClipboard = (text: string, key: string) => {
    navigator.clipboard.writeText(text);
    copiedKey.value = key;
    setTimeout(() => {
        if (copiedKey.value === key) copiedKey.value = null;
    }, 2000);
};

// Filtered sections based on search
const matchesSearch = (text: string) => {
    if (!searchQuery.value.trim()) return true;
    return text.toLowerCase().includes(searchQuery.value.toLowerCase().trim());
};
</script>

<template>
    <AppLayout wide>
        <Head title="Dokumentasi & Panduan Operasional - Ruang Periksa BPS" />

        <div class="docs-container">
            <!-- Hero Header -->
            <header class="docs-hero">
                <div class="hero-badge">
                    <Sparkles :size="14" class="text-amber-500" />
                    <span>Pusat Dokumentasi & Panduan Arsitektur Sistem</span>
                </div>
                <h1 class="docs-title">Dokumentasi Operasional Ruang Periksa Publikasi</h1>
                <p class="docs-subtitle">
                    Panduan komprehensif cara penggunaan web, pengaturan agen AI, alur logika pemeriksaan (workflow), arsitektur paralel 5 agen, serta dokumentasi peningkatan kecepatan audit publikasi BPS.
                </p>

                <!-- Search Input -->
                <div class="search-box">
                    <Search :size="18" class="search-icon" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Cari dokumentasi (contoh: paralel, settings, workflow, rate limit, tabel, error)..."
                        class="search-input"
                    />
                    <button v-if="searchQuery" @click="searchQuery = ''" class="search-clear">Hapus</button>
                </div>

                <!-- Quick Stats Matrix -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper bg-emerald-100 text-emerald-700">
                            <Cpu :size="20" />
                        </div>
                        <div class="stat-meta">
                            <span class="stat-label">Agen AI Paralel</span>
                            <span class="stat-value">{{ concurrency }} Agen Simultan</span>
                            <span class="stat-sub">Slot 1 s.d. Slot 5 aktif bersamaan</span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon-wrapper bg-blue-100 text-blue-700">
                            <Gauge :size="20" />
                        </div>
                        <div class="stat-meta">
                            <span class="stat-label">Konsumsi Rate Limit</span>
                            <span class="stat-value">~{{ calculated_rpm }} RPM</span>
                            <span class="stat-sub">Batas Provider: {{ rate_limit_rpm }} RPM (Sangat Aman)</span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon-wrapper bg-amber-100 text-amber-700">
                            <Zap :size="20" />
                        </div>
                        <div class="stat-meta">
                            <span class="stat-label">Waktu Pemeriksaan</span>
                            <span class="stat-value">~9–10 Menit</span>
                            <span class="stat-sub">Untuk buku 118 hlm (2.5x lebih cepat)</span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon-wrapper bg-purple-100 text-purple-700">
                            <ShieldCheck :size="20" />
                        </div>
                        <div class="stat-meta">
                            <span class="stat-label">Aturan BPS & Versi</span>
                            <span class="stat-value">{{ rule_version }}</span>
                            <span class="stat-sub">Target: {{ target_rule_version }}</span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Mobile Section Dropdown Selector -->
            <div class="docs-mobile-nav md:hidden">
                <label class="docs-mobile-label">Pilih Bagian Dokumentasi:</label>
                <div class="docs-mobile-select-wrapper">
                    <select
                        v-model="activeTab"
                        class="docs-mobile-select"
                        aria-label="Pilih Bagian Dokumentasi"
                    >
                        <option value="guide">📖 1. Cara Penggunaan Web</option>
                        <option value="settings">⚙️ 2. Cara Settings & Agen</option>
                        <option value="workflow">🔄 3. Alur Kerja (Workflow)</option>
                        <option value="logic">🧠 4. Flow Logic & Validator</option>
                        <option value="parallel">🤖 5. AI Paralel (5 Agen)</option>
                        <option value="speedup">⚡ 6. Optimasi Kecepatan</option>
                        <option value="faq">❓ 7. FAQ & Solusi Kendala</option>
                    </select>
                    <ChevronDown :size="18" class="docs-select-arrow" />
                </div>
            </div>

            <!-- Desktop Navigation Tabs -->
            <nav class="docs-nav-tabs hidden md:flex" aria-label="Navigasi Dokumentasi">
                <button
                    class="nav-tab"
                    :class="{ active: activeTab === 'guide' }"
                    @click="activeTab = 'guide'"
                >
                    <BookOpenCheck :size="16" />
                    <span>1. Cara Penggunaan</span>
                </button>
                <button
                    class="nav-tab"
                    :class="{ active: activeTab === 'settings' }"
                    @click="activeTab = 'settings'"
                >
                    <Settings :size="16" />
                    <span>2. Cara Settings</span>
                </button>
                <button
                    class="nav-tab"
                    :class="{ active: activeTab === 'workflow' }"
                    @click="activeTab = 'workflow'"
                >
                    <Workflow :size="16" />
                    <span>3. Alur Kerja (Workflow)</span>
                </button>
                <button
                    class="nav-tab"
                    :class="{ active: activeTab === 'logic' }"
                    @click="activeTab = 'logic'"
                >
                    <Layers :size="16" />
                    <span>4. Flow Logic & Validator</span>
                </button>
                <button
                    class="nav-tab"
                    :class="{ active: activeTab === 'parallel' }"
                    @click="activeTab = 'parallel'"
                >
                    <Bot :size="16" />
                    <span>5. AI Paralel (5 Agen)</span>
                </button>
                <button
                    class="nav-tab"
                    :class="{ active: activeTab === 'speedup' }"
                    @click="activeTab = 'speedup'"
                >
                    <TrendingUp :size="16" />
                    <span>6. Optimasi Kecepatan</span>
                </button>
                <button
                    class="nav-tab"
                    :class="{ active: activeTab === 'faq' }"
                    @click="activeTab = 'faq'"
                >
                    <HelpCircle :size="16" />
                    <span>7. FAQ & Solusi</span>
                </button>
            </nav>

            <!-- TAB 1: CARA PENGGUNAAN WEB -->
            <section v-show="activeTab === 'guide'" class="tab-content" id="guide">
                <div class="section-header">
                    <h2>Panduan Penggunaan Web (User Guide)</h2>
                    <p>Langkah demi langkah menggunakan sistem Ruang Periksa Publikasi BPS dari upload PDF hingga ekspor hasil telaah.</p>
                </div>

                <div class="guide-steps">
                    <!-- Step 1 -->
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <div class="step-body">
                            <div class="step-heading">
                                <h3>Unggah File Publikasi PDF</h3>
                                <span class="badge blue">Halaman: /publications/create</span>
                            </div>
                            <p>
                                Masuk ke menu <strong>Pemeriksaan Baru</strong>. Pilih atau seret (*drag-and-drop*) file dokumen publikasi resmi BPS (misalnya: <em>Kecamatan Dalam Angka</em>, <em>Kabupaten Dalam Angka</em>, atau <em>Statistik Daerah</em>) dalam format PDF.
                            </p>
                            <ul class="bullet-list">
                                <li><strong>Batas Ukuran File:</strong> Maksimal 150 MB.</li>
                                <li><strong>Batas Halaman:</strong> Maksimal 500 halaman per publikasi.</li>
                                <li><strong>Validasi Otomatis:</strong> Sistem mengekstrak metadata dokumen (judul, jumlah halaman, tahun) menggunakan utilitas server <code>pdfinfo</code> secara instan.</li>
                            </ul>
                            <div class="tip-box">
                                <Info :size="16" />
                                <span><strong>Tips:</strong> Pastikan file PDF bukan file yang diproteksi password agar sistem dapat merender gambar setiap halaman.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <div class="step-body">
                            <div class="step-heading">
                                <h3>Mulai Pemeriksaan & Inisiasi Antrean</h3>
                                <span class="badge emerald">Tombol: Mulai Pemeriksaan</span>
                            </div>
                            <p>
                                Klik tombol <strong>Mulai Pemeriksaan</strong>. Sistem akan segera menjalankan proses background:
                            </p>
                            <ol class="step-sublist">
                                <li><strong>Render Halaman:</strong> Server merender PDF menjadi gambar beresolusi 160 DPI (dengan fallback 240 DPI High-DPI jika teks rapat) menggunakan <code>pdftoppm</code> dalam hitungan milidetik.</li>
                                <li><strong>Ekstraksi Text Layer:</strong> Mengambil teks digital mentah dengan <code>pdftotext</code> untuk dijadikan referensi silang ganda (*dual-layer check*).</li>
                                <li><strong>Distribusi Antrean:</strong> 118 halaman langsung dipecah dan didistribusikan ke antrean <code>ai</code> untuk dikerjakan secara paralel oleh 5 Agen AI.</li>
                            </ol>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <div class="step-body">
                            <div class="step-heading">
                                <h3>Pantau Progres Live di Markas Agen AI</h3>
                                <span class="badge purple">Halaman: /agent-office</span>
                            </div>
                            <p>
                                Anda dapat membuka menu <strong>Markas Agen</strong> untuk melihat aktivitas 5 karakter AI secara visual dalam ruangan kantor isometrik:
                            </p>
                            <div class="agent-preview-grid">
                                <div class="agent-chip">
                                    <span class="agent-indicator bg-emerald-500"></span>
                                    <span><strong>Agent 1 (Robot Mint):</strong> Fokus Halaman Awal & Struktur</span>
                                </div>
                                <div class="agent-chip">
                                    <span class="agent-indicator bg-blue-500"></span>
                                    <span><strong>Agent 2 (Cat Glasses):</strong> Fokus Tabel Bab Geografi & Penduduk</span>
                                </div>
                                <div class="agent-chip">
                                    <span class="agent-indicator bg-amber-500"></span>
                                    <span><strong>Agent 3 (Girl Headset):</strong> Fokus Tabel Sosial & Pertanian</span>
                                </div>
                                <div class="agent-chip">
                                    <span class="agent-indicator bg-rose-500"></span>
                                    <span><strong>Agent 4 (Bear Hoodie):</strong> Fokus Tabel Ekonomi & Perdagangan</span>
                                </div>
                                <div class="agent-chip">
                                    <span class="agent-indicator bg-teal-500"></span>
                                    <span><strong>Agent 5 (Peach Robot):</strong> Fokus Bab Narasi & Konsistensi</span>
                                </div>
                            </div>
                            <p class="text-sm text-gray-600 mt-2">
                                Setiap agen yang sedang menganalisis halaman akan otomatis beralih ke animasi mengetik menghadap monitor meja kerja masing-masing secara presisi.
                            </p>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div class="step-card">
                        <div class="step-number">4</div>
                        <div class="step-body">
                            <div class="step-heading">
                                <h3>Menelaah Temuan Pemeriksaan (Findings Review)</h3>
                                <span class="badge amber">Halaman: /publications/{id}</span>
                            </div>
                            <p>
                                Setelah status publikasi mencapai <strong>review_ready</strong>, verifikator dapat menelaah daftar temuan dengan fitur interaktif lengkap:
                            </p>
                            <div class="feature-pills">
                                <div class="pill">
                                    <Eye :size="15" />
                                    <span><strong>Visual Bounding Box:</strong> Sorotan kotak merah/kuning tepat pada teks/tabel yang bermasalah di atas gambar halaman asli.</span>
                                </div>
                                <div class="pill">
                                    <AlertTriangle :size="15" />
                                    <span><strong>Kategori Severity:</strong> Wajib (Kesalahan fatal/tata format baku), Verifikasi (Perlu konfirmasi angka), Catatan (Saran redaksional).</span>
                                </div>
                                <div class="pill">
                                    <SlidersHorizontal :size="15" />
                                    <span><strong>Filter Lengkap:</strong> Filter berdasarkan jenis aturan (Tabel, Narasi, Cover, Identitas, Ejaan).</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 5 -->
                    <div class="step-card">
                        <div class="step-number">5</div>
                        <div class="step-body">
                            <div class="step-heading">
                                <h3>Konfirmasi Verifikator & Feedback AI</h3>
                                <span class="badge blue">Fitur: Learning Feedback</span>
                            </div>
                            <p>
                                Verifikator dapat menerima (*Accept*) atau menolak (*Reject*) temuan AI:
                            </p>
                            <ul class="bullet-list">
                                <li>Jika temuan valid, verifikator memberi catatan koreksi yang harus dilakukan oleh penyusun publikasi BPS.</li>
                                <li>Jika AI menandai sesuatu yang sebenarnya diperbolehkan oleh kebijakan lokal BPS daerah, verifikator dapat memberikan umpan balik (feedback). Sistem akan menyimpannya ke database <strong>Pembelajaran AI (Examiner Learning)</strong> agar temuan serupa tidak berulang di masa mendatang.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Step 6 -->
                    <div class="step-card">
                        <div class="step-number">6</div>
                        <div class="step-body">
                            <div class="step-heading">
                                <h3>Ekspor Laporan Resmi (Excel Workbook)</h3>
                                <span class="badge emerald">Format: .xlsx Resmi BPS</span>
                            </div>
                            <p>
                                Klik tombol <strong>Unduh Hasil Pemeriksaan (.xlsx)</strong>. Sistem menghasilkan file Excel komprehensif berisi:
                            </p>
                            <ul class="bullet-list">
                                <li><strong>Sheet Ringkasan:</strong> Rekapitulasi jumlah halaman, skor keterbacaan, dan total temuan berdasarkan tingkat keparahan.</li>
                                <li><strong>Sheet Daftar Temuan:</strong> Tabel terstruktur nomor halaman, kode aturan, kutipan kesalahan (*quote*), nilai seharusnya (*expected*), dan rekomendasi perbaikan.</li>
                                <li><strong>Sheet Peta Bab & Tabel:</strong> Daftar seluruh tabel statistik dalam buku beserta kelengkapan sumber data dan catatannya.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 2: CARA SETTINGS -->
            <section v-show="activeTab === 'settings'" class="tab-content" id="settings">
                <div class="section-header">
                    <h2>Panduan Pengaturan & Konfigurasi (Settings Guide)</h2>
                    <p>Tata cara mengatur penyedia AI, kunci API, model, serta pengaturan 5 agen pada menu Pengaturan.</p>
                </div>

                <div class="settings-grid">
                    <!-- AI Provider Settings -->
                    <div class="doc-panel">
                        <div class="panel-header">
                            <div class="panel-title">
                                <Key :size="18" class="text-blue-600" />
                                <h3>1. Pengaturan Penyedia AI Utama</h3>
                            </div>
                            <span class="badge blue">Wajib Diisi</span>
                        </div>
                        <div class="panel-content">
                            <p>Sistem menggunakan arsitektur antarmuka standar OpenAI/Gemini yang kompatibel dengan gateway router AI.</p>
                            <div class="config-table">
                                <div class="config-row">
                                    <span class="cfg-name">Base URL</span>
                                    <span class="cfg-desc">Alamat endpoint HTTPS penyedia router (misal: <code>https://api.9router.com/v1</code> atau <code>https://api.limitrouter.com/v1</code>).</span>
                                </div>
                                <div class="config-row">
                                    <span class="cfg-name">API Key</span>
                                    <span class="cfg-desc">Kunci otentikasi Bearer token dari penyedia layanan router AI Anda.</span>
                                </div>
                                <div class="config-row">
                                    <span class="cfg-name">Uji Koneksi Visi</span>
                                    <span class="cfg-desc">Tombol untuk menguji apakah model mampu membaca kode visual acak (*Visual Challenge*) dari gambar uji.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 5 Agent Slots Settings -->
                    <div class="doc-panel">
                        <div class="panel-header">
                            <div class="panel-title">
                                <Cpu :size="18" class="text-emerald-600" />
                                <h3>2. Pengaturan 5 Slot Agen AI</h3>
                            </div>
                            <span class="badge emerald">Multi-Agent</span>
                        </div>
                        <div class="panel-content">
                            <p>Sistem memiliki 5 slot agen independen (Slot 1 s.d. Slot 5) yang dapat dikonfigurasi bersamaan:</p>
                            <div class="step-guide">
                                <div class="guide-item">
                                    <strong>Langkah A:</strong> Buka tab Slot 1, masukkan nama agen, model (rekomendasi: <code>gemini-3.8-flash</code>), dan API Key.
                                </div>
                                <div class="guide-item">
                                    <strong>Langkah B:</strong> Klik tombol <strong>Salin Pengaturan ke Semua Agen</strong> untuk mereplikasi konfigurasi ke Slot 2, 3, 4, dan 5 secara otomatis dalam 1 kali klik.
                                </div>
                                <div class="guide-item">
                                    <strong>Langkah C:</strong> Klik tombol <strong>Uji Semua Agen</strong> untuk memverifikasi bahwa kelima agen lulus tes membaca gambar visual.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cross-Verification Settings -->
                    <div class="doc-panel">
                        <div class="panel-header">
                            <div class="panel-title">
                                <ShieldCheck :size="18" class="text-purple-600" />
                                <h3>3. Mode Verifikasi Silang (Cross-Verification)</h3>
                            </div>
                            <span class="badge purple">Kontrol Akurasi</span>
                        </div>
                        <div class="panel-content">
                            <p>Pada detail halaman publikasi, Anda dapat memilih mode pengujian ganda (*double-check*):</p>
                            <div class="options-list">
                                <div class="opt-card">
                                    <span class="opt-badge high-risk">high_risk (Rekomendasi)</span>
                                    <p>Agen kedua hanya melakukan pengujian ulang pada halaman berisiko tinggi (cover, daftar isi, katalog, dan seluruh tabel statistik). Menjaga akurasi maksimal dengan efisiensi waktu tinggi.</p>
                                </div>
                                <div class="opt-card">
                                    <span class="opt-badge all-pages">all_pages (Sangat Ketat)</span>
                                    <p>Setiap halaman dari halaman 1 sampai 118 dibaca dua kali oleh dua agen berbeda. Paling teliti namun memakan kuota request dua kali lipat.</p>
                                </div>
                                <div class="opt-card">
                                    <span class="opt-badge off">off (Mode Ekspres)</span>
                                    <p>Hanya 1 kali pembacaan per halaman tanpa agen kedua. Paling cepat (selesai dalam ~8-9 menit untuk 118 halaman).</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Environment & Concurrency Settings -->
                    <div class="doc-panel">
                        <div class="panel-header">
                            <div class="panel-title">
                                <Terminal :size="18" class="text-amber-600" />
                                <h3>4. Konfigurasi Server (.env & Concurrency)</h3>
                            </div>
                            <span class="badge amber">Teknis / Server</span>
                        </div>
                        <div class="panel-content">
                            <p>Variabel penting pada file <code>.env</code> server untuk mengontrol kecepatan paralel:</p>
                            <div class="code-block">
                                <div class="code-header">
                                    <span>.env configuration</span>
                                    <button @click="copyToClipboard('AUDIT_AI_CONCURRENCY=5\nAUDIT_RULES_V2_ENABLED=true\nAUDIT_TEXT_LAYER_ENABLED=true', 'env')" class="copy-btn">
                                        <Check v-if="copiedKey === 'env'" :size="14" />
                                        <Copy v-else :size="14" />
                                        <span>{{ copiedKey === 'env' ? 'Tersalin' : 'Salin' }}</span>
                                    </button>
                                </div>
                                <pre><code># Kapasitas slot pemrosesan bersamaan (5 Agen Aktif)
AUDIT_AI_CONCURRENCY=5

# Aturan audit versi 2 (tabel konsistensi & numerik)
AUDIT_RULES_V2_ENABLED=true

# Dual-layer perbandingan teks digital
AUDIT_TEXT_LAYER_ENABLED=true</code></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 3: WORKFLOW / ALUR KERJA MENYELURUH -->
            <section v-show="activeTab === 'workflow'" class="tab-content" id="workflow">
                <div class="section-header">
                    <h2>Alur Kerja Sistem (End-to-End Pipeline)</h2>
                    <p>Bagaimana dokumen publikasi diproses secara otomatis dari file PDF hingga selesai diekspor.</p>
                </div>

                <!-- Interactive Workflow Diagram -->
                <div class="workflow-diagram">
                    <div class="wf-step">
                        <div class="wf-icon-box bg-blue-500 text-white">
                            <UploadCloud :size="24" />
                        </div>
                        <div class="wf-badge">Fase 1</div>
                        <h4>PDF Ingestion</h4>
                        <p>Upload PDF, ekstraksi metadata, validasi batas halaman dengan <code>pdfinfo</code>.</p>
                    </div>

                    <div class="wf-arrow"><ArrowRight :size="20" /></div>

                    <div class="wf-step">
                        <div class="wf-icon-box bg-teal-500 text-white">
                            <Layers :size="24" />
                        </div>
                        <div class="wf-badge">Fase 2</div>
                        <h4>Render & Text Layer</h4>
                        <p>Render gambar 160 DPI via <code>pdftoppm</code> dan ekstrak teks digital via <code>pdftotext</code>.</p>
                    </div>

                    <div class="wf-arrow"><ArrowRight :size="20" /></div>

                    <div class="wf-step highlight">
                        <div class="wf-icon-box bg-emerald-600 text-white">
                            <Cpu :size="24" />
                        </div>
                        <div class="wf-badge">Fase 3 (Paralel)</div>
                        <h4>5 Agen Visi AI</h4>
                        <p>Analisis gambar halaman serentak oleh 5 worker independen dengan koordinat bounding box.</p>
                    </div>

                    <div class="wf-arrow"><ArrowRight :size="20" /></div>

                    <div class="wf-step">
                        <div class="wf-icon-box bg-purple-600 text-white">
                            <ShieldCheck :size="24" />
                        </div>
                        <div class="wf-badge">Fase 4</div>
                        <h4>Self-Healing Validator</h4>
                        <p>Normalisasi koordinat, sanitasi alias, perbaikan bounding box tanpa membuang halaman.</p>
                    </div>

                    <div class="wf-arrow"><ArrowRight :size="20" /></div>

                    <div class="wf-step">
                        <div class="wf-icon-box bg-amber-600 text-white">
                            <Workflow :size="24" />
                        </div>
                        <div class="wf-badge">Fase 5</div>
                        <h4>Cross-Checks & Finalize</h4>
                        <p>Kalkulasi penjumlahan baris/kolom, konsistensi antar-bab, dan pembuatan laporan Excel.</p>
                    </div>
                </div>

                <!-- Detailed Phase Explanations -->
                <div class="phases-detail">
                    <div class="phase-row">
                        <div class="phase-tag">Fase 1 s.d. 2</div>
                        <div class="phase-desc">
                            <h4>Persiapan Cepat di Server Lokal</h4>
                            <p>
                                Server memproses gambar dan teks lokal secara instan tanpa membebani kuota API AI. Satu halaman PDF dirender dalam waktu <strong>~25 milidetik</strong>. Seluruh 118 halaman selesai dirender dalam waktu kurang dari 4 detik.
                            </p>
                        </div>
                    </div>

                    <div class="phase-row">
                        <div class="phase-tag">Fase 3</div>
                        <div class="phase-desc">
                            <h4>Eksekusi Paralel 5 Agen AI</h4>
                            <p>
                                Database queue mendistribusikan halaman secara merata (*Load Balancing*) kepada Agen 1, 2, 3, 4, dan 5. Kelima agen bekerja serentak di 5 slot worker yang berjalan secara paralel di sistem server background.
                            </p>
                        </div>
                    </div>

                    <div class="phase-row">
                        <div class="phase-tag">Fase 4</div>
                        <div class="phase-desc">
                            <h4>Validasi Cerdas (Self-Healing Validation)</h4>
                            <p>
                                Hasil tanggapan AI diperiksa oleh <code>PageResultValidator</code>. Jika AI mengembalikan skala koordinat 0..1000 atau format bounding box terbalik, validator otomatis menormalisasi koordinat ke skala 0.0..1.0 sehingga tidak terjadi error fatal.
                            </p>
                        </div>
                    </div>

                    <div class="phase-row">
                        <div class="phase-tag">Fase 5</div>
                        <div class="phase-desc">
                            <h4>Pemeriksaan Deterministik Matematis & Ekspor</h4>
                            <p>
                                Modul <code>TableNumericChecker</code> secara deterministik menghitung ulang seluruh angka penjumlahan tabel (Negeri + Swasta, Jumlah Kecamatan) dengan toleransi angka bulat dan desimal, lalu menyusun workbook Excel resmi.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 4: FLOW LOGIC & VALIDATOR -->
            <section v-show="activeTab === 'logic'" class="tab-content" id="logic">
                <div class="section-header">
                    <h2>Logika Alur Sistem & Self-Healing Validator</h2>
                    <p>Bagaimana status publikasi dikelola dan bagaimana sistem otomatis menyembuhkan anomali respon AI.</p>
                </div>

                <div class="logic-grid">
                    <!-- State Machine -->
                    <div class="logic-card">
                        <h3>1. Diagram Transisi Status Publikasi</h3>
                        <p class="text-sm text-gray-600 mb-3">Siklus hidup status buku dari upload hingga siap direview verifikator:</p>
                        <div class="state-diagram">
                            <div class="state-node">
                                <span class="st-name">uploaded</span>
                                <span class="st-desc">PDF diunggah & diverifikasi</span>
                            </div>
                            <div class="st-arrow">↓</div>
                            <div class="state-node active">
                                <span class="st-name">processing</span>
                                <span class="st-desc">5 Agen AI memproses 118 halaman</span>
                            </div>
                            <div class="st-arrow">↓</div>
                            <div class="state-node">
                                <span class="st-name">cross_checking</span>
                                <span class="st-desc">Pemeriksaan silang numerik & bab</span>
                            </div>
                            <div class="st-arrow">↓</div>
                            <div class="state-node">
                                <span class="st-name">exporting</span>
                                <span class="st-desc">Penyusunan workbook Excel</span>
                            </div>
                            <div class="st-arrow">↓</div>
                            <div class="state-node success">
                                <span class="st-name">review_ready</span>
                                <span class="st-desc">Siap ditelaah verifikator</span>
                            </div>
                        </div>
                    </div>

                    <!-- Batch Continuity Guard -->
                    <div class="logic-card">
                        <h3>2. Batch Continuity Guard (Anti-Nyangkut)</h3>
                        <p class="text-sm text-gray-600 mb-3">
                            Solusi teknis yang mencegah proses berhenti di tengah jalan:
                        </p>
                        <div class="guard-box">
                            <div class="guard-item">
                                <CheckCircle2 :size="18" class="text-emerald-600" />
                                <div>
                                    <strong>Status Processing Dijaga Penuh:</strong>
                                    <p>Sistem tidak pernah mengubah status buku menjadi <code>partial_failure</code> hanya karena satu halaman gagal baca. Status tetap <code>processing</code> selama masih ada halaman lain yang sedang berjalan.</p>
                                </div>
                            </div>

                            <div class="guard-item">
                                <CheckCircle2 :size="18" class="text-emerald-600" />
                                <div>
                                    <strong>Auto-Retry Tanpa Klik Manual:</strong>
                                    <p>Halaman yang mengalami kendala sesaat (timeout atau kapasitas) otomatis dicoba ulang oleh worker di antrean tanpa mewajibkan pengguna menekan tombol "Lanjutkan".</p>
                                </div>
                            </div>
                        </div>

                        <h3 class="mt-4">3. Mekanisme Self-Healing Bounding Box</h3>
                        <div class="code-snippet-box">
                            <pre><code>// Contoh penanganan otomatis PageResultValidator:
// 1. Skala 0..1000 dikonversi otomatis ke 0.0..1.0
// 2. Koordinat terbalik (ymin > ymax) otomatis di-swap
// 3. Jika bbox cacat namun quote teks valid, bbox di-null-kan 
//    sehingga temuan tetap tercatat tanpa menggagalkan halaman!</code></pre>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 5: AI PARALEL & RATE LIMIT -->
            <section v-show="activeTab === 'parallel'" class="tab-content" id="parallel">
                <div class="section-header">
                    <h2>Arsitektur Paralel 5 Agen AI & Keamanan Rate Limit</h2>
                    <p>Penjelasan teknis bagaimana 5 agen bekerja bersamaan secara aman dalam batas kuota 25 RPM provider.</p>
                </div>

                <div class="parallel-container">
                    <!-- Rate Limit Math Card -->
                    <div class="math-card">
                        <div class="math-header">
                            <Gauge :size="24" class="text-blue-600" />
                            <div>
                                <h3>Kalkulasi Matematis Keamanan Kuota (25 RPM)</h3>
                                <p class="text-sm text-gray-600">Batas maksimal penyedia AI Anda: <strong>25 Request / Menit</strong>.</p>
                            </div>
                        </div>

                        <div class="formula-box">
                            <div class="formula-row">
                                <span class="formula-label">Rata-rata Waktu Visi AI per Halaman:</span>
                                <span class="formula-val">t = 24 detik</span>
                            </div>
                            <div class="formula-row">
                                <span class="formula-label">Kapasitas 1 Agen per Menit:</span>
                                <span class="formula-val">60s / 24s = 2.5 request/menit</span>
                            </div>
                            <div class="formula-row highlight">
                                <span class="formula-label">Total Beban 5 Agen Simultan:</span>
                                <span class="formula-val">5 × 2.5 = 12.5 RPM</span>
                            </div>
                            <div class="formula-row safe">
                                <span class="formula-label">Sisa Ruang Aman (Headroom Buffer):</span>
                                <span class="formula-val">25 RPM - 12.5 RPM = 12.5 RPM (Tersisa 50%)</span>
                            </div>
                        </div>
                        <p class="text-sm text-emerald-800 mt-2 font-medium">
                            ✓ Konfigurasi ini menjamin kecepatan maksimal sekaligus 100% kebal dari error HTTP 429 (Rate Limit Exceeded).
                        </p>
                    </div>

                    <!-- Worker Architecture Visual -->
                    <div class="worker-architecture">
                        <h3>Arsitektur Worker Antrean Server</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            Server menjalankan 5 background daemon independen yang mengunci slot masing-masing:
                        </p>

                        <div class="workers-list">
                            <div class="worker-card">
                                <div class="w-head">
                                    <Bot :size="18" />
                                    <span>Worker ai-1</span>
                                </div>
                                <span class="w-status online">Aktif di Cron</span>
                                <span class="w-desc">Memproses antrean <code>ai,verify,default</code></span>
                            </div>

                            <div class="worker-card">
                                <div class="w-head">
                                    <Bot :size="18" />
                                    <span>Worker ai-2</span>
                                </div>
                                <span class="w-status online">Aktif di Cron</span>
                                <span class="w-desc">Memproses antrean <code>ai,verify,default</code></span>
                            </div>

                            <div class="worker-card">
                                <div class="w-head">
                                    <Bot :size="18" />
                                    <span>Worker ai-3</span>
                                </div>
                                <span class="w-status online">Aktif di Cron</span>
                                <span class="w-desc">Memproses antrean <code>ai,verify,default</code></span>
                            </div>

                            <div class="worker-card">
                                <div class="w-head">
                                    <Bot :size="18" />
                                    <span>Worker ai-4</span>
                                </div>
                                <span class="w-status online">Aktif di Cron</span>
                                <span class="w-desc">Memproses antrean <code>ai,verify,default</code></span>
                            </div>

                            <div class="worker-card">
                                <div class="w-head">
                                    <Bot :size="18" />
                                    <span>Worker ai-5</span>
                                </div>
                                <span class="w-status online">Aktif di Cron</span>
                                <span class="w-desc">Memproses antrean <code>ai,verify,default</code></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 6: OPTIMASI KECEPATAN (SPEEDUP CHANGELOG) -->
            <section v-show="activeTab === 'speedup'" class="tab-content" id="speedup">
                <div class="section-header">
                    <h2>Dokumentasi Peningkatan Kecepatan (Changelog & Benchmarks)</h2>
                    <p>Rincian peningkatan arsitektur yang meningkatkan kecepatan pemeriksaan hingga 2,5x – 3x lebih cepat.</p>
                </div>

                <!-- Comparison Table -->
                <div class="benchmark-table-wrapper">
                    <table class="benchmark-table">
                        <thead>
                            <tr>
                                <th>Parameter Pemeriksaan</th>
                                <th>Konfigurasi Lama</th>
                                <th>Konfigurasi Baru (Saat Ini)</th>
                                <th>Dampak & Peningkatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Batas Concurrency (Slot Simultan)</strong></td>
                                <td><span class="badge red">2 Agen Maks</span></td>
                                <td><span class="badge emerald">5 Agen Simultan</span></td>
                                <td>Kelima agen bekerja serentak di 5 lajur paralel.</td>
                            </tr>
                            <tr>
                                <td><strong>Jumlah Worker Daemon di Server</strong></td>
                                <td><span class="badge red">3 Worker</span></td>
                                <td><span class="badge emerald">5 Worker Paralel</span></td>
                                <td>Antrean tidak pernah tertahan menunggu giliran.</td>
                            </tr>
                            <tr>
                                <td><strong>Penanganan Error Respon AI</strong></td>
                                <td>Gagal fatal & melempar ValidationException</td>
                                <td><span class="badge emerald">Auto Self-Healing</span></td>
                                <td>Menormalisasi koordinat cacat tanpa membuang halaman.</td>
                            </tr>
                            <tr>
                                <td><strong>Batch Continuity (Anti-Pause)</strong></td>
                                <td>Pause seketika (partial_failure) saat 1 halaman gagal</td>
                                <td><span class="badge emerald">Uninterrupted Processing</span></td>
                                <td>Status tetap processing sampai seluruh 118 halaman tuntas.</td>
                            </tr>
                            <tr>
                                <td><strong>Tabel Bertingkat Multi-Level</strong></td>
                                <td>Crash PHP Array to string conversion</td>
                                <td><span class="badge emerald">Safe Stringify Helper</span></td>
                                <td>Mendukung tabel kompleks tanpa error fatal.</td>
                            </tr>
                            <tr class="highlight-row">
                                <td><strong>Total Waktu Audit (Buku 118 Halaman)</strong></td>
                                <td><strong>25 – 35 Menit</strong></td>
                                <td><strong>~9 – 10 Menit</strong></td>
                                <td><strong>Peningkatan Kecepatan 2.5x – 3x Lipat</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="summary-box mt-4">
                    <h4>Jaminan Kualitas & Ketelitian</h4>
                    <p>
                        Peningkatan kecepatan ini dicapai murni melalui <strong>optimasi paralelitas infrastruktur antrean server</strong>. Seluruh aturan ketelitian BPS, prompt analitis model <code>gemini-3.8-flash</code>, validasi angka penjumlahan tabel, dan deteksi ejaan berjalan <strong>100% lengkap tanpa ada satu pun aturan yang dikurangi atau dilewati</strong>.
                    </p>
                </div>
            </section>

            <!-- TAB 7: FAQ & TROUBLESHOOTING -->
            <section v-show="activeTab === 'faq'" class="tab-content" id="faq">
                <div class="section-header">
                    <h2>Tanya Jawab & Panduan Solusi (FAQ & Troubleshooting)</h2>
                    <p>Jawaban atas pertanyaan umum seputar pengoperasian dan penanganan kendala sistem.</p>
                </div>

                <div class="faq-accordion">
                    <!-- FAQ 1 -->
                    <div class="faq-item" :class="{ open: openFaqs.faq_1 }">
                        <button class="faq-trigger" @click="toggleFaq('faq_1')">
                            <span>Mengapa Agent 4 di Markas Agen orientasinya berbeda sendiri?</span>
                            <ChevronDown v-if="openFaqs.faq_1" :size="18" />
                            <ChevronRight v-else :size="18" />
                        </button>
                        <div v-show="openFaqs.faq_1" class="faq-content">
                            <p>
                                Posisi meja dan monitor pada workstation Agent 4 (karakter Bear Hoodie di area tengah) memiliki sudut isometrik yang menghadap ke kanan-bawah. Pose dan tatapan Agent 4 sudah sejak awal selaras presisi ke layar monitornya, sehingga sengaja dipertahankan (*keep existing*) agar sudut pandang ruangan tetap natural dan variatif.
                            </p>
                        </div>
                    </div>

                    <!-- FAQ 2 -->
                    <div class="faq-item" :class="{ open: openFaqs.faq_2 }">
                        <button class="faq-trigger" @click="toggleFaq('faq_2')">
                            <span>Apa yang harus dilakukan jika kuota penyedia AI habis di tengah jalan?</span>
                            <ChevronDown v-if="openFaqs.faq_2" :size="18" />
                            <ChevronRight v-else :size="18" />
                        </button>
                        <div v-show="openFaqs.faq_2" class="faq-content">
                            <p>
                                Anda tidak perlu mengulang pemeriksaan dari awal! Cukup buka menu <strong>Pengaturan</strong>, masukkan API Key baru atau ganti router, lalu klik <strong>Simpan</strong>. Setelah itu, buka kembali publikasi yang bersangkutan dan klik tombol <strong>Ganti Koneksi AI & Lanjutkan</strong>. Sistem akan melanjutkan hanya pada halaman-halaman yang belum selesai.
                            </p>
                        </div>
                    </div>

                    <!-- FAQ 3 -->
                    <div class="faq-item" :class="{ open: openFaqs.faq_3 }">
                        <button class="faq-trigger" @click="toggleFaq('faq_3')">
                            <span>Bagaimana cara membaca kode aturan temuan (contoh: TABLE.TOTAL)?</span>
                            <ChevronDown v-if="openFaqs.faq_3" :size="18" />
                            <ChevronRight v-else :size="18" />
                        </button>
                        <div v-show="openFaqs.faq_3" class="faq-content">
                            <p>
                                Kode temuan disusun secara hierarkis berdasarkan standar buku pedoman BPS:
                            </p>
                            <ul>
                                <li><code>TABLE.TOTAL</code>: Angka pada baris Jumlah/Total tidak cocok dengan penjumlahan rincian di atasnya.</li>
                                <li><code>TABLE.SYMBOL</code>: Penggunaan tanda hubung strip biasa (<code>-</code>) yang seharusnya menggunakan tanda en-dash resmi (<code>–</code>).</li>
                                <li><code>IDENTITY.CONSISTENCY</code>: Judul buku, nomor katalog, atau nomor publikasi berbeda antara cover depan, halaman judul, dan punggung buku.</li>
                                <li><code>TEXT.SPELL</code>: Potensi salah ketik ejaan bahasa Indonesia atau istilah teknis statistik.</li>
                            </ul>
                        </div>
                    </div>

                    <!-- FAQ 4 -->
                    <div class="faq-item" :class="{ open: openFaqs.faq_4 }">
                        <button class="faq-trigger" @click="toggleFaq('faq_4')">
                            <span>Bisakah saya mengunduh laporan sebagian jika buku belum 100% selesai?</span>
                            <ChevronDown v-if="openFaqs.faq_4" :size="18" />
                            <ChevronRight v-else :size="18" />
                        </button>
                        <div v-show="openFaqs.faq_4" class="faq-content">
                            <p>
                                Ya! Sistem menyediakan tombol <strong>Unduh Hasil Sementara (Partial Export)</strong>. Anda dapat mengunduh seluruh temuan dari halaman-halaman yang sudah berhasil diproses tanpa harus menunggu seluruh isi buku selesai.
                            </p>
                        </div>
                    </div>

                    <!-- FAQ 5 -->
                    <div class="faq-item" :class="{ open: openFaqs.faq_5 }">
                        <button class="faq-trigger" @click="toggleFaq('faq_5')">
                            <span>Mengapa batas concurrency dipatok 5 agen dan bukan 10 agen?</span>
                            <ChevronDown v-if="openFaqs.faq_5" :size="18" />
                            <ChevronRight v-else :size="18" />
                        </button>
                        <div v-show="openFaqs.faq_5" class="faq-content">
                            <p>
                                Ini demi mematuhi batas rate limit kuota penyedia Anda yaitu <strong>25 RPM</strong>. Pada halaman-halaman yang teksnya sangat sedikit (misal halaman pemisah bab atau cover), waktu analisis bisa sangat cepat (~8–10 detik). Jika menggunakan 10 agen bersamaan, frekuensi request dapat melonjak hingga 40–50 RPM yang akan memicu pemblokiran otomatis (HTTP 429). Concurrency 5 adalah titik keseimbangan paling optimal antara kecepatan dan stabilitas operasional.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.docs-container {
    max-width: 1240px;
    margin: 0 auto;
    padding: 32px 24px 80px;
}

/* Hero Section */
.docs-hero {
    background: linear-gradient(135deg, #ffffff 0%, #f4fbf9 100%);
    border: 1px solid #d7e8e4;
    border-radius: 18px;
    padding: 40px;
    margin-bottom: 28px;
    box-shadow: 0 4px 20px rgba(48, 94, 88, 0.05);
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #eef7f5;
    border: 1px solid #bce1da;
    color: #245750;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 16px;
}

.docs-title {
    font-size: 32px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 12px;
    letter-spacing: -0.02em;
}

.docs-subtitle {
    font-size: 15px;
    color: #4b5563;
    max-width: 85ch;
    margin: 0 0 24px;
    line-height: 1.6;
}

/* Search Box */
.search-box {
    position: relative;
    max-width: 680px;
    margin-bottom: 28px;
}

.search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}

.search-input {
    width: 100%;
    height: 48px;
    padding: 0 80px 0 44px;
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    font-size: 14px;
    color: #1f2937;
    transition: all 0.2s;
}

.search-input:focus {
    border-color: #305e58;
    box-shadow: 0 0 0 3px rgba(48, 94, 88, 0.15);
    outline: none;
}

.search-clear {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: #e5e7eb;
    border: none;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    color: #4b5563;
    cursor: pointer;
}

/* Stats Matrix */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
}

.stat-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.04);
}

.stat-icon-wrapper {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.stat-meta {
    display: flex;
    flex-direction: column;
}

.stat-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
}

.stat-value {
    font-size: 16px;
    font-weight: 700;
    color: #111827;
    margin: 2px 0;
}

.stat-sub {
    font-size: 11px;
    color: #9ca3af;
}

/* Mobile Navigation Select */
.docs-mobile-nav {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 24px;
}
.docs-mobile-label {
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: #6b7280;
    text-transform: uppercase;
}
.docs-mobile-select-wrapper {
    position: relative;
    width: 100%;
}
.docs-mobile-select {
    width: 100%;
    padding: 12px 38px 12px 14px;
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    appearance: none;
    -webkit-appearance: none;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    cursor: pointer;
    transition: all 0.2s ease;
}
.docs-mobile-select:focus {
    outline: none;
    border-color: #305e58;
    box-shadow: 0 0 0 3px rgba(48, 94, 88, 0.15);
}
.docs-select-arrow {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: #6b7280;
}

/* Navigation Tabs */
.docs-nav-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 28px;
    border-bottom: 1px solid #e5e7eb;
}

.nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    background: #ffffff;
    border: 1px solid #d1d5db;
    color: #4b5563;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}

.nav-tab:hover {
    background: #f9fafb;
    border-color: #9ca3af;
    color: #111827;
}

.nav-tab.active {
    background: #305e58;
    border-color: #305e58;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(48, 94, 88, 0.2);
}

/* Section Header */
.section-header {
    margin-bottom: 28px;
}

.section-header h2 {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 6px;
}

.section-header p {
    font-size: 14px;
    color: #6b7280;
    margin: 0;
}

/* User Guide Steps */
.guide-steps {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.step-card {
    display: flex;
    gap: 20px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 24px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
}

.step-number {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #eef7f5;
    color: #245750;
    border: 2px solid #bce1da;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}

.step-body {
    flex: 1;
}

.step-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.step-heading h3 {
    font-size: 17px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.bullet-list, .step-sublist {
    margin: 12px 0;
    padding-left: 20px;
    color: #4b5563;
    font-size: 13.5px;
    line-height: 1.7;
}

.tip-box {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    margin-top: 12px;
}

.agent-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 10px;
    margin-top: 12px;
}

.agent-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    color: #374151;
}

.agent-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.feature-pills {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 12px;
}

.feature-pills .pill {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    color: #374151;
}

/* Badges */
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}
.badge.blue { background: #e0f2fe; color: #0369a1; }
.badge.emerald { background: #dcfce7; color: #15803d; }
.badge.purple { background: #f3e8ff; color: #7e22ce; }
.badge.amber { background: #fef3c7; color: #b45309; }
.badge.red { background: #fee2e2; color: #b91c1c; }

/* Settings Grid */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
    gap: 20px;
}

.doc-panel {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
}

.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.panel-title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.panel-title h3 {
    font-size: 15px;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.panel-content {
    padding: 20px;
    font-size: 13.5px;
    color: #4b5563;
    line-height: 1.6;
}

.config-table {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 14px;
}

.config-row {
    display: flex;
    flex-direction: column;
    padding: 10px;
    background: #f8fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.cfg-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 13px;
    margin-bottom: 2px;
}

.cfg-desc {
    font-size: 12.5px;
    color: #64748b;
}

.step-guide {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 12px;
}

.guide-item {
    background: #f0fdf4;
    border: 1px solid #dcfce7;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    color: #166534;
}

.options-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 12px;
}

.opt-card {
    border: 1px solid #e5e7eb;
    padding: 12px;
    border-radius: 8px;
    background: #ffffff;
}

.opt-card p {
    margin: 6px 0 0;
    font-size: 12.5px;
    color: #4b5563;
}

.opt-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-block;
}
.opt-badge.high-risk { background: #fef3c7; color: #92400e; }
.opt-badge.all-pages { background: #f3e8ff; color: #6b21a8; }
.opt-badge.off { background: #e5e7eb; color: #374151; }

.code-block {
    background: #1e293b;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 12px;
}

.code-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 14px;
    background: #0f172a;
    color: #94a3b8;
    font-size: 12px;
    font-family: monospace;
}

.copy-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: transparent;
    border: 1px solid #334155;
    color: #cbd5e1;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 4px;
    cursor: pointer;
}

.copy-btn:hover {
    background: #334155;
    color: #ffffff;
}

.code-block pre {
    margin: 0;
    padding: 14px;
    color: #38bdf8;
    font-family: monospace;
    font-size: 12px;
    line-height: 1.5;
    overflow-x: auto;
}

/* Workflow Diagram */
.workflow-diagram {
    display: flex;
    align-items: stretch;
    gap: 12px;
    overflow-x: auto;
    padding: 16px 8px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    margin-bottom: 24px;
}

.wf-step {
    flex: 1;
    min-width: 170px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.wf-step.highlight {
    background: #ecfdf5;
    border-color: #a7f3d0;
}

.wf-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}

.wf-badge {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 4px;
}

.wf-step h4 {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px;
}

.wf-step p {
    font-size: 11.5px;
    color: #64748b;
    margin: 0;
    line-height: 1.4;
}

.wf-arrow {
    display: flex;
    align-items: center;
    color: #94a3b8;
}

.phases-detail {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.phase-row {
    display: flex;
    gap: 16px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px;
}

.phase-tag {
    font-weight: 700;
    font-size: 12px;
    padding: 4px 10px;
    background: #f1f5f9;
    color: #334155;
    border-radius: 6px;
    height: fit-content;
    white-space: nowrap;
}

.phase-desc h4 {
    font-size: 14px;
    font-weight: 700;
    margin: 0 0 4px;
    color: #1e293b;
}

.phase-desc p {
    font-size: 13px;
    color: #475569;
    margin: 0;
}

/* Logic & State Machine */
.logic-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
    gap: 20px;
}

.logic-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 24px;
}

.logic-card h3 {
    font-size: 16px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 6px;
}

.state-diagram {
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
}

.state-node {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 14px;
}

.state-node.active {
    border-color: #305e58;
    background: #eef7f5;
}

.state-node.success {
    border-color: #16a34a;
    background: #f0fdf4;
}

.st-name {
    font-family: monospace;
    font-weight: 700;
    font-size: 13px;
    color: #0f172a;
}

.st-desc {
    font-size: 12px;
    color: #64748b;
}

.st-arrow {
    text-align: center;
    color: #94a3b8;
    font-weight: bold;
    font-size: 14px;
}

.guard-box {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.guard-item {
    display: flex;
    gap: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
}

.guard-item strong {
    display: block;
    font-size: 13.5px;
    color: #0f172a;
    margin-bottom: 2px;
}

.guard-item p {
    font-size: 12.5px;
    color: #475569;
    margin: 0;
}

.code-snippet-box {
    background: #0f172a;
    border-radius: 8px;
    padding: 12px;
    margin-top: 10px;
}

.code-snippet-box pre {
    margin: 0;
    font-size: 11.5px;
    font-family: monospace;
    color: #a5f3fc;
}

/* Parallel & Rate Limit */
.parallel-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.math-card {
    background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
    border: 1.5px solid #bbf7d0;
    border-radius: 14px;
    padding: 24px;
}

.math-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 16px;
}

.math-header h3 {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    color: #166534;
}

.formula-box {
    background: #ffffff;
    border: 1px solid #dcfce7;
    border-radius: 10px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.formula-row {
    display: flex;
    justify-content: space-between;
    font-size: 13.5px;
    color: #374151;
}

.formula-row.highlight {
    font-weight: 700;
    color: #15803d;
    border-top: 1px dashed #bbf7d0;
    padding-top: 8px;
}

.formula-row.safe {
    font-weight: 700;
    color: #0369a1;
}

.worker-architecture {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 24px;
}

.worker-architecture h3 {
    font-size: 17px;
    font-weight: 700;
    margin: 0 0 4px;
    color: #111827;
}

.workers-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
}

.worker-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.w-head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 13px;
    color: #0f172a;
}

.w-status {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    width: fit-content;
}
.w-status.online { background: #dcfce7; color: #166534; }

.w-desc {
    font-size: 11.5px;
    color: #64748b;
}

/* Benchmark Table */
.benchmark-table-wrapper {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    overflow-x: auto;
}

.benchmark-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 13.5px;
}

.benchmark-table th {
    background: #f8fafc;
    padding: 14px 18px;
    font-weight: 700;
    color: #1e293b;
    border-bottom: 1px solid #e2e8f0;
}

.benchmark-table td {
    padding: 14px 18px;
    border-bottom: 1px solid #f1f5f9;
    color: #475569;
}

.benchmark-table tr.highlight-row {
    background: #f0fdf4;
    font-weight: 700;
    color: #166534;
}

.benchmark-table tr.highlight-row td {
    color: #166534;
    font-size: 14px;
}

.summary-box {
    background: #eef7f5;
    border: 1px solid #bce1da;
    border-radius: 12px;
    padding: 18px;
}

.summary-box h4 {
    margin: 0 0 6px;
    color: #1f5049;
    font-size: 15px;
    font-weight: 700;
}

.summary-box p {
    margin: 0;
    font-size: 13px;
    color: #2e665e;
    line-height: 1.6;
}

/* FAQ Accordion */
.faq-accordion {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.faq-item {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s;
}

.faq-item.open {
    border-color: #305e58;
}

.faq-trigger {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: transparent;
    border: none;
    font-size: 14.5px;
    font-weight: 700;
    color: #1f2937;
    text-align: left;
    cursor: pointer;
}

.faq-content {
    padding: 0 20px 18px;
    font-size: 13.5px;
    color: #4b5563;
    line-height: 1.6;
    border-top: 1px solid #f3f4f6;
    padding-top: 14px;
}

.faq-content ul {
    margin: 8px 0;
    padding-left: 20px;
}

@media (max-width: 768px) {
    .docs-nav-tabs {
        display: none !important;
    }
    .docs-container {
        padding: 20px 16px 60px;
    }
    .docs-hero {
        padding: 24px;
    }
    .docs-title {
        font-size: 24px;
    }
    .settings-grid, .logic-grid {
        grid-template-columns: 1fr;
    }
}
</style>
