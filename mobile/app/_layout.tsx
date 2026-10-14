import '@/global.css';

import { AuthGate, AuthProvider } from '@/lib/auth';
import { CartProvider } from '@/lib/cart';
import { takePostOnboardingRoute } from '@/lib/first-run';
import { NetworkProvider } from '@/lib/network';
import { NAV_THEME } from '@/lib/theme';
import { PortalHost } from '@rn-primitives/portal';
import { Stack, ThemeProvider, useNavigationContainerRef, useRouter, type Href } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { colorScheme } from 'nativewind';
import { useEffect } from 'react';
import { Platform } from 'react-native';

export { ErrorScreen as ErrorBoundary } from '@/components/error-screen';

// A deep link opened on its own still has Beranda underneath, so back never exits the app.
export const unstable_settings = { anchor: '(tabs)' };

// The app is light-only: pin the scheme so NativeWind, the navigation theme, the keyboard and
// system alerts all agree, whatever the phone is set to.
// Not during web static rendering: NativeWind only accepts it where there is a browser.
if (Platform.OS !== 'web' || typeof document !== 'undefined') {
  colorScheme.set('light');
}

// Hold the native navy splash until the first real frame is ready (AuthGate lifts it). A crash
// or a stuck start must never leave it on top forever, hence the unconditional fallback.
SplashScreen.preventAutoHideAsync().catch(() => {
  // Already prevented, or no native splash (Expo Go).
});
SplashScreen.setOptions({ duration: 300, fade: true });
setTimeout(() => {
  SplashScreen.hideAsync().catch(() => {
    // Already hidden.
  });
}, 4000);

/**
 * "Sudah punya akun? Masuk" on the intro: opens sign-in once the navigator is mounted. The intro
 * finishes before the navigator exists, so this waits (briefly) until the router accepts pushes.
 */
function PostOnboardingRedirect() {
  const router = useRouter();
  const navigationRef = useNavigationContainerRef();

  useEffect(() => {
    const route = takePostOnboardingRoute();
    if (!route) {
      return;
    }

    // No cleanup on purpose: the route is consumed here, so a development double-mount must not
    // cancel the attempt. It gives up after about two seconds.
    let attempts = 0;
    const open = () => {
      if (navigationRef.isReady()) {
        try {
          router.push(route as Href);
        } catch {
          // The intro is over; staying on Beranda is an acceptable fallback.
        }
        return;
      }
      if (attempts++ < 40) {
        setTimeout(open, 50);
      }
    };
    open();
  }, []); // Once: the parked route is consumed on the first run.

  return null;
}

export default function RootLayout() {
  return (
    <ThemeProvider value={NAV_THEME.light}>
      {/* The one status bar: every header is navy, so icons are light. Screens with a white top
          (intro, error screen, image viewers) set their own. */}
      <StatusBar style="light" />
      <NetworkProvider>
        <AuthProvider>
          <AuthGate>
            <CartProvider>
              <Stack screenOptions={{ headerShown: false, headerBackTitle: 'Kembali' }}>
                <Stack.Screen name="(tabs)" />
                <Stack.Screen name="login" />
                <Stack.Screen name="register" />
                <Stack.Screen name="forgot-password" />
                <Stack.Screen name="shop" />
                <Stack.Screen name="promo" />
                <Stack.Screen name="featured" />
                <Stack.Screen name="category/[slug]" />
                <Stack.Screen name="collection/[slug]" />
                <Stack.Screen name="product/[slug]" />
                <Stack.Screen name="keranjang" />
                <Stack.Screen name="checkout" />
                <Stack.Screen name="order-placed" />
                <Stack.Screen name="orders" />
                <Stack.Screen name="order/[number]" />
                <Stack.Screen name="addresses" />
                <Stack.Screen name="notifications" />
                <Stack.Screen name="profile" />
                <Stack.Screen name="profile-edit" />
                <Stack.Screen name="change-password" />
                <Stack.Screen name="about" />
                <Stack.Screen name="legal/[slug]" />
              </Stack>
              <PostOnboardingRedirect />
              <PortalHost />
            </CartProvider>
          </AuthGate>
        </AuthProvider>
      </NetworkProvider>
    </ThemeProvider>
  );
}
