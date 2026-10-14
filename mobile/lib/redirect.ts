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

/**
 * Leaves the sign-in or sign-up screen for `next`.
 *
 * A tab destination (the default, Akun) pops back to the tab navigator that is already under
 * the auth screen: `replace` would push a second `(tabs)` onto the root stack, and the Android
 * back button would then need two presses to exit. Everything else (checkout, an order, ...)
 * replaces the auth screen, so Back from there does not return to the form.
 */
export function leaveAuth(router: ReturnType<typeof useRouter>, next: string): void {
  if (next.startsWith('/(tabs)')) {
    router.dismissTo(next as Href);
    return;
  }

  router.replace(next as Href);
}
