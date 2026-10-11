<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\EvaluationScorer;
use App\Models\Publication;
use Illuminate\Console\Command;

class EvaluateGoldenDataset extends Command
{
    protected $signature = 'audit:evaluate {gold : JSON hasil adjudikasi manusia untuk satu publikasi}';

    protected $description = 'Hitung akurasi dari pasangan temuan gold–AI yang diperiksa manusia, bukan klaim otomatis.';

    public function handle(EvaluationScorer $scorer): int
    {
        try {
            $gold = json_decode(file_get_contents($this->argument('gold')), true, 64, JSON_THROW_ON_ERROR);
            $pub = Publication::findOrFail($gold['publication_id']);
            if (($gold['pdf_sha256'] ?? '') !== $pub->pdf_checksum) {
                throw new \InvalidArgumentException('Checksum PDF gold berbeda dari publikasi.');
            }
            $records = $pub->findings()->where('source', '!=', 'manual')->with('revisions')->get()->map(function ($f) {
                $first = $f->revisions->sortBy('id')->first();

                return ! empty($first?->before) ? $first->before : $f->toArray();
            })->all();
            $score = $scorer->score($gold, $records);
            $coverage = $pub->pages()->where('status', 'complete')->whereNotNull('result')->whereNotNull('completed_at')->count();
            $evidence = collect($records)->filter(fn ($f) => ! empty($f['evidences']) && collect($f['evidences'])->every(fn ($e) => isset($e['physical_page']) && $e['physical_page'] >= 1 && $e['physical_page'] <= $pub->page_count && (! empty($e['quote']) || ! empty($e['bbox']))))->count();
            $report = [...$score, 'publication_id' => $pub->id, 'page_coverage_percent' => round($coverage / $pub->page_count * 100, 2), 'evidence_completeness_percent' => count($records) ? round($evidence / count($records) * 100, 2) : null, 'validated_exports' => $pub->exports()->where('status', 'complete')->where(fn ($q) => $q->whereNull('validation->mode')->orWhere('validation->mode', '!=', 'partial'))->count(), 'processing_seconds' => $pub->completed_at && $pub->started_at ? abs($pub->completed_at->timestamp - $pub->started_at->timestamp) : null, 'model' => $pub->aiConfig?->model, 'rule_version' => $pub->rule_version, 'prompt_version' => $pub->prompt_version];
            $report['release_gate_passed'] = $report['accuracy_gate_passed'] && $report['page_coverage_percent'] === 100.0 && $report['evidence_completeness_percent'] === 100.0 && $report['validated_exports'] > 0;
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['release_gate_passed'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Evaluasi belum dapat diselesaikan: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
