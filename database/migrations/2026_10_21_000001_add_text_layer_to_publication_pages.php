<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_pages', function (Blueprint $table): void {
            $table->longText('text_layer')->nullable()->after('result');
            $table->string('text_layer_status', 32)->nullable()->after('text_layer');
        });
    }

    public function down(): void
    {
        Schema::table('publication_pages', function (Blueprint $table): void {
            $table->dropColumn(['text_layer', 'text_layer_status']);
        });
    }
};
