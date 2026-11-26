<?php

declare(strict_types=1);

use Database\Seeders\GranularPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new GranularPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Permissions remain intact on rollback to avoid breaking role grants.
    }
};
