import { api, type Cart } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useNetwork } from '@/lib/network';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

type CartContextValue = {
  cart: Cart | null;
  itemCount: number;
  refresh: () => Promise<void>;
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

  const refresh = useCallback(async () => {
    try {
      const res = await api<{ data: Cart }>('/cart');
      setCart(res.data);
    } catch {
      // Keep the last known cart so a blip does not look like "empty".
    }
  }, []);

  useEffect(() => {
    if (!ready || !online) {
      return;
    }
    void refresh();
  }, [ready, user?.id, refresh, online]);

  const value = useMemo<CartContextValue>(
    () => ({
      cart,
      itemCount: cartItemCount(cart),
      refresh,
      applyCart: setCart,
    }),
    [cart, refresh]
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
