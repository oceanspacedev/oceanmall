import { BrandLogo } from '@/components/brand-logo';
import { CartBadge } from '@/components/cart-badge';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useAuth } from '@/lib/auth';
import { useCart } from '@/lib/cart';
import { useUnreadCount } from '@/lib/notifications';
import { COLORS } from '@/lib/theme';
import { useFocusEffect, useRouter } from 'expo-router';
import { Bell, Search, ShoppingCart } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Pressable, View } from 'react-native';
import Animated, { FadeIn, FadeOut, useReducedMotion } from 'react-native-reanimated';

const DEFAULT_HINTS = ['Cari di OceanMall', 'Cari produk, merek, atau kategori'];
const HINT_INTERVAL_MS = 3000;
const ICON_RIPPLE = { color: 'rgba(255,255,255,0.25)', borderless: true, radius: 22 } as const;

/**
 * The navy band at the top of Beranda: logo, a search pill with a rotating hint, the bell and the
 * cart. The screen supplies the SafeAreaView (edges top, bg-om-navy). The band is a fixed 56px so
 * the loading skeleton and the loaded screen never jump.
 */
export function HomeHeader({ hints }: { hints?: string[] }) {
  const router = useRouter();
  const { user } = useAuth();
  const { itemCount } = useCart();
  const { unread } = useUnreadCount();
  const reduceMotion = useReducedMotion();
  const [hintIndex, setHintIndex] = useState(0);

  const list = hints && hints.length > 0 ? hints : DEFAULT_HINTS;
  const rotates = list.length > 1 && !reduceMotion;
  const hint = list[hintIndex % list.length];

  // Rotate only while the screen is focused, so a hidden Beranda tab never keeps a timer alive.
  useFocusEffect(
    useCallback(() => {
      if (!rotates) {
        return;
      }
      const id = setInterval(() => setHintIndex((index) => index + 1), HINT_INTERVAL_MS);
      return () => clearInterval(id);
    }, [rotates])
  );

  const signedIn = Boolean(user);
  const bellCount = signedIn ? unread : 0;

  function openNotifications() {
    if (signedIn) {
      router.push('/notifications');
    } else {
      router.push({ pathname: '/login', params: { redirect: '/notifications' } });
    }
  }

  return (
    <View className="h-14 flex-row items-center gap-2 bg-om-navy px-3">
      <BrandLogo variant="light" size={28} withWordmark={false} />

      <Pressable
        accessibilityRole="search"
        accessibilityLabel="Cari produk"
        onPress={() => router.navigate('/(tabs)/search')}
        hitSlop={{ top: 4, bottom: 4 }}
        className="h-10 flex-1 flex-row items-center rounded-full bg-white px-3">
        <Icon as={Search} size={16} color={COLORS.slate} />
        <View className="ml-2 h-full flex-1 justify-center overflow-hidden">
          <Animated.View
            key={hintIndex % list.length}
            // The first hint is simply there (a remount of the header must not flash it).
            entering={reduceMotion || hintIndex === 0 ? undefined : FadeIn.duration(200)}
            exiting={reduceMotion ? undefined : FadeOut.duration(150)}
            style={{
              position: 'absolute',
              top: 0,
              bottom: 0,
              left: 0,
              right: 0,
              justifyContent: 'center',
            }}>
            <Text
              numberOfLines={1}
              maxFontSizeMultiplier={1.2}
              className="text-[13px] text-muted-foreground">
              {hint}
            </Text>
          </Animated.View>
        </View>
      </Pressable>

      <View className="-mr-1 flex-row items-center">
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={
            bellCount > 0 ? `Notifikasi, ${bellCount} belum dibaca` : 'Notifikasi'
          }
          onPress={openNotifications}
          android_ripple={ICON_RIPPLE}
          className="h-11 w-11 items-center justify-center active:opacity-70">
          <Icon as={Bell} size={20} color={COLORS.white} />
          <CartBadge count={bellCount} />
        </Pressable>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={itemCount > 0 ? `Keranjang, ${itemCount} barang` : 'Keranjang'}
          onPress={() => router.navigate('/(tabs)/cart')}
          android_ripple={ICON_RIPPLE}
          className="h-11 w-11 items-center justify-center active:opacity-70">
          <Icon as={ShoppingCart} size={20} color={COLORS.white} />
          <CartBadge count={itemCount} />
        </Pressable>
      </View>
    </View>
  );
}
