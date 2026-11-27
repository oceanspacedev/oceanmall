import type { OrderDetail } from '@/lib/api';
import { parseApiDate } from '@/lib/format';

export function expiredPaymentMessage(
  canRetry: boolean,
  variant: 'panel' | 'placed' = 'panel'
): string {
  if (!canRetry) {
    return 'Batas pembayaran sudah lewat. Pembayaran ulang tidak tersedia untuk pesanan ini.';
  }
  return variant === 'placed'
    ? 'Batas pembayaran sudah lewat. Buka pesananmu untuk membuat pembayaran baru.'
    : 'Batas pembayaran sudah lewat. Buat pembayaran baru untuk melanjutkan.';
}

/** A fresh charge is only useful when there is none, or the current one has expired. */
export function paymentNeedsRenewal(
  payment: OrderDetail['payment'] | null | undefined,
  now: number,
  parseDateFn: (value?: string | null) => Date | null = parseApiDate
): boolean {
  if (
    !payment ||
    (!payment.virtual_account_number && !payment.qris_string && !payment.payment_url)
  ) {
    return true;
  }
  const expires = parseDateFn(payment.expiry_date)?.getTime();

  return expires !== undefined && expires <= now;
}

export function trimmedAwb(
  shipment: Pick<OrderDetail['shipments'][number], 'awb' | 'tracking_number'>
): string | null {
  const awb = (shipment.awb ?? '').trim() || (shipment.tracking_number ?? '').trim();
  return awb !== '' ? awb : null;
}

export function canTrackShipment(
  shipment: Pick<OrderDetail['shipments'][number], 'awb' | 'tracking_number' | 'can_track'>
): boolean {
  return trimmedAwb(shipment) !== null && shipment.can_track !== false;
}

export function trackingToastMessage(trackingRefreshed?: boolean): string {
  return trackingRefreshed === false
    ? 'Status pengiriman sudah yang terbaru'
    : 'Status pengiriman diperbarui';
}

export function canConfirmOrderReceived(
  order: Pick<OrderDetail, 'status' | 'payment_status' | 'shipping_status' | 'is_cod' | 'shipments'>
): boolean {
  if (order.status === 'completed' || order.status === 'cancelled') {
    return false;
  }

  if (order.shipping_status === 'delivered') {
    return false;
  }

  if (order.payment_status !== 'paid' && !order.is_cod) {
    return false;
  }

  // Every parcel that still travels must have shipped (cancelled or superseded reshipped parcels are excluded).
  const liveShipments = order.shipments.filter(
    (shipment) => shipment.status.toLowerCase() !== 'cancelled' && !shipment.is_superseded
  );

  if (liveShipments.length === 0) {
    return false;
  }

  if (!liveShipments.some((shipment) => shipment.status.toLowerCase() !== 'delivered')) {
    return false;
  }

  return liveShipments.every((shipment) => {
    const status = shipment.status.toLowerCase();
    return status !== 'returned' && status !== 'delivery_failed' && trimmedAwb(shipment) !== null;
  });
}
