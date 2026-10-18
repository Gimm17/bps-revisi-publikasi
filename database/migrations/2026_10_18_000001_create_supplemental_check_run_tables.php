<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_check_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('mode')->default('supplemental');
            $table->string('baseline_rule_version');
            $table->string('target_rule_version');
            $table->string('prompt_version');
            $table->string('pdf_sha256', 64);
            $table->string('rules_path');
            $table->string('rules_hash', 64);
            $table->json('scope');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('input_revision')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->json('error')->nullable();
            $table->timestamps();
            $table->index(['publication_id', 'status']);
        });

        Schema::create('publication_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('check_run_id')->constrained('publication_check_runs')->cascadeOnDelete();
            $table->string('check_type');
            $table->string('input_hash', 64);
            $table->string('execution_status')->default('pending');
            $table->string('decision')->nullable();
            $table->json('result')->nullable();
            $table->json('evidences')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['check_run_id', 'check_type', 'input_hash']);
        });

        Schema::create('publication_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('check_run_id')->constrained('publication_check_runs')->cascadeOnDelete();
            $table->string('series')->nullable();
            $table->string('jurisdiction')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('source_url')->nullable();
            $table->json('metadata')->nullable();
            $table->string('provenance')->default('official_browser');
            $table->string('access_status')->default('pending');
            $table->string('read_status')->default('pending');
            $table->string('scope')->nullable();
            $table->json('artifact_manifest')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reference_provider_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('endpoint');
            $table->text('api_key');
            $table->boolean('is_ready')->default(false);
            $table->timestamp('tested_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('finding_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
            $table->foreignUuid('check_run_id')->constrained('publication_check_runs')->cascadeOnDelete();
            $table->string('proposed_decision');
            $table->text('reason');
            $table->json('evidences')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->json('context')->nullable()->after('manifest');
        });

        Schema::table('findings', function (Blueprint $table): void {
            $table->foreignUuid('check_run_id')->nullable()->after('publication_id')->constrained('publication_check_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->dropForeign(['check_run_id']);
            $table->dropColumn('check_run_id');
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->dropColumn('context');
        });

        Schema::dropIfExists('finding_assessments');
        Schema::dropIfExists('reference_provider_configs');
        Schema::dropIfExists('publication_references');
        Schema::dropIfExists('publication_checks');
        Schema::dropIfExists('publication_check_runs');
    }
};
