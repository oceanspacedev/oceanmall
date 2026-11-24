import type { Href, useRouter } from 'expo-router';

/** Where sign-in and sign-up land when no `redirect` is given: the Akun tab. */
export const DEFAULT_AUTH_REDIRECT = '/(tabs)/account';

export function safeInternalPath(value: unknown, fallback: string): string {
  if (typeof value !== 'string') {
    return fallback;
  }
  const path = value.trim();
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('://')) {
    return fallback;
  }

  return path;
}

type AuthContext = { title: string; subtitle: string };

/**
 * Title and one-line subtitle for the sign-in and sign-up screens, so a customer who was sent
 * here from checkout (or orders, notifications...) knows why and what happens next.
 */
export function authContext(
  redirect: string | null | undefined,
  kind: 'login' | 'register'
): AuthContext {
  const path = redirect ?? '';

  if (kind === 'register') {
    return {
      title: path.startsWith('/checkout') ? 'Daftar untuk lanjut checkout' : 'Buat akun baru',
      subtitle: 'Gratis, cuma butuh 1 menit.',
    };
  }

  if (path.startsWith('/checkout')) {
    return { title: 'Masuk untuk lanjut checkout', subtitle: 'Keranjangmu tetap tersimpan.' };
  }
  if (path.startsWith('/orders') || path.startsWith('/order/')) {
    return { title: 'Masuk untuk melihat pesanan', subtitle: 'Pantau status dan resi pesananmu.' };
  }
  if (path.startsWith('/notifications')) {
    return { title: 'Masuk untuk melihat notifikasi', subtitle: 'Info pesanan dan pembayaran.' };
  }
  if (path.startsWith('/profile')) {
    return { title: 'Masuk untuk melihat profil', subtitle: 'Kelola data diri dan keamanan.' };
  }
  if (path.startsWith('/addresses')) {
    return {
      title: 'Masuk untuk mengelola alamat',
      subtitle: 'Alamat tersimpan, checkout lebih cepat.',
    };
  }

  return { title: 'Masuk ke akunmu', subtitle: 'Senang kamu kembali!' };
}

/** The slice of the auth screen's `useNavigation()` that `leaveAuth` reads: the root stack. */
type AuthNavigation = {
  getState(): { index: number; routes: readonly { name: string; params?: object }[] } | undefined;
};

/** The pathname of a root-stack route: 'order/[number]' with { number: 'ORD-1' } -> '/order/ORD-1'. */
function routePathname(route: { name: string; params?: object }): string {
  const params = (route.params ?? {}) as Record<string, unknown>;
  const segments = route.name.split('/').map((segment) => {
    const dynamic = /^\[(?:\.\.\.)?(.+)\]$/.exec(segment);
    if (!dynamic) {
      return segment;
    }
    const value = params[dynamic[1]];

    return Array.isArray(value) ? value.join('/') : String(value ?? '');
  });

  return `/${segments.filter((segment) => segment !== 'index').join('/')}`;
}

/** True when the screen right under the auth screen is `next` (query string aside). */
function returnsToScreenBelow(navigation: AuthNavigation | undefined, next: string): boolean {
  const state = navigation?.getState();
  const below = state && state.index > 0 ? state.routes[state.index - 1] : undefined;
  if (!below) {
    return false;
  }
  const pathname = next.split(/[?#]/)[0];
  try {
    return routePathname(below) === decodeURI(pathname);
  } catch {
    return routePathname(below) === pathname;
  }
}

/**
 * Leaves the sign-in or sign-up screen for `next`.
 *
 * A tab destination (the default, Akun) pops back to the tab navigator that is already under
 * the auth screen: `replace` would push a second `(tabs)` onto the root stack, and the Android
 * back button would then need two presses to exit. The same goes for a screen that sent the
 * customer here to come back to it (a LoginPrompt on Pesanan, "Laporkan" on a product): it is
 * right under the auth screen, so pop back to it (it re-renders signed in, and a new query such
 * as `?status=` is applied) instead of stacking a second copy. Everything else (checkout from
 * the cart, ...) replaces the auth screen, so Back from there does not return to the form.
 *
 * `navigation` is the auth screen's own `useNavigation()`; only the route right under it is
 * matched, because `dismissTo` alone would pop to any older screen with the same route name
 * (another product or order).
 */
export function leaveAuth(
  router: ReturnType<typeof useRouter>,
  next: string,
  navigation?: AuthNavigation
): void {
  if (next.startsWith('/(tabs)') || returnsToScreenBelow(navigation, next)) {
    router.dismissTo(next as Href);
    return;
  }

  router.replace(next as Href);
}
