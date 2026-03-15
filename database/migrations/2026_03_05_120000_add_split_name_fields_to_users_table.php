<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
        });

        DB::table('users')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $name = trim((string) ($user->name ?? ''));

                    if ($name === '') {
                        continue;
                    }

                    $parts = preg_split('/\s+/', $name) ?: [];

                    if ($parts === []) {
                        continue;
                    }

                    $firstName = trim((string) array_shift($parts));
                    $lastName = '';
                    $middleName = null;

                    if (count($parts) > 0) {
                        $lastName = trim((string) array_pop($parts));
                        $middleName = count($parts) > 0
                            ? trim((string) implode(' ', $parts))
                            : null;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'first_name' => $firstName !== '' ? $firstName : null,
                            'middle_name' => $middleName !== '' ? $middleName : null,
                            'last_name' => $lastName !== '' ? $lastName : null,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }
};
