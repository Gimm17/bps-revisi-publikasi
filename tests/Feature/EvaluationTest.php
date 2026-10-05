<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\EvaluationScorer;
use Tests\TestCase;

class EvaluationTest extends TestCase
{
    public function test_scoring_does_not_invent_precision_without_adjudicated_matches(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(EvaluationScorer::class)->score(['adjudicated' => false, 'expected' => [], 'matches' => []], []);
    }

    public function test_recall_and_precision_include_false_negatives_and_positives(): void
    {
        $gold = ['adjudicated' => true, 'expected' => [['id' => 'a', 'severity' => 'wajib'], ['id' => 'b', 'severity' => 'wajib'], ['id' => 'c', 'severity' => 'saran']], 'matches' => [['gold_id' => 'a', 'finding_id' => 11]]];
        $result = app(EvaluationScorer::class)->score($gold, [['id' => 11, 'severity' => 'wajib'], ['id' => 12, 'severity' => 'wajib']]);
        $this->assertSame(50.0, $result['mandatory_recall_percent']);
        $this->assertSame(50.0, $result['mandatory_precision_percent']);
        $this->assertFalse($result['accuracy_gate_passed']);
    }

    public function test_duplicate_match_is_not_counted_twice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(EvaluationScorer::class)->score(['adjudicated' => true, 'expected' => [['id' => 'a', 'severity' => 'wajib']], 'matches' => [['gold_id' => 'a', 'finding_id' => 1], ['gold_id' => 'a', 'finding_id' => 1]]], [['id' => 1, 'severity' => 'wajib']]);
    }
}
