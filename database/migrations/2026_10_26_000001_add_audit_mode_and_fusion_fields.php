<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            if (! Schema::hasColumn('publications', 'audit_mode')) {
                $table->string('audit_mode', 16)->default('ai')->index()->after('kind');
            }
            if (! Schema::hasColumn('publications', 'fusion_summary')) {
                $table->json('fusion_summary')->nullable()->after('manifest');
            }
        });

        Schema::table('publication_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('publication_pages', 'machine_result')) {
                $table->json('machine_result')->nullable()->after('result');
            }
            if (! Schema::hasColumn('publication_pages', 'machine_status')) {
                $table->string('machine_status', 16)->nullable()->after('status');
            }
            if (! Schema::hasColumn('publication_pages', 'fusion_status')) {
                $table->string('fusion_status', 24)->nullable()->after('machine_status');
            }
        });

        Schema::table('findings', function (Blueprint $table): void {
            if (! Schema::hasColumn('findings', 'detected_by')) {
                $table->string('detected_by', 16)->default('ai')->after('source');
            }
            if (! Schema::hasColumn('findings', 'fusion_status')) {
                $table->string('fusion_status', 24)->nullable()->after('detected_by');
            }
        });

        Schema::table('ai_usage_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_usage_records', 'purpose')) {
                $table->string('purpose', 24)->default('page')->after('agent_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_usage_records', 'purpose')) {
                $table->dropColumn('purpose');
            }
        });

        Schema::table('findings', function (Blueprint $table): void {
            $cols = array_filter(['detected_by', 'fusion_status'], fn ($c) => Schema::hasColumn('findings', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('publication_pages', function (Blueprint $table): void {
            $cols = array_filter(['machine_result', 'machine_status', 'fusion_status'], fn ($c) => Schema::hasColumn('publication_pages', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('publications', function (Blueprint $table): void {
            $cols = array_filter(['audit_mode', 'fusion_summary'], fn ($c) => Schema::hasColumn('publications', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
