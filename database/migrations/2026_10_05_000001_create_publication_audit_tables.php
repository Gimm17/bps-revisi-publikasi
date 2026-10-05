<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('examiner');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('ai_provider_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('base_url');
            $table->text('api_key');
            $table->string('model');
            $table->timestamp('tested_at')->nullable();
            $table->timestamp('vision_tested_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('title');
            $table->string('region');
            $table->unsignedSmallInteger('year');
            $table->string('kind')->default('kcda');
            $table->text('notes')->nullable();
            $table->string('pdf_path');
            $table->string('pdf_name');
            $table->string('pdf_checksum', 64);
            $table->string('template_path');
            $table->string('template_name');
            $table->string('template_checksum', 64);
            $table->unsignedInteger('page_count');
            $table->string('status')->default('draft')->index();
            $table->json('manifest');
            $table->foreignId('ai_config_id')->nullable()->constrained('ai_provider_configs');
            $table->string('rule_version');
            $table->string('prompt_version')->default('page-v1');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['owner_id', 'status']);
        });
        Schema::create('publication_user', function (Blueprint $table): void {
            $table->foreignUuid('publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['publication_id', 'user_id']);
        });
        Schema::create('publication_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('publication_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('physical_page');
            $table->string('status')->default('pending')->index();
            $table->string('image_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('image_checksum', 64)->nullable();
            $table->unsignedInteger('dpi')->default(160);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('page_type')->nullable();
            $table->string('printed_page')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['publication_id', 'physical_page']);
        });
        Schema::create('findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('publication_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('rule_code');
            $table->string('section');
            $table->string('severity');
            $table->string('source');
            $table->float('confidence')->nullable();
            $table->text('observed');
            $table->text('expected');
            $table->text('recommendation');
            $table->json('evidences');
            $table->string('review_status')->default('unreviewed');
            $table->text('review_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->unique(['publication_id', 'fingerprint']);
        });
        Schema::create('finding_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->json('before');
            $table->json('after');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('publication_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('publication_revision');
            $table->string('status')->default('pending');
            $table->string('path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('finding_count')->default(0);
            $table->json('validation')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['publication_id', 'version']);
        });
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignUuid('publication_id')->nullable()->constrained();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('publication_id')->constrained();
            $table->foreignId('page_id')->nullable()->constrained('publication_pages');
            $table->string('model');
            $table->string('request_id')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ai_usage_records', 'audit_events', 'exports', 'finding_revisions', 'findings', 'publication_pages', 'publication_user', 'publications', 'ai_provider_configs'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active']));
    }
};
