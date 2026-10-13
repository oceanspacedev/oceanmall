import { API_URL } from '@/lib/config';

export function formatIdr(amount: number | null | undefined): string {
  const value = typeof amount === 'number' ? amount : 0;

  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value);
}

export function discountPercent(price?: number | null, compare?: number | null): number | null {
  if (price == null || compare == null || compare <= price) {
    return null;
  }

  return Math.max(1, Math.round(((compare - price) / compare) * 100));
}

export function mediaUrl(url?: string | null): string | undefined {
  if (!url) {
    return undefined;
  }
  const origin = API_URL.replace(/\/api\/v1\/?$/, '');

  if (/^https?:\/\//i.test(url)) {
    // A dev backend often renders media against APP_URL=localhost (wrong port,
    // and unreachable from an emulator or phone): serve it from the API origin.
    const loopback = url.match(
      /^https?:\/\/(?:localhost|127\.0\.0\.1|0\.0\.0\.0)(?::\d+)?(\/.*)?$/i
    );
    return loopback ? `${origin}${loopback[1] ?? ''}` : url;
  }

  return `${origin}${url.startsWith('/') ? '' : '/'}${url}`;
}

export function formatDate(value?: string | null): string {
  if (!value) {
    return '';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(date);
}

const STATUS_LABELS: Record<string, string> = {
  new: 'Baru',
  pending: 'Menunggu',
  labeled: 'Menunggu pickup',
  picked_up: 'Dijemput kurir',
  confirmed: 'Dikonfirmasi',
  processing: 'Diproses',
  completed: 'Selesai',
  paid: 'Dibayar',
  unpaid: 'Belum bayar',
  authorized: 'Otorisasi',
  shipped: 'Dikirim',
  in_transit: 'Dalam perjalanan',
  delivered: 'Terkirim',
  cancelled: 'Dibatalkan',
  canceled: 'Dibatalkan',
  failed: 'Gagal',
  refunded: 'Dikembalikan',
  partially_paid: 'Sebagian dibayar',
  voided: 'Dibatalkan',
  returned: 'Dikembalikan',
  unfulfilled: 'Belum diproses',
  fulfilled: 'Diproses',
  partially_shipped: 'Dikirim sebagian',
  ready_for_pickup: 'Siap diambil',
};

export function statusLabel(status?: string | null): string {
  if (!status) {
    return '';
  }

  return STATUS_LABELS[status] ?? status.split('_').join(' ');
}

const PAYMENT_TYPE_LABELS: Record<string, string> = {
  bank_transfer: 'Transfer virtual account',
  va: 'Transfer virtual account',
  qris: 'QRIS',
  ewallet: 'E-wallet',
};

export function paymentTypeLabel(type?: string | null): string {
  if (!type) {
    return '';
  }

  return PAYMENT_TYPE_LABELS[type.toLowerCase()] ?? type.split('_').join(' ');
}

/** e.g. "14 Okt 2026, 09.37" for gateway expiry timestamps. */
export function formatDateTime(value?: string | null): string {
  if (!value) {
    return '';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

export function stripHtml(value?: string | null): string {
  if (!value) {
    return '';
  }

  return value
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}
