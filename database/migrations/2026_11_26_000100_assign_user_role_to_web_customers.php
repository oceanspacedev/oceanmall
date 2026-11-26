<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        $modelHasRolesTable = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
        $usersTable = (new User)->getTable();

        if (! Schema::hasTable($rolesTable) || ! Schema::hasTable($modelHasRolesTable) || ! Schema::hasTable($usersTable)) {
            return;
        }

        $roleName = (string) config('shopper.admin.roles.user', 'user');

        $roleId = DB::table($rolesTable)
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->value('id');

        if (! is_numeric($roleId)) {
            return;
        }

        $morphClass = (new User)->getMorphClass();
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?: 'model_id');
        $roleKey = (string) (config('permission.column_names.role_pivot_key') ?: 'role_id');

        $userIds = DB::table($usersTable)
            ->whereNotExists(static function ($query) use ($modelHasRolesTable, $usersTable, $morphClass, $modelKey): void {
                $query->select(DB::raw(1))
                    ->from($modelHasRolesTable)
                    ->whereColumn("{$modelHasRolesTable}.{$modelKey}", "{$usersTable}.id")
                    ->where("{$modelHasRolesTable}.model_type", $morphClass);
            })
            ->pluck('id');

        foreach ($userIds->chunk(500) as $chunk) {
            $rows = $chunk->map(static fn (int|string $id): array => [
                $roleKey => (int) $roleId,
                'model_type' => $morphClass,
                $modelKey => (int) $id,
            ])->all();

            DB::table($modelHasRolesTable)->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // One-way data backfill; existing customer role assignments are preserved.
    }
};
