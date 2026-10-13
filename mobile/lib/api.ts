import { API_URL } from '@/lib/config';
import {
  fetchWithTimeout,
  isNetworkFailure,
  NetworkError,
  OFFLINE_COPY,
  reportNetworkFailure,
  reportNetworkSuccess,
} from '@/lib/network';
import { getSecureItem, setSecureItem } from '@/lib/storage';

let authToken: string | null = null;
let guestToken: string | null = null;
let guestTokenPromise: Promise<string> | null = null;

const GUEST_CART_KEY = 'oceanmall.cart-token';

let unauthorizedHandler: (() => void) | null = null;

export const SESSION_EXPIRED_MESSAGE = 'Sesi kamu berakhir. Silakan masuk lagi.';

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
    public body: unknown
  ) {
    super(message);
  }
}

export function errorMessage(error: unknown, fallback = 'Terjadi kesalahan.'): string {
  if (isNetworkFailure(error)) {
    return OFFLINE_COPY.message;
  }

  if (error instanceof ApiError) {
    const body = error.body as { message?: string; errors?: Record<string, string[] | string> };
    if (body?.errors) {
      const first = Object.values(body.errors)[0];
      if (Array.isArray(first) && first[0]) {
        return first[0];
      }
      if (typeof first === 'string' && first !== '') {
        return first;
      }
    }
    return body?.message ?? error.message;
  }

  return error instanceof Error ? error.message : fallback;
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (!headers.has('Content-Type') && init.body) {
    headers.set('Content-Type', 'application/json');
  }
  if (authToken) {
    headers.set('Authorization', `Bearer ${authToken}`);
  }
  headers.set('X-Cart-Token', await ensureGuestToken());

  let response: Response;
  try {
    response = await fetchWithTimeout(`${API_URL}${path}`, { ...init, headers });
    reportNetworkSuccess();
  } catch (error) {
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
    throw new ApiError(json.message ?? `HTTP ${response.status}`, response.status, json);
  }

  return json as T;
}

export type User = {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone_number?: string | null;
};

export type ProductReview = {
  id: number;
  rating: number;
  title?: string | null;
  content?: string | null;
  is_recommended?: boolean;
  created_at?: string | null;
  author_name?: string;
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
  variants?: {
    id: number;
    sku?: string | null;
    name?: string | null;
    price?: number | null;
    compare_price?: number | null;
    /** null = stock not tracked */
    stock?: number | null;
  }[];
  /** Product detail only; null for variant products or untracked stock. */
  stock?: number | null;
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

export async function fetchFeatured(): Promise<Product[]> {
  const res = await api<{ data: Product[] }>('/catalog/featured');
  return res.data ?? [];
}
export async function fetchPromo(): Promise<Product[]> {
  const res = await api<{ data: Product[] }>('/catalog/promo');
  return res.data ?? [];
}
export async function fetchCategories(): Promise<Category[]> {
  const res = await api<{ data: { categories: Category[] } }>('/catalog/categories');
  return res.data.categories ?? [];
}
export async function fetchBrands(): Promise<Brand[]> {
  const res = await api<{ data: Brand[] }>('/catalog/brands');
  return res.data ?? [];
}
export async function fetchCollections(): Promise<Collection[]> {
  const res = await api<{ data: Collection[] }>('/catalog/collections');
  return res.data ?? [];
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
  totals: { subtotal: number; discount: number; tax: number; total: number };
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

export type ShippingRate = {
  service_code: string;
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
  rajaongkir_destination_id?: string | null;
  rajaongkir_destination_label?: string | null;
  shipping_default?: boolean;
};

export type Destination = {
  id: string;
  label: string;
  province_name?: string | null;
  city_name?: string | null;
  zip_code?: string | null;
};

export type CheckoutPayload = {
  cart: Cart;
  shipping_address: {
    first_name?: string;
    last_name?: string;
    street_address?: string;
    city?: string;
    postal_code?: string;
    phone_number?: string;
    rajaongkir_destination_id?: string;
    rajaongkir_destination_label?: string;
  } | null;
  shipping_option: {
    service_code?: string;
    service_name?: string;
    amount?: number;
    carrier_name?: string | null;
  } | null;
  shipping_rates: ShippingRate[];
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
  items: {
    name: string;
    sku?: string | null;
    quantity: number;
    unit_price: number;
    thumbnail?: string | null;
    slug?: string | null;
  }[];
  shipping_address?: {
    name: string;
    phone?: string | null;
    street_address: string;
    area?: string | null;
  } | null;
  totals?: { subtotal: number; shipping: number; adjustment: number; total: number };
  shipments: {
    id: number;
    status: string;
    awb?: string | null;
    tracking_number?: string | null;
    carrier?: string | null;
    service?: string | null;
    cost?: number | null;
    carrier_logo?: string | null;
    tracking_history?: { description?: string; date?: string }[];
  }[];
  payment?: PaymentInstructions | null;
  can_retry_payment?: boolean;
};
