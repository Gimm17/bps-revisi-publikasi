<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\ExaminerLearning;
use App\Models\Finding;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ExaminerLearningService
{
    private const STORAGE_PATH = 'audit/examiner_learnings.json';

    /**
     * Default confirmed learnings from BPS guidelines and examiner feedback.
     */
    private const DEFAULT_LEARNINGS = [
        [
            'key' => 'learning-logo-position',
            'rule_code' => 'TITLE.COVER_LAYOUT',
            'topic' => 'Posisi Logo BPS pada Kover dan Halaman Judul',
            'observed_example' => 'Logo resmi BPS berada di sudut kanan bawah, bukan sudut kiri atas.',
            'reason' => 'Pedoman BPS 2.1 butir 8 menegaskan logo dan nama BPS boleh ditempatkan di kiri, tengah, atau kanan bagian bawah kover.',
            'instruction' => 'DILARANG menganggap penempatan logo resmi BPS di sudut kanan bawah sebagai kesalahan. Pedoman tata letak BPS memperbolehkan penempatan logo di bagian bawah (kiri, tengah, atau kanan).',
            'created_by_name' => 'Pemeriksa BPS (Pedoman 2.1 butir 8)',
            'status' => 'active',
            'kind' => 'false_positive',
            'source' => 'default',
        ],
        [
            'key' => 'learning-pdf-spacing-artifacts',
            'rule_code' => 'LANGUAGE.EJAAN',
            'topic' => 'Artefak Pemisahan Spasi pada Ekstraksi Teks PDF',
            'observed_example' => 'Kata tampak terpisah spasi seperti Hor ticulture, Transpor tasi, Jakar ta, Per tanian, memberik an, ditingk atkan.',
            'reason' => 'Pemisahan huruf di tengah kata sering kali merupakan artefak layer teks PDF akibat kerning atau subpixel positioning font, padahal secara visual dokumen tampak rapat dan benar.',
            'instruction' => 'DILARANG menandai kata yang tampak terpisah spasi (seperti \'Hor ticulture\', \'Transpor tasi\', \'Jakar ta\', \'Per tanian\', \'memberik an\', \'ditingk atkan\') sebagai salah ketik jika pemisahan huruf tersebut semata-mata adalah artefak ekstraksi teks layer PDF, kecuali secara kasat mata/visual memang tercetak salah.',
            'created_by_name' => 'Pemeriksa BPS (Standar Pembacaan PDF)',
            'status' => 'active',
            'kind' => 'false_positive',
            'source' => 'default',
        ],
        [
            'key' => 'learning-kcda-layouters',
            'rule_code' => 'TEAM.FORMAT',
            'topic' => 'Penggunaan Istilah Layouters pada Tim Penyusun KCDA',
            'observed_example' => 'Kata Layouters pada susunan tim penyusun disalahkan atau dianggap bahasa asing tidak baku.',
            'reason' => 'Istilah Layouters tercantum secara resmi pada Template KCDA (Kecamatan Dalam Angka) BPS dan diperbolehkan.',
            'instruction' => 'DILARANG menyalahkan kata \'Layouters\' pada Halaman Tim Penyusun Kecamatan Dalam Angka karena istilah tersebut merupakan bagian dari template baku KCDA BPS.',
            'created_by_name' => 'Pemeriksa BPS (Template KCDA)',
            'status' => 'active',
            'kind' => 'false_positive',
            'source' => 'default',
        ],
        [
            'key' => 'learning-source-podes-vintage',
            'rule_code' => 'TABLE.SYMBOL',
            'topic' => 'Tahun Data Sumber Podes',
            'observed_example' => 'Sumber data Podes 2024 disalahkan pada publikasi terbitan 2026.',
            'reason' => 'Pendataan Potensi Desa (Podes) dilakukan secara berkala (misal 2024), sehingga penggunaannya pada publikasi 2025/2026 adalah data termutakhir yang sah.',
            'instruction' => 'DILARANG menyalahkan sumber data Podes (seperti \'Pendataan Potensi Desa 2024\') pada publikasi tahun 2025/2026 hanya karena tahun sumber lebih awal dari tahun terbitan, karena Podes merupakan sensus/pendataan berkala.',
            'created_by_name' => 'Pemeriksa BPS (Kaidah Sumber Statistik)',
            'status' => 'active',
            'kind' => 'false_positive',
            'source' => 'default',
        ],
        [
            'key' => 'learning-narrative-summary-scope',
            'rule_code' => 'LANGUAGE.BILINGUAL',
            'topic' => 'Kelengkapan Rincian Narasi Bab',
            'observed_example' => 'Narasi dianggap salah karena tidak menyebutkan seluruh jenjang sekolah atau seluruh baris tabel secara lengkap.',
            'reason' => 'Ulasan narasi adalah ringkasan eksekutif (highlight), bukan kewajiban mengulang 100% isi baris tabel.',
            'instruction' => 'DILARANG menyalahkan paragraf narasi hanya karena narasi tidak menyebutkan seluruh kategori/baris/jenjang yang ada di tabel. Narasi berfungsi sebagai ringkasan dan sorotan utama.',
            'created_by_name' => 'Pemeriksa BPS (Pedoman Penulisan Ulasan)',
            'status' => 'active',
            'kind' => 'false_positive',
            'source' => 'default',
        ],
        [
            'key' => 'learning-heading-punctuation',
            'rule_code' => 'LANGUAGE.EJAAN',
            'topic' => 'Tanda Titik pada Judul dan Heading',
            'observed_example' => 'Judul bab, judul gambar, atau entri heading disalahkan karena tidak diakhiri tanda titik.',
            'reason' => 'Sesuai kaidah tata bahasa dan pedoman BPS, judul dan baris heading tidak diakhiri tanda titik.',
            'instruction' => 'DILARANG menyalahkan ketiadaan tanda titik di akhir judul bab, judul tabel, judul gambar, atau baris heading kelompok.',
            'created_by_name' => 'Pemeriksa BPS (EYD & Pedoman BPS)',
            'status' => 'active',
            'kind' => 'false_positive',
            'source' => 'default',
        ],
    ];

    public function __construct()
    {
        $this->ensureMigratedFromLegacyStorage();
    }

    /**
     * Get all learning items.
     *
     * @return Collection<int, ExaminerLearning>
     */
    public function all(): Collection
    {
        if (! Schema::hasTable('examiner_learnings')) {
            return collect();
        }

        return ExaminerLearning::orderByDesc('hits')->orderByDesc('id')->get();
    }

    /**
     * Record an examiner's rejection as a permanent learning item.
     */
    public function recordRejection(Finding $finding, string $reason, ?User $user = null): ExaminerLearning
    {
        $fingerprint = hash('sha256', $finding->rule_code.'|'.mb_strtolower(trim($reason)));
        $key = 'learning-rej-'.$fingerprint;

        $instruction = $this->buildInstruction($finding, $reason);

        return ExaminerLearning::updateOrCreate(
            ['key' => $key],
            [
                'rule_code' => $finding->rule_code,
                'section' => $finding->section,
                'kind' => 'false_positive',
                'source' => 'rejection',
                'status' => 'active',
                'topic' => "Pengecualian Aturan {$finding->rule_code} (".mb_substr($finding->observed, 0, 50).'...)',
                'observed_example' => $finding->observed,
                'reason' => trim($reason),
                'instruction' => $instruction,
                'finding_id' => $finding->id,
                'publication_id' => $finding->publication_id,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'Pemeriksa',
            ]
        );
    }

    /**
     * Record an examiner's acceptance as a positive example learning item.
     */
    public function recordAcceptance(Finding $finding, ?User $user = null): ExaminerLearning
    {
        $key = 'learning-acc-'.$finding->id;

        return ExaminerLearning::updateOrCreate(
            ['key' => $key],
            [
                'rule_code' => $finding->rule_code,
                'section' => $finding->section,
                'kind' => 'positive_example',
                'source' => 'acceptance',
                'status' => 'active',
                'topic' => "Contoh Temuan Benar: {$finding->rule_code}",
                'observed_example' => $finding->observed,
                'reason' => 'Diverifikasi benar oleh pemeriksa.',
                'instruction' => "CONTOH TEMUAN VALID [{$finding->rule_code}]: {$finding->observed}",
                'finding_id' => $finding->id,
                'publication_id' => $finding->publication_id,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'Pemeriksa',
            ]
        );
    }

    /**
     * Record finding removed from Excel during re-upload.
     */
    public function recordExcelDeletion(Finding $finding, ?string $reason = null, ?User $user = null): ExaminerLearning
    {
        $key = 'learning-excel-del-'.$finding->id;
        $status = ! empty($reason) ? 'active' : 'pending';

        return ExaminerLearning::updateOrCreate(
            ['key' => $key],
            [
                'rule_code' => $finding->rule_code,
                'section' => $finding->section,
                'kind' => 'false_positive',
                'source' => 'excel_deletion',
                'status' => $status,
                'topic' => "Temuan Dihapus dari Excel: {$finding->rule_code}",
                'observed_example' => $finding->observed,
                'reason' => $reason ?: 'Dihapus dari Excel oleh pemeriksa',
                'instruction' => $reason ? "DILARANG menandai kesalahan serupa: {$reason}" : "Periksa ulang sebelum menandai: {$finding->observed}",
                'finding_id' => $finding->id,
                'publication_id' => $finding->publication_id,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'Pemeriksa (Excel)',
            ]
        );
    }

    /**
     * Generate prompt text of active learnings for AI instruction.
     */
    public function activeLearningsForPrompt(?string $pageType = null, array $ruleCodes = []): string
    {
        if (! Schema::hasTable('examiner_learnings')) {
            return '';
        }

        $query = ExaminerLearning::where('status', 'active');

        if (! empty($ruleCodes)) {
            $query->whereIn('rule_code', $ruleCodes);
        }

        $learnings = $query->orderByDesc('hits')
            ->take(25)
            ->get();

        if ($learnings->isEmpty()) {
            return '';
        }

        $prompt = "PEMBELAJARAN PERMANEN DARI PEMERIKSA (KOREKSI DAN PENGECUALIAN ATURAN):\n";
        $prompt .= "Instruksi berikut wajib ditaati sebagai pengecualian sah atau contoh acuan:\n";

        foreach ($learnings as $idx => $item) {
            $num = $idx + 1;
            $prompt .= "{$num}. [{$item->rule_code}] {$item->instruction}\n";
        }

        return rtrim($prompt);
    }

    private function buildInstruction(Finding $finding, string $reason): string
    {
        $cleanReason = trim($reason);
        if (preg_match('/^(dilarang|jangan|tidak perlu|abaikan)/i', $cleanReason)) {
            return $cleanReason;
        }

        return "DILARANG menandai temuan serupa pada aturan {$finding->rule_code} jika: {$cleanReason}. (Contoh yang dibatalkan: \"{$finding->observed}\")";
    }

    /**
     * Ensure database is initialized with default learnings and legacy json file is migrated.
     */
    protected function ensureMigratedFromLegacyStorage(): void
    {
        if (! Schema::hasTable('examiner_learnings')) {
            return;
        }

        // Check if defaults exist
        if (ExaminerLearning::count() === 0) {
            foreach (self::DEFAULT_LEARNINGS as $item) {
                ExaminerLearning::create($item);
            }

            // Check if legacy json file exists in storage
            $disk = Storage::disk('local');
            if ($disk->exists(self::STORAGE_PATH)) {
                $raw = json_decode($disk->get(self::STORAGE_PATH) ?: '[]', true) ?: [];
                foreach ($raw as $it) {
                    $key = $it['id'] ?? ('learning-legacy-'.hash('sha256', ($it['rule_code'] ?? '').($it['instruction'] ?? '')));
                    ExaminerLearning::firstOrCreate(
                        ['key' => $key],
                        [
                            'rule_code' => $it['rule_code'] ?? 'GENERAL',
                            'topic' => $it['topic'] ?? null,
                            'observed_example' => $it['observed_example'] ?? null,
                            'reason' => $it['rejection_reason'] ?? null,
                            'instruction' => $it['instruction'] ?? '',
                            'kind' => 'false_positive',
                            'source' => 'rejection',
                            'status' => ($it['active'] ?? true) ? 'active' : 'disabled',
                            'created_by_name' => $it['created_by'] ?? 'Pemeriksa',
                        ]
                    );
                }
            }
        }
    }
}
