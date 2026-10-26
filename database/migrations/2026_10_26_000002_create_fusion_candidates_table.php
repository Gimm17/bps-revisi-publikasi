<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fusion_candidates')) {
            Schema::create('fusion_candidates', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('publication_id')->constrained('publications')->cascadeOnDelete();
                $table->foreignId('publication_page_id')->constrained('publication_pages')->cascadeOnDelete();
                $table->unsignedInteger('physical_page')->index();
                $table->string('engine', 16)->index(); // 'machine' | 'ai'
                $table->string('rule_code', 64)->index();
                $table->string('severity', 16);
                $table->text('observed');
                $table->text('expected');
                $table->text('recommendation');
                $table->json('evidences')->nullable();
                $table->string('match_group', 64)->nullable()->index();
                $table->string('category', 24)->nullable()->index(); // agreed | machine_only | ai_only | conflict
                $table->string('verdict', 16)->nullable(); // confirm | reject | uncertain
                $table->text('verdict_reason')->nullable();
                $table->timestamps();

                $table->index(['publication_id', 'physical_page']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fusion_candidates');
    }
};
