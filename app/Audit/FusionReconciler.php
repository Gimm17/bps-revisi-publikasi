<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\FusionCandidate;
use App\Models\PublicationPage;

class FusionReconciler
{
    /**
     * @var array<string, string>
     */
    protected array $ruleAliases = [
        'ISSN.COLON' => 'CATALOGUE.ISSN_COLON_RULE',
        'CATALOGUE.ISSN_COLON_RULE' => 'ISSN.COLON',
        'COVER.TITLE' => 'COVER.COMPONENTS',
        'COVER.COMPONENTS' => 'COVER.TITLE',
    ];

    /**
     * @return array{agreed: array, machine_only: array, ai_only: array}
     */
    public function reconcile(PublicationPage $page): array
    {
        $candidates = FusionCandidate::where('publication_page_id', $page->id)->get();
        $machineItems = $candidates->where('engine', 'machine');
        $aiItems = $candidates->where('engine', 'ai');

        $agreed = [];
        $machineOnly = [];
        $aiOnly = [];
        $matchedAiIds = [];

        foreach ($machineItems as $m) {
            $matched = false;
            foreach ($aiItems as $a) {
                if (in_array($a->id, $matchedAiIds, true)) {
                    continue;
                }

                if ($this->isMatch($m, $a)) {
                    $groupId = 'group_'.$m->id.'_'.$a->id;
                    $m->update(['category' => 'agreed', 'match_group' => $groupId]);
                    $a->update(['category' => 'agreed', 'match_group' => $groupId]);
                    $agreed[] = ['machine' => $m, 'ai' => $a];
                    $matchedAiIds[] = $a->id;
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $m->update(['category' => 'machine_only']);
                $machineOnly[] = $m;
            }
        }

        foreach ($aiItems as $a) {
            if (! in_array($a->id, $matchedAiIds, true)) {
                $a->update(['category' => 'ai_only']);
                $aiOnly[] = $a;
            }
        }

        return [
            'agreed' => $agreed,
            'machine_only' => $machineOnly,
            'ai_only' => $aiOnly,
        ];
    }

    protected function isMatch(FusionCandidate $m, FusionCandidate $a): bool
    {
        $ruleM = $m->rule_code;
        $ruleA = $a->rule_code;

        $isSameRule = ($ruleM === $ruleA) || (($this->ruleAliases[$ruleM] ?? '') === $ruleA);
        if (! $isSameRule) {
            return false;
        }

        // Jika teks observed mirip >= 55%
        similar_text(mb_strtolower((string) $m->observed), mb_strtolower((string) $a->observed), $percent);
        if ($percent >= 55.0) {
            return true;
        }

        // Atau jika rule sama dan berada di halaman yang sama
        return true;
    }
}
