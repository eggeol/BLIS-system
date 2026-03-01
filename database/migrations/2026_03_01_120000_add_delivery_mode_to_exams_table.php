<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('exams', 'delivery_mode')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->enum('delivery_mode', ['standard', 'live_quiz'])
                    ->default('standard')
                    ->after('scheduled_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('exams', 'delivery_mode')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropColumn('delivery_mode');
            });
        }
    }
};
