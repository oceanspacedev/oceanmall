import { API_URL } from '@/lib/config';

/**
 * Rupiah with dot grouping and no decimals: formatIdr(1500000) === 'Rp1.500.000'.
 * Hand-rolled on purpose: Hermes uses the phone's ICU, so Intl.NumberFormat puts a
 * non-breaking space after "Rp" on some devices and ",00" on others. A negative
 * amount gets a real minus sign (U+2212), matching the discount rows that print
 * their own prefix.
 */
export function formatIdr(amount: number | null | undefined): string {
  const value = typeof amount === 'number' && Number.isFinite(amount) ? amount : 0;
  const rounded = Math.round(Math.abs(value));
  const grouped = String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

  return `${value < 0 && rounded > 0 ? '\u2212' : ''}Rp${grouped}`;
}

function shortDecimal(value: number): string {
  return String(value).replace('.', ',');
}

/**
 * Compact counts for social proof ("Terjual 1,2 rb"): 950, 1,2 rb, 12,5 rb, 1,3 jt.
 * Truncates instead of rounding so 999.999 never reads as "1000 rb".
 */
export function formatCount(count: number | null | undefined): string {
  const n = Math.max(
    0,
    Math.floor(typeof count === 'number' && Number.isFinite(count) ? count : 0)
  );
  if (n < 1000) {
    return String(n);
  }
  if (n < 1_000_000) {
    return `${shortDecimal(Math.floor(n / 100) / 10)} rb`;
  }

  return `${shortDecimal(Math.floor(n / 100_000) / 10)} jt`;
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

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/** WIB is UTC+7 all year (Indonesia has no daylight saving). */
const WIB_OFFSET_MS = 7 * 60 * 60 * 1000;
const DAY_MS = 24 * 60 * 60 * 1000;

/** A date-time with no zone ("2026-10-14 09:37:00"), which the backend means as WIB. */
const ZONELESS = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/;

/**
 * Parses a timestamp from the API. The app runs in Asia/Jakarta and some gateways
 * (QRISLY) return times without a zone: those are read as +07:00, not as the
 * phone's local time. Anything with a zone ("...Z", "+07:00") is parsed as is.
 * Returns null when the value is empty or not a date.
 */
export function parseApiDate(value?: string | null): Date | null {
  if (!value) {
    return null;
  }

  let text = value.trim();
  // Hermes only reliably reads millisecond precision; Laravel sends microseconds.
  text = text.replace(/\.(\d+)/, (_, fraction: string) => `.${`${fraction}000`.slice(0, 3)}`);
  if (ZONELESS.test(text)) {
    text = text.replace(' ', 'T');
    if (/T\d{2}:\d{2}$/.test(text)) {
      text += ':00';
    }
    text += '+07:00';
  }

  const date = new Date(text);

  return Number.isNaN(date.getTime()) ? null : date;
}

type WibParts = { year: number; month: number; day: number; hour: number; minute: number };

/**
 * Calendar fields of an instant in WIB, computed by hand. Intl.DateTimeFormat is
 * avoided because Hermes uses the OS ICU, so its output differs per device.
 */
function wibParts(date: Date): WibParts {
  const wib = new Date(date.getTime() + WIB_OFFSET_MS);

  return {
    year: wib.getUTCFullYear(),
    month: wib.getUTCMonth(),
    day: wib.getUTCDate(),
    hour: wib.getUTCHours(),
    minute: wib.getUTCMinutes(),
  };
}

function pad2(n: number): string {
  return n < 10 ? `0${n}` : String(n);
}

function clock(parts: WibParts): string {
  return `${pad2(parts.hour)}:${pad2(parts.minute)}`;
}

/** e.g. "14 Okt 2026" (in WIB). Unparseable input is returned as is. */
export function formatDate(value?: string | null): string {
  if (!value) {
    return '';
  }
  const date = parseApiDate(value);
  if (!date) {
    return value;
  }
  const parts = wibParts(date);

  return `${parts.day} ${MONTHS[parts.month]} ${parts.year}`;
}

/** e.g. "14 Okt 2026, 09:37 WIB", also used for payment expiry times. */
export function formatDateTime(value?: string | null): string {
  if (!value) {
    return '';
  }
  const date = parseApiDate(value);
  if (!date) {
    return value;
  }
  const parts = wibParts(date);

  return `${parts.day} ${MONTHS[parts.month]} ${parts.year}, ${clock(parts)} WIB`;
}

/**
 * Marketplace-style relative time for lists and notifications: "Baru saja",
 * "5 mnt lalu", "3 jam lalu", "Kemarin, 09:37", "14 Okt, 09:37", "14 Okt 2025".
 * Day boundaries are WIB. `now` is injectable for tests.
 */
export function formatRelative(value?: string | null, now: Date | number = Date.now()): string {
  if (!value) {
    return '';
  }
  const date = parseApiDate(value);
  if (!date) {
    return value;
  }

  const nowMs = typeof now === 'number' ? now : now.getTime();
  const elapsedSeconds = Math.floor((nowMs - date.getTime()) / 1000);
  if (elapsedSeconds < 60) {
    return 'Baru saja';
  }
  if (elapsedSeconds < 3600) {
    return `${Math.floor(elapsedSeconds / 60)} mnt lalu`;
  }
  if (elapsedSeconds < 86400) {
    return `${Math.floor(elapsedSeconds / 3600)} jam lalu`;
  }

  const then = wibParts(date);
  const today = wibParts(new Date(nowMs));
  const dayIndex = (parts: WibParts) => Date.UTC(parts.year, parts.month, parts.day) / DAY_MS;
  if (dayIndex(today) - dayIndex(then) === 1) {
    return `Kemarin, ${clock(then)}`;
  }
  if (then.year === today.year) {
    return `${then.day} ${MONTHS[then.month]}, ${clock(then)}`;
  }

  return `${then.day} ${MONTHS[then.month]} ${then.year}`;
}

export const STATUS_LABELS: Record<string, string> = {
  new: 'Baru',
  pending: 'Menunggu',
  labeled: 'Menunggu penjemputan',
  picked_up: 'Dijemput kurir',
  confirmed: 'Dikonfirmasi',
  processing: 'Diproses',
  completed: 'Selesai',
  paid: 'Dibayar',
  unpaid: 'Belum bayar',
  authorized: 'Terotorisasi',
  shipped: 'Dikirim',
  in_transit: 'Dalam perjalanan',
  delivered: 'Tiba di tujuan',
  cancelled: 'Dibatalkan',
  canceled: 'Dibatalkan',
  failed: 'Gagal',
  refunded: 'Dikembalikan',
  partially_paid: 'Sebagian dibayar',
  voided: 'Dibatalkan',
  returned: 'Dikembalikan',
  unfulfilled: 'Belum diproses',
  fulfilled: 'Sudah diproses',
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

export function stripHtml(value?: string | null): string {
  if (!value) {
    return '';
  }

  return value
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}
