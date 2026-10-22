<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Benchmark\GoldenMatcher;
use App\Models\Publication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditBenchmark extends Command
{
    protected $signature = 'audit:benchmark 
                            {publication_id? : UUID publikasi pada database}
                            {--golden=tests/Golden/ampana-kota-2026.json : Path ke file golden dataset}
                            {--fixture= : Path ke file findings.json offline fixture}
                            {--json : Keluarkan hasil dalam format JSON murni}';

    protected $description = 'Jalankan evaluasi benchmark recall dan precision terhadap dataset kunci jawaban (golden)';

    public function handle(GoldenMatcher $matcher): int
    {
        $goldenPath = base_path($this->option('golden'));
        if (! file_exists($goldenPath)) {
            $this->error("File golden dataset tidak ditemukan: {$goldenPath}");

            return 1;
        }

        $goldenDoc = json_decode(file_get_contents($goldenPath), true);
        if (! is_array($goldenDoc)) {
            $this->error("Format JSON pada golden dataset tidak valid.");

            return 1;
        }

        $findings = [];
        $fixture = $this->option('fixture');

        if ($fixture) {
            $fixturePath = base_path($fixture);
            if (! file_exists($fixturePath)) {
                $this->error("File fixture findings tidak ditemukan: {$fixturePath}");

                return 1;
            }
            $findings = json_decode(file_get_contents($fixturePath), true) ?: [];
        } else {
            $pubId = $this->argument('publication_id');
            if (! $pubId) {
                // Try to find default Ampana Kota or first publication
                $pub = Publication::where('title', 'like', '%ampana kota%')->first() ?? Publication::first();
            } else {
                $pub = Publication::find($pubId);
            }

            if (! $pub) {
                $this->error("Publikasi tidak ditemukan pada database. Gunakan opsi --fixture= path/to/findings.json untuk mode offline.");

                return 1;
            }

            $findings = $pub->findings()->where('deleted', false)->get()->toArray();
        }

        $result = $matcher->evaluate($goldenDoc, $findings);

        // Save benchmark result to storage
        $disk = Storage::disk('local');
        $disk->makeDirectory('benchmarks');
        $filename = 'benchmarks/'.now()->format('Y-m-d_His').'-benchmark.json';
        $disk->put($filename, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->info("================================================================================");
        $this->info("  BENCHMARK AUDIT PUBLIKASI BPS — HASIL EVALUASI KUNCI JAWABAN");
        $this->info("================================================================================");
        $this->line("Publikasi                 : " . ($result['publication']));
        $this->line("Total Temuan Diuji        : " . ($result['total_findings']));
        $this->line("Target Kunci Jawaban      : " . ($result['total_golden_must_find']) . " butir wajib (must_find)");
        $this->line("Kunci Jawaban Tertangkap  : " . ($result['matched_must_find_count']) . " butir");
        $this->line("Kunci Jawaban Luput (Miss): " . ($result['unmatched_must_find_count']) . " butir");
        $this->line("Pelanggaran Must Not Find : " . ($result['must_not_find_violations']) . " butir (target 0)");
        $this->line("Temuan Belum Terpetakan   : " . ($result['unmapped_findings_count']) . " butir (proxy noise)");
        $this->info("--------------------------------------------------------------------------------");
        $this->info("RECALL SKOR               : {$result['recall_percentage']}%");
        $this->info("--------------------------------------------------------------------------------");

        $rows = [];
        foreach ($result['chapter_breakdown'] as $chapter => $stat) {
            $rows[] = [
                $chapter,
                $stat['total_items'],
                $stat['matched_items'],
                $stat['recall_pct'] . '%',
            ];
        }
        $this->table(['Seksi / Bab', 'Target', 'Cocok', 'Recall %'], $rows);

        if (! empty($result['violations'])) {
            $this->warn("\nPERINGATAN: Ditemukan temuan yang seharusnya dibatalkan (Must Not Find):");
            foreach ($result['violations'] as $v) {
                $this->line("- [{$v['golden_id']}] {$v['golden_text']} -> {$v['violation_observed']}");
            }
        }

        $this->line("\nLaporan detail tersimpan di: storage/app/{$filename}");

        return 0;
    }
}
