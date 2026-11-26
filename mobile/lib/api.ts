import { API_URL } from '@/lib/config';
import {
  fetchWithTimeout,
  isNetworkFailure,
  NetworkError,
  OFFLINE_COPY,
  reportNetworkFailure,
  reportNetworkSuccess,
  TimeoutError,
} from '@/lib/network';
import { getSecureItem, setSecureItem } from '@/lib/storage';

let authToken: string | null = null;
let guestToken: string | null = null;
let guestTokenPromise: Promise<string> | null = null;
let serverClockOffsetMs = 0;

export function syncServerClock(serverIso?: string | null): void {
  if (!serverIso) {
    return;
  }
  const parsed = Date.parse(serverIso);
  if (!Number.isNaN(parsed)) {
    serverClockOffsetMs = parsed - Date.now();
  }
}

export function serverNow(): number {
  return Date.now() + serverClockOffsetMs;
}

/** SecureStore key of the guest cart token. Also cleared by lib/auth.tsx on a fresh install. */
export const GUEST_CART_KEY = 'oceanmall.cart-token';

let unauthorizedHandler: (() => void) | null = null;

export const SESSION_EXPIRED_MESSAGE = 'Sesi kamu sudah habis. Masuk lagi, ya.';

export function setAuthToken(token: string | null): void {
  authToken = token;
}

/** Called when a signed-in request gets 401 (token revoked, e.g. login on another device). */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  unauthorizedHandler = handler;
}

function newUuid(): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return globalThis.crypto.randomUUID();
  }

  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

export async function ensureGuestToken(): Promise<string> {
  if (guestToken) {
    return guestToken;
  }
  if (!guestTokenPromise) {
    guestTokenPromise = (async () => {
      const stored = await getSecureItem(GUEST_CART_KEY);
      const valid =
        typeof stored === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(stored);
      const token = valid ? stored : newUuid();
      if (token !== stored) {
        await setSecureItem(GUEST_CART_KEY, token);
      }
      guestToken = token;
      return token;
    })();
  }

  return guestTokenPromise;
}

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public body: unknown,
    /** Seconds to wait, from the Retry-After header (429 and 503 responses). */
    public retryAfter?: number
  ) {
    super(message);
  }
}

type ErrorBody = {
  message?: unknown;
  errors?: Record<string, string[] | string>;
};

const DEFAULT_ERROR_MESSAGE = 'Ada yang tidak beres. Coba lagi, ya.';
const TIMEOUT_MESSAGE = 'Koneksi lambat. Coba lagi, ya.';
const SERVER_ERROR_MESSAGE = 'Lagi ada gangguan di sistem kami. Coba lagi beberapa saat, ya.';
const MAINTENANCE_MESSAGE = 'OceanMall sedang pemeliharaan sebentar. Coba lagi nanti.';

/** Untranslated messages that Laravel and the HTTP stack produce on their own. */
const FRAMEWORK_COPY =
  /^(too many attempts\.|server error|unauthenticated\.|this action is unauthorized\.|no query results for model|not found|service unavailable|forbidden)/i;

/**
 * Validation messages come back as ":Attribute wajib diisi." with the English
 * field name ("First name wajib diisi."). Until the backend ships an `attributes`
 * lang array, swap the known names for their Indonesian labels here. Longest
 * phrases first, so "password confirmation" wins over "password".
 */
const ATTRIBUTE_LABELS: [RegExp, string][] = (
  [
    ['password confirmation', 'konfirmasi kata sandi'],
    ['current password', 'kata sandi saat ini'],
    ['first name', 'nama depan'],
    ['last name', 'nama belakang'],
    ['phone number', 'nomor HP'],
    ['street address', 'alamat lengkap'],
    ['postal code', 'kode pos'],
    ['password', 'kata sandi'],
    ['city', 'kota'],
    ['state', 'provinsi'],
  ] as const
).map(([english, indonesian]): [RegExp, string] => [
  new RegExp(`\\b${english}\\b`, 'gi'),
  indonesian,
]);

function localizeAttributes(message: string): string {
  let result = message;
  for (const [pattern, label] of ATTRIBUTE_LABELS) {
    result = result.replace(pattern, label);
  }
  if (result === message) {
    return message;
  }

  return result.charAt(0).toUpperCase() + result.slice(1);
}

function retryDelayMessage(seconds: number): string {
  const wait = Math.max(1, Math.ceil(seconds));
  if (wait > 90) {
    return `Terlalu banyak percobaan. Coba lagi dalam ${Math.ceil(wait / 60)} menit.`;
  }

  return `Terlalu banyak percobaan. Coba lagi dalam ${wait} detik.`;
}

/**
 * Turns anything a request can throw into a sentence a shopper can act on.
 * Never leaks framework copy ("Server Error", "No query results for model ...",
 * "HTTP 404") or a raw JS error; `fallback` is what a screen says for "this did
 * not work" in its own words (e.g. 'Produk tidak ditemukan' for a 404).
 */
export function errorMessage(error: unknown, fallback = DEFAULT_ERROR_MESSAGE): string {
  // A slow answer is not an offline device: check timeouts before the offline branch.
  if (
    error instanceof TimeoutError ||
    (error instanceof ApiError && (error.status === 408 || error.status === 504))
  ) {
    return TIMEOUT_MESSAGE;
  }

  if (isNetworkFailure(error)) {
    return OFFLINE_COPY.message;
  }

  if (error instanceof ApiError) {
    const body = (error.body ?? {}) as ErrorBody;

    if (body.errors && typeof body.errors === 'object') {
      const first = Object.values(body.errors)[0];
      if (Array.isArray(first) && typeof first[0] === 'string' && first[0] !== '') {
        return localizeAttributes(first[0]);
      }
      if (typeof first === 'string' && first !== '') {
        return localizeAttributes(first);
      }
    }

    const message = typeof body.message === 'string' ? body.message.trim() : '';
    if (message !== '' && !FRAMEWORK_COPY.test(message)) {
      return message;
    }

    switch (true) {
      case error.status === 401:
        // The session-expired copy is a custom body message and returned above.
        return 'Masuk dulu untuk melanjutkan.';
      case error.status === 403:
        return 'Kamu tidak punya akses ke halaman ini.';
      case error.status === 404:
        return fallback;
      case error.status === 429:
        return error.retryAfter !== undefined
          ? retryDelayMessage(error.retryAfter)
          : 'Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.';
      case error.status === 503 && /^service unavailable/i.test(message):
        return MAINTENANCE_MESSAGE;
      case error.status >= 500:
        return SERVER_ERROR_MESSAGE;
      default:
        return fallback;
    }
  }

  // A raw JS error (a JSON parse failure, a bug) is never shown to a shopper.
  return fallback;
}

/**
 * All validation messages per field from a 422 response, for inline errors under
 * each input. Empty when the error carries none.
 */
export function apiFieldErrors(error: unknown): Record<string, string[]> {
  if (!(error instanceof ApiError)) {
    return {};
  }

  const errors = (error.body as ErrorBody | null | undefined)?.errors;
  if (!errors || typeof errors !== 'object') {
    return {};
  }

  const result: Record<string, string[]> = {};
  for (const [field, value] of Object.entries(errors)) {
    const messages = (Array.isArray(value) ? value : [value]).filter(
      (message): message is string => typeof message === 'string' && message !== ''
    );
    if (messages.length > 0) {
      result[field] = messages.map(localizeAttributes);
    }
  }

  return result;
}

function parseRetryAfter(value: string | null): number | undefined {
  if (!value) {
    return undefined;
  }
  const seconds = Number(value);
  if (Number.isFinite(seconds)) {
    return Math.max(0, Math.ceil(seconds));
  }
  const at = Date.parse(value);
  if (!Number.isNaN(at)) {
    return Math.max(0, Math.ceil((at - Date.now()) / 1000));
  }

  return undefined;
}

/**
 * Longer limit for the calls that wait on an outside provider (courier quotes, the payment
 * gateway). The default of 8 s suits the catalog and cart, but a place-order that is still
 * creating the payment would be aborted client-side after the order already exists. Stays above
 * the server's own 30 s execution limit (config/octane.php), so the server gives up first.
 */
export const PROVIDER_TIMEOUT_MS = 45_000;

export type ApiInit = RequestInit & {
  /** Overrides the default request time limit (see fetchWithTimeout). */
  timeoutMs?: number;
};

export async function api<T>(path: string, { timeoutMs, ...init }: ApiInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  const isFormData = typeof FormData !== 'undefined' && init.body instanceof FormData;
  if (!headers.has('Content-Type') && init.body && !isFormData) {
    headers.set('Content-Type', 'application/json');
  }
  if (authToken) {
    headers.set('Authorization', `Bearer ${authToken}`);
  }
  headers.set('X-Cart-Token', await ensureGuestToken());

  let response: Response;
  try {
    response = await fetchWithTimeout(`${API_URL}${path}`, { ...init, headers }, timeoutMs);
    syncServerClock(response.headers.get('Date'));
    reportNetworkSuccess();
  } catch (error) {
    // A timeout means a slow server, not a lost connection: leave the offline state alone.
    if (error instanceof TimeoutError) {
      throw error;
    }
    reportNetworkFailure();
    throw error instanceof NetworkError ? error : new NetworkError();
  }

  const json = (await response.json().catch(() => ({}))) as {
    message?: string;
    data?: unknown;
  };

  if (!response.ok) {
    if (response.status === 401 && headers.has('Authorization')) {
      unauthorizedHandler?.();
      throw new ApiError(SESSION_EXPIRED_MESSAGE, 401, { message: SESSION_EXPIRED_MESSAGE });
    }
    throw new ApiError(
      json.message ?? '',
      response.status,
      json,
      parseRetryAfter(response.headers.get('Retry-After'))
    );
  }

  return json as T;
}

export type User = {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone_number?: string | null;
  email_verified_at?: string | null;
};

export async function resendVerificationEmail(): Promise<{ message: string }> {
  return api<{ message: string }>('/auth/email/verification-notification', {
    method: 'POST',
  });
}

export type ProductReview = {
  id: number;
  rating: number;
  title?: string | null;
  content?: string | null;
  is_recommended?: boolean;
  created_at?: string | null;
  author_name?: string;
};

export type VariantOptionsPayload = {
  productOptions: {
    id: number;
    name: string;
    slug: string;
    type: string;
    values: { id: number; value: string; key?: string | null }[];
  }[];
  variantIndex: Record<string, number>;
  variantMap: Record<
    number,
    { id: number; values: number[]; stock: number; allow_backorder: boolean }
  >;
  availabilityMatrix: Record<number, Record<number, boolean>>;
  hasStructuredAttributes: boolean;
};

export type Product = {
  id: number;
  name: string;
  slug: string;
  thumbnail?: string | null;
  images?: { id?: number; url?: string }[];
  brand?: { id: number; name: string; slug: string } | null;
  price?: number | null;
  compare_price?: number | null;
  currency?: string;
  description?: string | null;
  allow_backorder?: boolean;
  options?: VariantOptionsPayload | null;
  variants?: {
    id: number;
    sku?: string | null;
    name?: string | null;
    price?: number | null;
    compare_price?: number | null;
    /** null = stock not tracked */
    stock?: number | null;
    allow_backorder?: boolean;
    /** The variant's own photo URL; null/absent = none, keep the product gallery. */
    thumbnail?: string | null;
  }[];
  /** Product detail only; null for variant products or untracked stock. */
  stock?: number | null;
  /** Social proof; rendered only when the backend sends it. */
  rating_average?: number | null;
  rating_count?: number;
  sold_count?: number;
  reviews?: {
    average: number;
    count: number;
    items: ProductReview[];
  };
  related_products?: Product[];
};

export type Collection = {
  id: number;
  name: string;
  slug: string;
  thumbnail?: string | null;
  products_count?: number;
};

export type Category = {
  id: number;
  name: string;
  slug: string;
  thumbnail?: string | null;
};

export type Brand = {
  id: number;
  name: string;
  slug: string;
  thumbnail?: string | null;
  products_count?: number;
};

export type HomePayload = {
  featured_products: Product[];
  promo_products: Product[];
  featured_collections: Collection[];
  collections: Collection[]; // legacy alias
  categories: Category[];
  brands: Brand[];
  currency: string;
};

/**
 * Reports a published review as inappropriate (App Store 1.2, Play UGC policy). The server puts
 * it back to pending, so it leaves the public list until a moderator looks at it.
 */
export async function reportReview(reviewId: number, reason?: string): Promise<void> {
  await api(`/catalog/reviews/${reviewId}/report`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  });
}

export async function fetchHome(): Promise<HomePayload> {
  const res = await api<{ data: HomePayload }>('/catalog/home');
  return res.data;
}

export type Cart = {
  id: number | null;
  currency: string;
  coupon_code?: string | null;
  lines: {
    id: number;
    quantity: number;
    unit_price: number;
    name: string;
    variant?: string | null;
    slug?: string | null;
    thumbnail?: string | null;
    purchasable_id: number;
  }[];
  totals: {
    subtotal: number;
    discount: number;
    tax: number;
    tax_inclusive?: boolean;
    total: number;
  };
};

export type PaymentInstructions = {
  payment_id?: string;
  payment_type?: string;
  virtual_account_number?: string | null;
  bank_code?: string | null;
  qris_string?: string | null;
  payment_url?: string | null;
  amount?: number;
  expiry_date?: string | null;
};

/**
 * How to pay an order, from the order's own payment driver (API `payment_action`).
 * instructions / qr: `data` is the VA or QR payload (same shape as PaymentInstructions);
 * redirect: `data.url` is the payment page; none: nothing to pay online (manual / COD),
 * `data` carries the method's title, description and instructions.
 */
export type PaymentAction = {
  driver: string;
  kind: 'instructions' | 'redirect' | 'client_secret' | 'qr' | 'none';
  data: Record<string, unknown>;
};

type PaymentSource = {
  payment?: PaymentInstructions | null;
  payment_action?: PaymentAction | null;
};

/**
 * The instructions the payment panel renders. Reads the neutral `payment_action` and falls back
 * to the legacy `payment` field for API versions that predate it.
 */
export function paymentFromAction(
  source: PaymentSource,
  fallbackAmount?: number
): PaymentInstructions | null {
  const action = source.payment_action;

  if (action === undefined) {
    return source.payment ?? null;
  }

  if (action === null) {
    return null;
  }

  if (action.kind === 'instructions' || action.kind === 'qr') {
    return action.data as PaymentInstructions;
  }

  if (action.kind === 'redirect' && typeof action.data.url === 'string') {
    return {
      payment_url: action.data.url,
      expiry_date: typeof action.data.expiry_date === 'string' ? action.data.expiry_date : null,
      amount: typeof action.data.amount === 'number' ? action.data.amount : fallbackAmount,
      bank_code: typeof action.data.channel_code === 'string' ? action.data.channel_code : null,
      payment_id: typeof action.data.payment_id === 'string' ? action.data.payment_id : undefined,
    };
  }

  return null;
}

/** Manual / cash-on-delivery: nothing to pay online, so no instructions or retry to offer. */
export function isOfflinePayment(source: PaymentSource): boolean {
  return source.payment_action?.kind === 'none';
}

/** The method's own note for an offline payment (instructions, else description). */
export function offlinePaymentNote(source: PaymentSource): string | null {
  if (!isOfflinePayment(source)) {
    return null;
  }

  const { instructions, description } = source.payment_action?.data ?? {};

  if (typeof instructions === 'string' && instructions.trim() !== '') {
    return instructions.trim();
  }

  return typeof description === 'string' && description.trim() !== '' ? description.trim() : null;
}

export type ShippingRate = {
  service_code: string | number;
  service_name: string;
  amount: number;
  currency?: string;
  carrier_code?: string;
  carrier_name?: string | null;
  carrier_logo?: string | null;
  description?: string | null;
  estimated_days?: string | number | null;
};

export type PaymentMethodOption = {
  id: number;
  title: string;
  slug?: string;
  driver: string;
  description?: string | null;
  payment_type?: string | null;
  channel_code?: string | null;
  min_amount?: number | null;
  max_amount?: number | null;
  logo?: string | null;
};

export type SavedAddress = {
  id: number;
  first_name: string;
  last_name: string;
  street_address: string;
  city: string;
  postal_code: string;
  state?: string | null;
  phone_number?: string | null;
  /** Provider-neutral destination (preferred). */
  destination_id?: string | null;
  destination_label?: string | null;
  destination_driver?: string | null;
  pin_point?: string | null;
  /** @deprecated Legacy aliases still emitted by the API for older builds. */
  rajaongkir_destination_id?: string | null;
  /** @deprecated Legacy alias of destination_label. */
  rajaongkir_destination_label?: string | null;
  shipping_default?: boolean;
};

/** What the configured shipping drivers need before an address is complete. */
export type ShippingCapabilities = {
  destination_search: boolean;
  requires_destination: boolean;
  requires_coordinates: boolean;
};

/**
 * Destination search row. The neutral region keys can be null when the shipping
 * provider does not know them: the form then lets the customer type that field.
 */
export type Destination = {
  id: string;
  label: string;
  postal_code?: string | null;
  city?: string | null;
  state?: string | null;
  district?: string | null;
  /** @deprecated Legacy RajaOngkir names; read only as a fallback for older API versions. */
  province_name?: string | null;
  /** @deprecated See province_name. */
  city_name?: string | null;
  /** @deprecated See province_name. */
  zip_code?: string | null;
};

/** Region fields a destination row provides (neutral keys first, legacy names as fallback). */
export function destinationRegion(row: Destination): {
  postal_code: string | null;
  city: string | null;
  state: string | null;
} {
  const pick = (...values: (string | null | undefined)[]) =>
    values.map((value) => (value ?? '').trim()).find((value) => value !== '') ?? null;
  return {
    postal_code: pick(row.postal_code, row.zip_code),
    city: pick(row.city, row.city_name),
    state: pick(row.state, row.province_name),
  };
}

export type CheckoutAllocationItem = {
  inventory_id: number;
  inventory_name: string;
  lines: { purchasable_type: string; purchasable_id: number; qty: number }[];
};

export type CheckoutPayload = {
  cart: Cart;
  shipping_address: {
    first_name?: string;
    last_name?: string;
    street_address?: string;
    street_address_plus?: string | null;
    city?: string;
    state?: string | null;
    postal_code?: string;
    phone_number?: string;
    destination_id?: string | null;
    destination_label?: string | null;
    destination_driver?: string | null;
    pin_point?: string | null;
    /** @deprecated Legacy alias of destination_id. */
    rajaongkir_destination_id?: string;
    /** @deprecated Legacy alias of destination_label. */
    rajaongkir_destination_label?: string;
  } | null;
  shipping_option: {
    service_code?: string | number;
    service_name?: string;
    amount?: number;
    carrier_name?: string | null;
  } | null;
  shipping_rates: ShippingRate[];
  allocation?: CheckoutAllocationItem[] | null;
  shipping_rates_by_shipment?: Record<string, ShippingRate[]>;
  shipping_options_by_shipment?: Record<string, string | null>;
  /** Absent on API versions older than the provider-neutral checkout. */
  shipping_capabilities?: ShippingCapabilities;
  payment_methods: PaymentMethodOption[];
  saved_addresses: SavedAddress[];
};

export type OrderSummary = {
  id: number;
  number: string;
  status: string;
  payment_status: string;
  shipping_status?: string;
  amount: number;
  currency?: string;
  created_at?: string | null;
  item_count?: number;
  line_count?: number;
  first_item?: { name: string; quantity: number; thumbnail?: string | null } | null;
  payment_method?: string | null;
  payment_driver?: string | null;
};

export type AppNotification = {
  id: string;
  title: string;
  body: string;
  url?: string;
  order_number?: string | null;
  type?: string | null;
  read_at?: string | null;
  created_at?: string | null;
};

export type OrderDetail = OrderSummary & {
  server_time?: string;
  items: {
    id?: number;
    name: string;
    sku?: string | null;
    quantity: number;
    unit_price: number;
    thumbnail?: string | null;
    slug?: string | null;
    reviewed?: boolean;
  }[];
  shipping_address?: {
    name: string;
    phone?: string | null;
    street_address: string;
    area?: string | null;
  } | null;
  totals?: { subtotal: number; shipping: number; adjustment: number; total: number };
  breakdown?: {
    subtotal: number;
    discount: number;
    tax: number;
    tax_inclusive: boolean;
    shipping: number;
    refunded: number;
    total: number;
  };
  shipments: {
    id: number;
    status: string;
    awb?: string | null;
    tracking_number?: string | null;
    carrier?: string | null;
    service?: string | null;
    cost?: number | null;
    carrier_logo?: string | null;
    can_track?: boolean;
    tracking_history?: {
      description?: string;
      datetime?: string | null;
      location?: string | null;
      /** @deprecated Alias of datetime. */
      date?: string | null;
    }[];
  }[];
  /** Legacy instruction payload; prefer payment_action (see paymentFromAction). */
  payment?: PaymentInstructions | null;
  payment_action?: PaymentAction | null;
  can_retry_payment?: boolean;
  can_cancel?: boolean;
  can_request_return?: boolean;
  can_upload_payment_proof?: boolean;
  invoice_number?: string | null;
  invoice_url?: string | null;
  is_cod?: boolean;
  payment_proofs?: {
    id: number;
    status: string;
    original_name: string;
    sender_name?: string | null;
    sender_bank?: string | null;
    transfer_amount?: number | null;
    verification_notes?: string | null;
    created_at?: string | null;
  }[];
  return_requests?: {
    id: number;
    status: string;
    reason: string;
    description?: string | null;
    resolution_notes?: string | null;
    created_at?: string | null;
  }[];
};
