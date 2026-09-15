<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE events ALTER COLUMN starts_at TYPE timestamptz(0) USING starts_at AT TIME ZONE 'UTC'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE events ALTER COLUMN starts_at TYPE timestamp(0) USING starts_at AT TIME ZONE 'UTC'");
    }
};
