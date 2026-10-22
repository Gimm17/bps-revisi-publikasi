<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\ExaminerLearning;
use Illuminate\Support\Facades\Schema;

class LearningSuppressor
{
    /**
     * Check if a candidate finding should be suppressed by an active examiner learning.
     *
     * @param  array<string, mixed>  $candidate
     * @return array{suppress: bool, learning_id: int|null, reason: string|null}
     */
    public function evaluate(array $candidate): array
    {
        if (! Schema::hasTable('examiner_learnings')) {
            return ['suppress' => false, 'learning_id' => null, 'reason' => null];
        }

        $rule = $candidate['rule_code'] ?? '';
        $observed = mb_strtolower(trim((string) ($candidate['observed'] ?? '')));

        if ($rule === '' || $observed === '') {
            return ['suppress' => false, 'learning_id' => null, 'reason' => null];
        }

        $activeLearnings = ExaminerLearning::where('status', 'active')
            ->where('kind', 'false_positive')
            ->where('rule_code', $rule)
            ->get();

        foreach ($activeLearnings as $learning) {
            $learnedObserved = mb_strtolower(trim((string) $learning->observed_example));
            $learnedReason = mb_strtolower(trim((string) $learning->reason));

            // 1. Exact or substring match
            if ($learnedObserved !== '' && (str_contains($observed, $learnedObserved) || str_contains($learnedObserved, $observed))) {
                $learning->increment('hits');

                return [
                    'suppress' => true,
                    'learning_id' => $learning->id,
                    'reason' => "Ditekan oleh pembelajaran pemeriksa #{$learning->id}: {$learning->topic}",
                ];
            }

            // 2. High text similarity (>= 80%)
            if ($learnedObserved !== '') {
                similar_text($observed, $learnedObserved, $pct);
                if ($pct >= 80.0) {
                    $learning->increment('hits');

                    return [
                        'suppress' => true,
                        'learning_id' => $learning->id,
                        'reason' => "Ditekan oleh pembelajaran pemeriksa #{$learning->id} (kemiripan ".round($pct)."%): {$learning->topic}",
                    ];
                }
            }
        }

        return ['suppress' => false, 'learning_id' => null, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function shouldSuppress(array $candidate): bool
    {
        return $this->evaluate($candidate)['suppress'];
    }
}
