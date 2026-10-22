<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_learnings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('rule_code', 64)->index();
            $table->string('section', 128)->nullable();
            $table->string('kind', 32)->default('false_positive');
            $table->string('source', 32)->default('default');
            $table->string('status', 32)->default('active')->index();
            $table->text('topic')->nullable();
            $table->text('observed_example')->nullable();
            $table->text('reason')->nullable();
            $table->text('instruction');
            $table->unsignedBigInteger('finding_id')->nullable();
            $table->foreignUuid('publication_id')->nullable()->constrained('publications')->nullOnDelete();
            $table->unsignedInteger('hits')->default(0);
            $table->string('created_by_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_learnings');
    }
};
