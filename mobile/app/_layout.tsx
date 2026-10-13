import '@/global.css';

import { AuthGate, AuthProvider } from '@/lib/auth';
import { CartProvider } from '@/lib/cart';
import { NetworkProvider } from '@/lib/network';
import { NAV_THEME } from '@/lib/theme';
import { PortalHost } from '@rn-primitives/portal';
import { Stack, ThemeProvider } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useColorScheme } from 'nativewind';

export { ErrorBoundary } from 'expo-router';

export default function RootLayout() {
  const { colorScheme } = useColorScheme();

  return (
    <ThemeProvider value={NAV_THEME[colorScheme ?? 'light']}>
      <NetworkProvider>
        <AuthProvider>
          <AuthGate>
            <CartProvider>
              <StatusBar style={colorScheme === 'dark' ? 'light' : 'dark'} />
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
                <Stack.Screen name="checkout" />
                <Stack.Screen name="orders" />
                <Stack.Screen name="order/[number]" />
                <Stack.Screen name="addresses" />
                <Stack.Screen name="notifications" />
                <Stack.Screen name="profile" />
                <Stack.Screen name="profile-edit" />
                <Stack.Screen name="change-password" />
              </Stack>
              <PortalHost />
            </CartProvider>
          </AuthGate>
        </AuthProvider>
      </NetworkProvider>
    </ThemeProvider>
  );
}
