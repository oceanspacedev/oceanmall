<?php

declare(strict_types=1);

// Indonesian override for shopper/core status labels (OrderStatus, PaymentStatus,
// ShippingStatus, ...). Used by the cpanel order tabs and order detail badges.
// Wording follows resources/js/components/account/order-status-badge.vue.

return [
    'archived' => 'Diarsipkan',
    'awaiting' => 'Menunggu',
    'cancelled' => 'Dibatalkan',
    'completed' => 'Selesai',
    'new' => 'Baru',
    'not_paid' => 'Belum dibayar',
    'not_refunded' => 'Belum dikembalikan',
    'partial-refund' => 'Dikembalikan sebagian',
    'pending' => 'Menunggu',
    'processing' => 'Diproses',
    'treatment' => 'Ditangani',
    'refunded' => 'Dikembalikan',
    'rejected' => 'Ditolak',

    'payment' => [
        'pending' => 'Menunggu pembayaran',
        'authorized' => 'Otorisasi',
        'paid' => 'Dibayar',
        'partially_refunded' => 'Dikembalikan sebagian',
        'refunded' => 'Dikembalikan',
        'voided' => 'Dibatalkan',
    ],

    'shipping' => [
        'unfulfilled' => 'Belum diproses',
        'partially_shipped' => 'Dikirim sebagian',
        'shipped' => 'Dikirim',
        'partially_delivered' => 'Terkirim sebagian',
        'delivered' => 'Terkirim',
        'partially_returned' => 'Dikembalikan sebagian',
        'returned' => 'Dikembalikan',
    ],

    'fulfillment' => [
        'pending' => 'Menunggu diproses',
        'forwarded' => 'Diteruskan ke pemasok',
        'processing' => 'Disiapkan',
        'shipped' => 'Dikirim',
        'delivered' => 'Terkirim',
        'cancelled' => 'Dibatalkan',
    ],

    'shipment' => [
        'pending' => 'Label dibuat',
        'picked_up' => 'Sudah dijemput',
        'in_transit' => 'Dalam perjalanan',
        'at_sorting_center' => 'Di pusat sortir',
        'out_for_delivery' => 'Sedang diantar',
        'delivered' => 'Terkirim',
        'delivery_failed' => 'Pengiriman gagal',
        'returned' => 'Dikembalikan',
    ],

    'stock' => [
        'reserved' => 'Dipesan untuk pesanan',
        'cancelled' => 'Dilepas dari pesanan yang dibatalkan',
    ],

    'discount' => [
        'draft' => 'Draf',
        'scheduled' => 'Terjadwal',
        'active' => 'Aktif',
        'disabled' => 'Nonaktif',
        'expired' => 'Kedaluwarsa',
        'limit_reached' => 'Batas tercapai',
    ],
];
