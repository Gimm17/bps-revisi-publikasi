<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\Text\ArtifactSuppressor;
use Tests\TestCase;

class ArtifactSuppressionTest extends TestCase
{
    public function test_suppresses_kerning_space_artifacts(): void
    {
        $suppressor = new ArtifactSuppressor;

        $finding = [
            'rule_code' => 'LANGUAGE.EJAAN',
            'observed' => 'Kata "Hor ticulture" dan "Transpor tasi" terpisah spasi.',
            'expected' => 'Horticulture dan Transportasi.',
            'recommendation' => 'Gabungkan kata.',
        ];

        $res = $suppressor->evaluate($finding);
        $this->assertTrue($res['suppress']);
        $this->assertStringContainsString('Artefak spasi/kerning', $res['reason']);
    }

    public function test_suppresses_layouters_and_podes_false_positives(): void
    {
        $suppressor = new ArtifactSuppressor;

        $layoutersFinding = [
            'rule_code' => 'TEAM.COMPONENTS',
            'observed' => 'Terdapat kata "Layouters" dalam bahasa Inggris pada halaman tim.',
            'expected' => 'Penata letak.',
            'recommendation' => 'Ubah istilah.',
        ];
        $this->assertTrue($suppressor->shouldSuppress($layoutersFinding));

        $podesFinding = [
            'rule_code' => 'TABLE.CONSISTENCY',
            'observed' => 'Sumber data Podes 2024 usang untuk buku tahun 2026.',
            'expected' => 'Gunakan data 2026.',
            'recommendation' => 'Perbarui data.',
        ];
        $this->assertTrue($suppressor->shouldSuppress($podesFinding));

        $minusThreeFinding = [
            'rule_code' => 'TABLE.SYMBOL',
            'observed' => 'Terdapat angka negatif tiga -³ pada tabel 5.6.',
            'expected' => 'Angka positif.',
            'recommendation' => 'Perbaiki.',
        ];
        $this->assertTrue($suppressor->shouldSuppress($minusThreeFinding));
    }

    public function test_allows_legitimate_findings(): void
    {
        $suppressor = new ArtifactSuppressor;

        $legitimateFinding = [
            'rule_code' => 'LANGUAGE.EJAAN',
            'observed' => 'Ditemukan kata "Tekait" dan "Distrcit".',
            'expected' => 'Terkait dan District.',
            'recommendation' => 'Perbaiki typo.',
        ];
        $this->assertFalse($suppressor->shouldSuppress($legitimateFinding));
    }
}
