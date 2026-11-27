import type {
  Cart,
  CheckoutAllocationItem,
  OrderSummary,
  PaymentMethodOption,
  ShippingRate,
} from '@/lib/api';
import { formatMoney, parseApiDate } from '@/lib/format';

export const PIN_POINT_REGEX = /^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/;

export function etaLabel(value?: string | number | null): string | null {
  if (value === null || value === undefined || value === '') {
    return 'Estimasi belum tersedia';
  }
  const text = String(value);
  return /hari|day|jam/i.test(text) ? `Estimasi ${text}` : `Estimasi ${text} hari`;
}

/**
 * Returns whether a shipping rate supports Cash on Delivery:
 * - `false` if any known COD flag (`is_cod`, `kiriminaja_cod`, `available_for_cash_on_delivery`) is explicitly false
 * - `true` if any known COD flag is explicitly true
 * - `null` when the rate does not carry COD capability metadata
 */
export function rateCodStatus(
  rate:
    | Pick<ShippingRate, 'is_cod' | 'kiriminaja_cod' | 'available_for_cash_on_delivery'>
    | null
    | undefined
): boolean | null {
  if (!rate) {
    return null;
  }
  const flags = [rate.is_cod, rate.kiriminaja_cod, rate.available_for_cash_on_delivery];
  if (flags.some((flag) => flag === false)) {
    return false;
  }
  if (flags.some((flag) => flag === true)) {
    return true;
  }
  return null;
}

export function rateSupportsCod(
  rate:
    | Pick<ShippingRate, 'is_cod' | 'kiriminaja_cod' | 'available_for_cash_on_delivery'>
    | null
    | undefined
): boolean {
  return rateCodStatus(rate) !== false;
}

export function allSelectedRatesSupportCod({
  isSplitShipment,
  allocation,
  shippingRates,
  selectedRate,
  shippingRatesByShipment,
  selectedRatesByShipment,
}: {
  isSplitShipment: boolean;
  allocation: Pick<CheckoutAllocationItem, 'inventory_id'>[];
  shippingRates: ShippingRate[];
  selectedRate: string | null;
  shippingRatesByShipment?: Record<string, ShippingRate[]>;
  selectedRatesByShipment: Record<string, string>;
}): boolean {
  if (!isSplitShipment) {
    if (!selectedRate) {
      return true;
    }
    const rate = shippingRates.find((row) => String(row.service_code) === selectedRate);
    return rateSupportsCod(rate);
  }

  for (const shipment of allocation) {
    const invKey = String(shipment.inventory_id);
    const pickedCode = selectedRatesByShipment[invKey];
    if (!pickedCode) {
      continue;
    }
    const pkgRates = shippingRatesByShipment?.[invKey] ?? [];
    const rate = pkgRates.find((row) => String(row.service_code) === pickedCode);
    if (!rateSupportsCod(rate)) {
      return false;
    }
  }

  return true;
}

export function isCodPaymentMethod(
  method: Pick<PaymentMethodOption, 'slug' | 'payment_type'> | null | undefined
): boolean {
  if (!method) {
    return false;
  }
  const slug = (method.slug ?? '').trim().toLowerCase();
  const type = (method.payment_type ?? '').trim().toLowerCase();
  return slug === 'cod' || type === 'cod';
}

export function paymentLimitNote(
  method: Pick<PaymentMethodOption, 'min_amount' | 'max_amount'>,
  amount: number,
  currency = 'IDR',
  formatMoneyFn: (amount: number | null | undefined, currency?: string) => string = formatMoney
): string | null {
  if (method.min_amount && amount < method.min_amount) {
    return `Minimal ${formatMoneyFn(method.min_amount, currency)} untuk metode ini.`;
  }
  if (method.max_amount && amount > method.max_amount) {
    return `Maksimal ${formatMoneyFn(method.max_amount, currency)} untuk metode ini.`;
  }
  return null;
}

export function paymentDisabledReason(
  method: Pick<PaymentMethodOption, 'slug' | 'payment_type' | 'min_amount' | 'max_amount'>,
  {
    amount,
    currency = 'IDR',
    ratesSupportCod,
  }: {
    amount: number;
    currency?: string;
    ratesSupportCod: boolean;
  },
  formatMoneyFn: (amount: number | null | undefined, currency?: string) => string = formatMoney
): string | null {
  const limit = paymentLimitNote(method, amount, currency, formatMoneyFn);
  if (limit !== null) {
    return limit;
  }
  if (isCodPaymentMethod(method) && !ratesSupportCod) {
    return 'Kurir yang dipilih tidak mendukung Cash on Delivery (COD).';
  }
  return null;
}

export function resolveEmptyShippingHint({
  addressPresent,
  searchEnabled,
  shippingRatesHint,
}: {
  addressPresent: boolean;
  searchEnabled: boolean;
  shippingRatesHint?: string | null;
}): string {
  const hint = shippingRatesHint?.trim();
  if (hint) {
    return hint;
  }
  if (!addressPresent) {
    return 'Isi alamat dulu untuk melihat pilihan kurir dan ongkir.';
  }
  return searchEnabled
    ? 'Ongkir belum tersedia untuk alamat ini. Coba kecamatan lain.'
    : 'Ongkir belum tersedia untuk alamat ini. Periksa kembali alamat pengirimanmu.';
}

export function resolveDestinationSearchError(
  error: unknown,
  errorMessageFn: (error: unknown, fallback?: string) => string
): string {
  return errorMessageFn(error, 'Pencarian kecamatan sedang gangguan. Coba lagi beberapa menit.');
}

export function matchPlacedOrder(
  orders: OrderSummary[],
  submittedAtMs: number,
  parseDateFn: (value?: string | null) => Date | null = parseApiDate
): string | null {
  const latest = orders.find((row) => {
    const status = (row.status ?? '').toLowerCase();
    if (status === 'cancelled' || status === 'canceled') {
      return false;
    }
    const ts = parseDateFn(row.created_at)?.getTime();
    return ts !== undefined && ts >= submittedAtMs - 60_000;
  });

  return latest ? latest.number : null;
}

export async function findPlacedOrderWith(
  fetcher: <T>(path: string) => Promise<T>,
  submittedAtMs: number,
  parseDateFn: (value?: string | null) => Date | null = parseApiDate
): Promise<{ state: 'placed'; number: string } | { state: 'none' } | { state: 'unknown' }> {
  try {
    const cart = await fetcher<{ data: Cart }>('/cart');
    if ((cart.data?.lines ?? []).length > 0) {
      return { state: 'none' };
    }
    // Query unfiltered /orders (not ?status=unpaid) so newly placed COD orders are also recovered.
    const orders = await fetcher<{ data: OrderSummary[] }>('/orders');
    const matched = matchPlacedOrder(orders.data ?? [], submittedAtMs, parseDateFn);

    return matched ? { state: 'placed', number: matched } : { state: 'unknown' };
  } catch {
    return { state: 'unknown' };
  }
}
