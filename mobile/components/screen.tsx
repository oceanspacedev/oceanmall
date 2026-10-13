import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useCart } from '@/lib/cart';
import { StatusBar } from 'expo-status-bar';
import { useRouter } from 'expo-router';
import { ChevronLeft, ShoppingBagIcon } from 'lucide-react-native';
import type { ReactNode } from 'react';
import { ActivityIndicator, Pressable, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export function Screen({
  title,
  showCart = false,
  loading = false,
  surface = false,
  right,
  children,
}: {
  title: string;
  showCart?: boolean;
  loading?: boolean;
  /** Light-gray page so white Section cards stand out (marketplace layout). */
  surface?: boolean;
  /** Custom header action (replaces the cart button). */
  right?: ReactNode;
  children?: ReactNode;
}) {
  const router = useRouter();
  const { itemCount } = useCart();

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <StatusBar style="light" />
      <View className="h-[52px] flex-row items-center bg-om-navy px-1">
        <Pressable
          accessibilityLabel="Kembali"
          onPress={() => router.back()}
          className="h-10 w-10 items-center justify-center"
          hitSlop={8}>
          <Icon as={ChevronLeft} size={22} color="#fff" />
        </Pressable>
        <Text className="flex-1 text-center text-[15px] font-bold text-white" numberOfLines={1}>
          {title}
        </Text>
        {right ? (
          <View className="min-w-10 items-end justify-center pr-2">{right}</View>
        ) : showCart ? (
          <Pressable
            accessibilityLabel="Keranjang"
            onPress={() => router.push('/(tabs)/cart')}
            className="h-10 w-10 items-center justify-center"
            hitSlop={8}>
            <Icon as={ShoppingBagIcon} size={20} color="#fff" />
            {itemCount > 0 ? (
              <View className="absolute right-1 top-1 min-w-[16px] items-center rounded-full bg-om-sale px-1">
                <Text className="text-[10px] font-extrabold text-white">
                  {itemCount > 99 ? '99+' : itemCount}
                </Text>
              </View>
            ) : null}
          </Pressable>
        ) : (
          <View className="h-10 w-10" />
        )}
      </View>
      <View className={surface ? 'flex-1 bg-om-surface' : 'flex-1 bg-background'}>
        {loading ? (
          <View className="flex-1 items-center justify-center">
            <ActivityIndicator />
          </View>
        ) : (
          children
        )}
      </View>
    </SafeAreaView>
  );
}
