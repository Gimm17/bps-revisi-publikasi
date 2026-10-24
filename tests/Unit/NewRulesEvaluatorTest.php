<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\CrossChecker;
use App\Audit\RuleEvaluator;
use App\Audit\RuleRegistry;
use Tests\TestCase;

class NewRulesEvaluatorTest extends TestCase
{
    private RuleEvaluator $evaluator;
    private CrossChecker $crossChecker;
    private RuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RuleEvaluator();
        $this->crossChecker = new CrossChecker();
        $this->registry = new RuleRegistry();
    }

    public function test_all_15_new_rules_are_registered_in_rule_registry(): void
    {
        $allRules = $this->registry->all();

        $fifteenNewRules = [
            'IDENTITY.WILAYAH_MISMATCH',
            'DOCUMENT.RAW_TEMPLATE',
            'CATALOGUE.NO_INDIVIDUAL_NAMES',
            'CATALOGUE.ISSN_COLON_RULE',
            'TEAM.ENGLISH_PLURAL_RULE',
            'TEAM.NO_NIP',
            'PREFACE.NO_NIP',
            'PREFACE.TITIMANGSA_YEAR',
            'PREFACE.BPS_FULL_TITLE',
            'TOC.SUBCHAPTER_NO_TRAILING_DOT',
            'TOC.NO_DAFTAR_GRAFIK',
            'TABLE.NO_TRAILING_DOT',
            'TABLE.NO_KATA_TAHUN',
            'TABLE.EN_DASH_ZERO',
            'TYPOGRAPHY.SUPERSCRIPT_UNIT',
        ];

        foreach ($fifteenNewRules as $ruleCode) {
            $this->assertArrayHasKey($ruleCode, $allRules, "Rule {$ruleCode} must be present in RuleRegistry.");
        }
    }

    public function test_document_raw_template_detection(): void
    {
        $page = [
            'page_number' => 1,
            'page_type' => 'cover',
            'text' => "KECAMATAN AMPANA KOTA DALAM ANGKA 2026\nKABUPATEN/KOTA\nVolume XX",
        ];

        $findings = $this->evaluator->evaluate($page);
        $found = array_filter($findings, fn ($f) => $f['rule_code'] === 'DOCUMENT.RAW_TEMPLATE');
        $this->assertNotEmpty($found);
    }

    public function test_catalogue_issn_colon_rule(): void
    {
        // On catalogue page without colon -> triggers CATALOGUE.ISSN_COLON_RULE
        $page = [
            'page_number' => 4,
            'page_type' => 'catalogue',
            'text' => "No. Publikasi: 1102001.2605\nISSN 2655-108X\nBadan Pusat Statistik",
        ];

        $findings = $this->evaluator->evaluate($page);
        $found = array_filter($findings, fn ($f) => $f['rule_code'] === 'CATALOGUE.ISSN_COLON_RULE');
        $this->assertNotEmpty($found);
    }

    public function test_typography_superscript_unit(): void
    {
        $page = [
            'page_number' => 15,
            'page_type' => 'table',
            'text' => "Tabel 1.1 Luas Wilayah menurut Desa (km2), 2025",
        ];

        $findings = $this->evaluator->evaluate($page);
        $found = array_filter($findings, fn ($f) => $f['rule_code'] === 'TYPOGRAPHY.SUPERSCRIPT_UNIT');
        $this->assertNotEmpty($found);
    }

    public function test_catalogue_no_individual_names(): void
    {
        $page = [
            'page_number' => 4,
            'page_type' => 'catalogue',
            'text' => "Katalog BPS: 1102001.7209\nISSN: 2655-108X\nPenyusun Naskah: Budi Santoso, S.Stat\nPembuat Kover: BPS Kabupaten Tojo Una-Una",
        ];

        $findings = $this->evaluator->evaluate($page);
        $found = array_filter($findings, fn ($f) => $f['rule_code'] === 'CATALOGUE.NO_INDIVIDUAL_NAMES');
        $this->assertNotEmpty($found);
    }

    public function test_team_english_plural_and_no_nip(): void
    {
        $page = [
            'page_number' => 5,
            'page_type' => 'team',
            'text' => "TIM PENYUSUN\nEditors: Ahmad\nNIP: 19850101 201001 1 001",
        ];

        $findings = $this->evaluator->evaluate($page);
        $pluralFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'TEAM.ENGLISH_PLURAL_RULE');
        $nipFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'TEAM.NO_NIP');

        $this->assertNotEmpty($pluralFindings);
        $this->assertNotEmpty($nipFindings);
    }

    public function test_preface_no_nip_and_bps_full_title(): void
    {
        $page = [
            'page_number' => 6,
            'page_type' => 'preface',
            'text' => "KATA PENGANTAR\n\nKepala BPS Kabupaten Tojo Una-Una\n\nDr. Hendra\nNIP. 19780512 200001 1 002",
        ];

        $findings = $this->evaluator->evaluate($page);
        $titleFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'PREFACE.BPS_FULL_TITLE');
        $nipFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'PREFACE.NO_NIP');

        $this->assertNotEmpty($titleFindings);
        $this->assertNotEmpty($nipFindings);
    }

    public function test_toc_subchapter_no_trailing_dot_and_no_daftar_grafik(): void
    {
        $page = [
            'page_number' => 7,
            'page_type' => 'toc',
            'text' => "DAFTAR ISI\n1.1. Keadaan Geografis .... 3\nDAFTAR GRAFIK\nGrafik 1 .... 12",
        ];

        $findings = $this->evaluator->evaluate($page);
        $dotFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'TOC.SUBCHAPTER_NO_TRAILING_DOT');
        $grafikFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'TOC.NO_DAFTAR_GRAFIK');

        $this->assertNotEmpty($dotFindings);
        $this->assertNotEmpty($grafikFindings);
    }

    public function test_table_no_trailing_dot_and_no_kata_tahun_and_en_dash(): void
    {
        $page = [
            'page_number' => 20,
            'page_type' => 'table',
            'text' => "Tabel 1.1. Luas Wilayah menurut Desa Tahun 2025\n(1) (2)\nDesa A 0,0",
        ];

        $findings = $this->evaluator->evaluate($page);
        $dotFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'TABLE.NO_TRAILING_DOT');
        $tahunFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'TABLE.NO_KATA_TAHUN');
        $dashFindings = array_filter($findings, fn ($f) => $f['rule_code'] === 'TABLE.EN_DASH_ZERO');

        $this->assertNotEmpty($dotFindings);
        $this->assertNotEmpty($tahunFindings);
        $this->assertNotEmpty($dashFindings);
    }

    public function test_cross_checker_identity_wilayah_mismatch(): void
    {
        $pages = [
            [
                'page_number' => 1,
                'page_type' => 'cover',
                'text' => "KECAMATAN AMPANA KOTA DALAM ANGKA 2026",
            ],
            [
                'page_number' => 3,
                'page_type' => 'title',
                'text' => "KECAMATAN RATOLINDO DALAM ANGKA 2026",
            ],
        ];

        $findings = $this->crossChecker->run($pages);
        $mismatch = array_filter($findings, fn ($f) => $f['rule_code'] === 'IDENTITY.WILAYAH_MISMATCH');
        $this->assertNotEmpty($mismatch);
    }

    public function test_cross_checker_preface_titimangsa_year(): void
    {
        $pages = [
            [
                'page_number' => 1,
                'page_type' => 'cover',
                'text' => "KECAMATAN AMPANA KOTA DALAM ANGKA 2026",
            ],
            [
                'page_number' => 6,
                'page_type' => 'preface',
                'text' => "KATA PENGANTAR\n\nAmpana, September 2025\nKepala Badan Pusat Statistik Kabupaten Tojo Una-Una",
            ],
        ];

        $findings = $this->crossChecker->run($pages);
        $titimangsa = array_filter($findings, fn ($f) => $f['rule_code'] === 'PREFACE.TITIMANGSA_YEAR');
        $this->assertNotEmpty($titimangsa);
    }
}
