<?php

use App\Models\Exam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('exams')->update([
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
        ]);
    }

    public function down(): void
    {
        // No-op: this migration intentionally collapses legacy delivery modes into one supported value.
    }
};
