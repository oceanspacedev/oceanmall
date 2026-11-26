<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class GranularPermissionsSeeder extends Seeder
{
    /**
     * @var array<string, array{group: string, display: string, description: string}>
     */
    public const PERMISSIONS = [
        'orders.mark_paid' => [
            'group' => 'orders',
            'display' => 'Konfirmasi Pembayaran Order',
            'description' => 'Memverifikasi bukti transfer manual, capture, & pelunasan COD.',
        ],
        'orders.cancel' => [
            'group' => 'orders',
            'display' => 'Batalkan / Arsipkan Order',
            'description' => 'Membatalkan atau mengarsipkan pesanan sesuai otorisasi CS/Manajer.',
        ],
        'orders.refund' => [
            'group' => 'orders',
            'display' => 'Proses Refund & Sengketa',
            'description' => 'Mencatat refund parsial/penuh dan menyelesaikan sengketa dana.',
        ],
        'orders.fulfill' => [
            'group' => 'orders',
            'display' => 'Fulfillment & Pengiriman Order',
            'description' => 'Memproses pesanan, menerbitkan resi, & memperbarui pelacakan gudang.',
        ],
        'orders.override_allocation' => [
            'group' => 'orders',
            'display' => 'Override Alokasi Gudang',
            'description' => 'Memindahkan alokasi stok shipment antar gudang sebelum pengiriman.',
        ],
        'finance.view_dashboard' => [
            'group' => 'finance',
            'display' => 'Lihat Metrik Keuangan & Laporan',
            'description' => 'Mengakses grafik omzet, margin kotor, laporan PPN 11%, & export akuntansi.',
        ],
    ];

    public function run(): void
    {
        $permissionsTable = (string) config('permission.table_names.permissions', 'permissions');

        if (! Schema::hasTable($permissionsTable)) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $created = [];

        foreach (self::PERMISSIONS as $name => $meta) {
            $created[] = Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                [
                    'group_name' => $meta['group'],
                    'display_name' => $meta['display'],
                    'description' => $meta['description'],
                    'can_be_removed' => false,
                ],
            );
        }

        $adminRole = Role::query()
            ->where('name', (string) config('shopper.admin.roles.admin', 'administrator'))
            ->where('guard_name', 'web')
            ->first();

        if ($adminRole instanceof Role) {
            $adminRole->givePermissionTo($created);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
