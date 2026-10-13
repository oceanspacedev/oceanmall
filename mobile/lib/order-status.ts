import type { PillTone } from '@/components/shop';
import type { OrderSummary } from '@/lib/api';

export type OrderTab = 'unpaid' | 'processing' | 'shipped' | 'completed' | 'cancelled';

export const ORDER_TABS: { key: OrderTab | 'all'; label: string }[] = [
  { key: 'all', label: 'Semua' },
  { key: 'unpaid', label: 'Belum bayar' },
  { key: 'processing', label: 'Diproses' },
  { key: 'shipped', label: 'Dikirim' },
  { key: 'completed', label: 'Selesai' },
  { key: 'cancelled', label: 'Dibatalkan' },
];

/** One customer-facing stage per order, mirroring the API's order tabs. */
export function orderStage(
  order: Pick<OrderSummary, 'status' | 'payment_status' | 'shipping_status'>
): {
  key: OrderTab;
  label: string;
  tone: PillTone;
  hint: string;
} {
  if (order.status === 'cancelled' || ['voided', 'refunded'].includes(order.payment_status)) {
    return { key: 'cancelled', label: 'Dibatalkan', tone: 'muted', hint: 'Pesanan dibatalkan.' };
  }
  if (order.status === 'completed') {
    return {
      key: 'completed',
      label: 'Selesai',
      tone: 'success',
      hint: 'Pesanan sudah diterima. Terima kasih!',
    };
  }
  if (order.payment_status !== 'paid') {
    return {
      key: 'unpaid',
      label: 'Menunggu pembayaran',
      tone: 'warning',
      hint: 'Selesaikan pembayaran sebelum batas waktu.',
    };
  }
  if (order.shipping_status && order.shipping_status !== 'unfulfilled') {
    return order.shipping_status === 'delivered'
      ? {
          key: 'shipped',
          label: 'Tiba di tujuan',
          tone: 'navy',
          hint: 'Paket sudah sampai. Konfirmasi jika sudah diterima.',
        }
      : { key: 'shipped', label: 'Dikirim', tone: 'navy', hint: 'Paket sedang dalam perjalanan.' };
  }
  return {
    key: 'processing',
    label: 'Diproses',
    tone: 'navy',
    hint: 'Penjual sedang menyiapkan pesananmu.',
  };
}
