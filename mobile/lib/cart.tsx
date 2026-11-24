import { api, ApiError, SESSION_EXPIRED_MESSAGE, type Cart } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useNetwork } from '@/lib/network';
import { toast } from '@/lib/toast';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

type CartContextValue = {
  cart: Cart | null;
  itemCount: number;
  /** Re-reads the cart; rejects on failure. Dropped if a newer read or write starts meanwhile. */
  refresh: () => Promise<void>;
  /** Sets the cart a write (or checkout) answered with. Always wins over reads still in flight. */
  applyCart: (next: Cart | null) => void;
};

const CartContext = createContext<CartContextValue | null>(null);

export function cartItemCount(cart: Cart | null | undefined): number {
  return (cart?.lines ?? []).reduce((sum, line) => sum + line.quantity, 0);
}

export function CartProvider({ children }: { children: ReactNode }) {
  const { ready, user } = useAuth();
  const { online } = useNetwork();
  const [cart, setCart] = useState<Cart | null>(null);
  // Bumped by every read and every write. A read only lands while it is still the latest, so a
  // slow GET /cart that answers after an add-to-cart (or a line edit, or checkout's clear) cannot
  // put the older cart, and the older badge count, back.
  const versionRef = useRef(0);
  // The latest GET /cart, so a superseded refresh() can wait for the read that replaced it.
  const readRef = useRef<Promise<void> | null>(null);

  const applyCart = useCallback((next: Cart | null) => {
    versionRef.current += 1;
    setCart(next);
  }, []);

  const refresh = useCallback(async () => {
    const version = ++versionRef.current;
    const read = api<{ data: Cart }>('/cart').then((res) => {
      if (versionRef.current === version) {
        setCart(res.data);
      }
    });
    readRef.current = read;
    try {
      await read;
    } catch (e) {
      // A newer read supersedes this one's failure; only the latest read reports.
      if (readRef.current === read) {
        throw e;
      }
    }
    // A newer read started meanwhile: settle with it, so a first load does not end before any
    // cart has landed (which would flash the empty state).
    if (readRef.current !== read) {
      await readRef.current;
    }
  }, []);

  useEffect(() => {
    if (!ready || !online) {
      return;
    }
    refresh().catch((e: unknown) => {
      // Keep the last known cart so a blip does not look like "empty". A 401 means the session
      // ended elsewhere: lib/auth signs out (and reloads the guest cart), so say why.
      if (e instanceof ApiError && e.status === 401) {
        toast.show({ tone: 'error', message: SESSION_EXPIRED_MESSAGE });
      }
    });
  }, [ready, user?.id, refresh, online]);

  const value = useMemo<CartContextValue>(
    () => ({
      cart,
      itemCount: cartItemCount(cart),
      refresh,
      applyCart,
    }),
    [cart, refresh, applyCart]
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartContextValue {
  const ctx = useContext(CartContext);
  if (!ctx) {
    throw new Error('useCart must be used within CartProvider');
  }
  return ctx;
}
