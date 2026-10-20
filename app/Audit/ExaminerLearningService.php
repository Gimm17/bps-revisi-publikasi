<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\Finding;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ExaminerLearningService
{
    private const STORAGE_PATH = 'audit/examiner_learnings.json';

    /**
     * Default confirmed learnings from BPS guidelines and examiner feedback.
     */
    private const DEFAULT_LEARNINGS = [
        [
            'id' => 'learning-logo-position',
            'rule_code' => 'TITLE.COVER_LAYOUT',
            'topic' => 'Posisi Logo BPS pada Kover dan Halaman Judul',
            'observed_example' => 'Logo resmi BPS berada di sudut kanan bawah, bukan sudut kiri atas.',
            'rejection_reason' => 'Pedoman BPS 2.1 butir 8 menegaskan logo dan nama BPS boleh ditempatkan di kiri, tengah, atau kanan bagian bawah kover.',
            'instruction' => 'DILARANG menganggap penempatan logo resmi BPS di sudut kanan bawah sebagai kesalahan. Pedoman tata letak BPS memperbolehkan penempatan logo di bagian bawah (kiri, tengah, atau kanan).',
            'created_at' => '2026-10-20T14:00:00+08:00',
            'created_by' => 'Pemeriksa BPS (Pedoman 2.1 butir 8)',
            'active' => true,
        ],
    ];

    /**
     * Record an examiner's rejection as a permanent learning item.
     */
    public function recordRejection(Finding $finding, string $reason, ?User $user = null): array
    {
        $learnings = $this->all();
        $fingerprint = hash('sha256', $finding->rule_code.'|'.mb_strtolower(trim($reason)));

        // Look for existing learning item to update or create new
        $existingIndex = null;
        foreach ($learnings as $index => $item) {
            if (($item['fingerprint'] ?? '') === $fingerprint || ($item['rule_code'] === $finding->rule_code && mb_strtolower($item['rejection_reason']) === mb_strtolower(trim($reason)))) {
                $existingIndex = $index;
                break;
            }
        }

        $instruction = $this->deriveInstruction($finding, $reason);

        $record = [
            'id' => 'learning-'.substr($fingerprint, 0, 12),
            'fingerprint' => $fingerprint,
            'finding_id' => $finding->id,
            'rule_code' => $finding->rule_code,
            'section' => $finding->section,
            'topic' => 'Koreksi Pemeriksa pada '.$finding->rule_code,
            'observed_example' => mb_substr($finding->observed, 0, 300),
            'rejection_reason' => trim($reason),
            'instruction' => $instruction,
            'created_at' => now()->toIso8601String(),
            'created_by' => $user?->name ?? $user?->email ?? 'Pemeriksa BPS',
            'active' => true,
        ];

        if ($existingIndex !== null) {
            $learnings[$existingIndex] = array_merge($learnings[$existingIndex], $record);
        } else {
            $learnings[] = $record;
        }

        $this->save($learnings);

        return $record;
    }

    /**
     * Get all permanent examiner learnings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::STORAGE_PATH)) {
            $disk->makeDirectory('audit');
            $disk->put(self::STORAGE_PATH, json_encode(self::DEFAULT_LEARNINGS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::DEFAULT_LEARNINGS;
        }

        $content = $disk->get(self::STORAGE_PATH);
        $decoded = json_decode((string) $content, true);

        return is_array($decoded) ? $decoded : self::DEFAULT_LEARNINGS;
    }

    /**
     * Save learnings list to disk.
     */
    public function save(array $learnings): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('audit');
        $disk->put(self::STORAGE_PATH, json_encode(array_values($learnings), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Generate concise authoritative prompt text to instruct AI agents.
     */
    public function activeLearningsForPrompt(): string
    {
        $active = array_filter($this->all(), fn ($item) => ($item['active'] ?? true) === true);
        if (empty($active)) {
            return '';
        }

        $lines = [
            "\n=== PEMBELAJARAN PERMANEN DARI PENOLAKAN PEMERIKSA MANUSIA BPS ===",
            'Pemeriksa manusia BPS telah menolak temuan-temuan di bawah ini karena tidak sesuai atau tidak diatur dalam pedoman resmi.',
            'AI WAJIB mematuhi instruksi pembelajaran ini dan DILARANG mengulang kesalahan/temuan palsu serupa:',
        ];

        foreach ($active as $item) {
            $rule = $item['rule_code'] ?? 'UMUM';
            $inst = $item['instruction'] ?? $item['rejection_reason'] ?? '';
            $reason = $item['rejection_reason'] ?? '';
            $lines[] = "- [Aturan: {$rule}] {$inst} (Alasan Pemeriksa: \"{$reason}\")";
        }

        $lines[] = "==================================================================\n";

        return implode("\n", $lines);
    }

    /**
     * Formulate an actionable AI instruction from the rejected finding and examiner reason.
     */
    private function deriveInstruction(Finding $finding, string $reason): string
    {
        $reasonLower = mb_strtolower($reason);

        if (str_contains($reasonLower, 'logo')) {
            return 'DILARANG mempermasalahkan posisi penempatan logo resmi BPS (misal di sudut kanan bawah). Pedoman BPS 2.1 butir 8 memperbolehkan logo ditempatkan di kiri, tengah, atau kanan bagian bawah kover.';
        }

        if (str_contains($reasonLower, 'tidak ada') || str_contains($reasonLower, 'bukan aturan') || str_contains($reasonLower, 'tidak diatur')) {
            return "DILARANG menandai '{$finding->observed}' sebagai pelanggaran/temuan, karena hal tersebut TIDAK ADA dalam pedoman BPS menurut penegasan pemeriksa.";
        }

        return "Hindari membuat temuan serupa dengan '{$finding->observed}'. Pemeriksa menegaskan: {$reason}.";
    }
}
