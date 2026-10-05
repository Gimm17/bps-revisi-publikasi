<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\PageResultValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\PublicationFixtures;
use Tests\TestCase;

class ResultSafetyTest extends TestCase
{
    use PublicationFixtures;

    public function test_readable_non_blank_page_cannot_omit_all_text(): void
    {
        $this->expectException(ValidationException::class);
        app(PageResultValidator::class)->validate($this->pageResult(1, ['text' => '']), 1);
    }

    public function test_malformed_table_inventory_is_rejected(): void
    {
        $data = $this->pageResult();
        $data['inventory']['tables'] = [['number' => '1.1', 'title' => 'Tabel', 'rows' => 'not rows']];
        $this->expectException(ValidationException::class);
        app(PageResultValidator::class)->validate($data, 1);
    }

    public function test_identity_values_must_be_scalars_not_instructions_in_objects(): void
    {
        $this->expectException(ValidationException::class);
        app(PageResultValidator::class)->validate($this->pageResult(1, ['identity' => ['title' => ['malformed']]]), 1);
    }

    public function test_proven_blank_page_may_have_no_text(): void
    {
        $result = app(PageResultValidator::class)->validate($this->pageResult(1, ['text' => '', 'page_type' => 'blank']), 1);
        $this->assertSame('blank', $result['page_type']);
    }
}
