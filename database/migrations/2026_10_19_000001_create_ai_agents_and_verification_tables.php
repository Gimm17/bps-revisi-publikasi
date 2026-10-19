<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('slot')->unique();
            $table->string('name', 100);
            $table->string('base_url', 500);
            $table->text('api_key');
            $table->string('model', 150);
            $table->boolean('enabled')->default(true)->index();
            $table->string('status', 30)->default('untested')->index();
            $table->text('test_message')->nullable();
            $table->timestamp('vision_tested_at')->nullable();
            $table->string('config_hash', 64)->nullable();
            $table->unsignedTinyInteger('max_concurrency')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('publication_ai_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('publication_id')->constrained('publications')->cascadeOnDelete();
            $table->foreignId('ai_agent_id')->constrained('ai_agents')->cascadeOnDelete();
            $table->string('config_hash_at_start', 64);
            $table->timestamps();
            $table->unique(['publication_id', 'ai_agent_id']);
        });

        Schema::create('page_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publication_page_id')->constrained('publication_pages')->cascadeOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->json('result')->nullable();
            $table->json('agreement')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['publication_page_id', 'status']);
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->string('cross_verify_mode', 30)->default('high_risk')->after('ai_config_id');
        });

        Schema::table('publication_pages', function (Blueprint $table): void {
            $table->foreignId('ai_agent_id')->nullable()->after('publication_id')->constrained('ai_agents')->nullOnDelete();
        });

        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->foreignId('ai_agent_id')->nullable()->after('page_id')->constrained('ai_agents')->nullOnDelete();
        });

        Schema::table('findings', function (Blueprint $table): void {
            $table->string('agreement', 30)->nullable()->index()->after('review_note');
            $table->json('agent_ids')->nullable()->after('agreement');
        });

        // Migrate existing AiProviderConfig to slot 1 if present
        $existing = DB::table('ai_provider_configs')->latest('id')->first();
        if ($existing) {
            $hash = hash('sha256', $existing->base_url.'|'.$existing->model.'|'.$existing->api_key);
            DB::table('ai_agents')->insert([
                'slot' => 1,
                'name' => 'Agent 1 (Utama)',
                'base_url' => $existing->base_url,
                'api_key' => $existing->api_key,
                'model' => $existing->model,
                'enabled' => true,
                'status' => $existing->vision_tested_at ? 'ready' : 'untested',
                'test_message' => $existing->vision_tested_at ? 'Lulus uji pembacaan gambar.' : null,
                'vision_tested_at' => $existing->vision_tested_at,
                'config_hash' => $hash,
                'max_concurrency' => 1,
                'created_by' => $existing->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            if (Schema::hasColumn('findings', 'agreement')) {
                $table->dropIndex(['agreement']);
                $table->dropColumn(['agreement', 'agent_ids']);
            }
        });

        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_agent_id');
        });

        Schema::table('publication_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_agent_id');
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->dropColumn('cross_verify_mode');
        });

        Schema::dropIfExists('page_verifications');
        Schema::dropIfExists('publication_ai_agents');
        Schema::dropIfExists('ai_agents');
    }
};
