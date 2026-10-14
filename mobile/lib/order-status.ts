import type { PillTone } from '@/components/shop';
import type { OrderSummary } from '@/lib/api';

export type OrderTab = 'unpaid' | 'processing' | 'shipped' | 'completed' | 'cancelled';

/**
 * The one vocabulary for an order's stage. The tab strip on Pesanan, the pill on each order card
 * and the banner on the detail page all read these five labels (plus "Tiba di tujuan" as the last
 * state of "Dikirim"), so the customer sees the same word everywhere.
 */
export const ORDER_TABS: { key: OrderTab | 'all'; label: string }[] = [
  { key: 'all', label: 'Semua' },
  { key: 'unpaid', label: 'Belum bayar' },
  { key: 'processing', label: 'Diproses' },
  { key: 'shipped', label: 'Dikirim' },
  { key: 'completed', label: 'Selesai' },
  { key: 'cancelled', label: 'Dibatalkan' },
];

type StageInput = Pick<OrderSummary, 'status' | 'payment_status' | 'shipping_status'> & {
  /** Present on the detail payload; the list payload has none. */
  shipments?: { status?: string | null; awb?: string | null; tracking_number?: string | null }[];
};

export type OrderStage = {
  key: OrderTab;
  label: string;
  tone: PillTone;
  hint: string;
};

const CANCELLED_STATUSES = ['cancelled', 'canceled'];
const CLOSED_PAYMENTS = ['voided', 'refunded'];
/** Shipment statuses (not the order's) from the courier registration onwards. */
const SHIPPED_SHIPMENT = /labeled|picked_up|shipped|in_transit|delivered/;
const MOVING_SHIPMENT = /picked_up|shipped|in_transit|delivered/;

export function isCancelled(order: Pick<OrderSummary, 'status' | 'payment_status'>): boolean {
  return (
    CANCELLED_STATUSES.includes(order.status) || CLOSED_PAYMENTS.includes(order.payment_status)
  );
}

/** The package has arrived but the customer has not confirmed it yet. */
export function awaitsReceiptConfirmation(
  order: Pick<OrderSummary, 'status' | 'shipping_status'>
): boolean {
  return order.shipping_status === 'delivered' && order.status !== 'completed';
}

/**
 * Whether the customer may review the items: the backend accepts a review once the order is
 * completed or delivered (its purchaser check), so the button is offered under the same rule.
 */
export function canReviewOrder(order: Pick<OrderSummary, 'status' | 'shipping_status'>): boolean {
  return order.status === 'completed' || order.shipping_status === 'delivered';
}

/** One customer-facing stage per order, mirroring the API's order tabs. */
export function orderStage(order: StageInput): OrderStage {
  if (isCancelled(order)) {
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
      label: 'Belum bayar',
      tone: 'warning',
      hint: 'Selesaikan pembayaran sebelum batas waktu.',
    };
  }
  if (order.shipping_status && order.shipping_status !== 'unfulfilled') {
    if (order.shipping_status === 'delivered') {
      return {
        key: 'shipped',
        label: 'Tiba di tujuan',
        tone: 'navy',
        hint: 'Paket sudah tiba. Konfirmasi penerimaan, ya.',
      };
    }

    const statuses = (order.shipments ?? []).map((shipment) =>
      (shipment.status ?? '').toLowerCase()
    );
    const waitingForPickup =
      statuses.length > 0 &&
      statuses.some((status) => status === 'labeled') &&
      !statuses.some((status) => MOVING_SHIPMENT.test(status));

    return {
      key: 'shipped',
      label: 'Dikirim',
      tone: 'navy',
      hint: waitingForPickup
        ? 'Pesanan sudah terdaftar di kurir, menunggu penjemputan.'
        : 'Paket sedang dalam perjalanan.',
    };
  }

  return {
    key: 'processing',
    label: 'Diproses',
    tone: 'navy',
    hint: 'Pesananmu sedang disiapkan.',
  };
}

export type OrderStepKey = 'ordered' | 'paid' | 'packed' | 'shipped' | 'delivered' | 'done';

export type OrderStep = {
  key: OrderStepKey | 'cancelled';
  label: string;
  done: boolean;
};

/**
 * The progress strip on the detail page: Dipesan, Dibayar, Dikemas, Dikirim, Tiba, Selesai.
 * A later step being done implies the earlier ones are (an order can be completed without a
 * shipment row, for example), so the strip never shows a gap. A cancelled order is a single
 * "Dibatalkan" step instead.
 */
export function orderSteps(order: StageInput): OrderStep[] {
  if (isCancelled(order)) {
    return [{ key: 'cancelled', label: 'Dibatalkan', done: true }];
  }

  const shipments = order.shipments ?? [];
  const completed = order.status === 'completed';
  const shippingStatus = order.shipping_status ?? 'unfulfilled';
  const hasAwb = shipments.some((shipment) => Boolean(shipment.awb || shipment.tracking_number));

  const flags: boolean[] = [
    true,
    order.payment_status === 'paid' || completed,
    shippingStatus !== 'unfulfilled' || hasAwb,
    shipments.some((shipment) => SHIPPED_SHIPMENT.test((shipment.status ?? '').toLowerCase())) ||
      ['shipped', 'partially_shipped', 'partially_delivered', 'delivered'].includes(shippingStatus),
    shippingStatus === 'delivered' || completed,
    completed,
  ];
  for (let index = flags.length - 2; index >= 0; index -= 1) {
    flags[index] = flags[index] || flags[index + 1];
  }

  const labels: [OrderStepKey, string][] = [
    ['ordered', 'Dipesan'],
    ['paid', 'Dibayar'],
    ['packed', 'Dikemas'],
    ['shipped', 'Dikirim'],
    ['delivered', 'Tiba'],
    ['done', 'Selesai'],
  ];

  return labels.map(([key, label], index) => ({ key, label, done: flags[index] }));
}
