import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { Image } from 'expo-image';
import { StatusBar } from 'expo-status-bar';
import { ShieldCheck, Truck, type LucideIcon } from 'lucide-react-native';
import { useEffect, useRef, useState } from 'react';
import {
  BackHandler,
  Pressable,
  ScrollView,
  View,
  useWindowDimensions,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
} from 'react-native';
import Animated, { FadeIn, useReducedMotion } from 'react-native-reanimated';
import { SafeAreaView } from '@/components/safe-area-view';

type Slide = {
  key: string;
  title: string;
  body: string;
  icon?: LucideIcon;
};

// Couriers and payment methods come from the providers at runtime, so the copy names neither and
// promises no guarantees.
const SLIDES: Slide[] = [
  {
    key: 'welcome',
    title: 'Belanja Lebih Luas, Pilihan Tanpa Batas',
    body: 'Temukan produk pilihan dan promo setiap hari, semuanya dalam satu aplikasi.',
  },
  {
    key: 'shipping',
    icon: Truck,
    title: 'Ongkir Jelas, Paket Terlacak',
    body: 'Lihat tarif kurir sebelum bayar dan lacak status paket langsung dari aplikasi.',
  },
  {
    key: 'payment',
    icon: ShieldCheck,
    title: 'Bayar Mudah dan Aman',
    body: 'Bayar lewat virtual account atau QRIS. Status pembayaran diperbarui otomatis.',
  },
];

const MARK = require('@/assets/images/logo-mark.png');

/**
 * First-run intro: three skippable slides. AuthGate renders it before the navigator mounts, so it
 * has no router: `onFinish` tells the gate how the intro ended, and the gate opens the app (and
 * the login screen for 'login').
 */
export function Onboarding({ onFinish }: { onFinish: (next?: 'login') => void }) {
  const { width, height } = useWindowDimensions();
  const reduceMotion = useReducedMotion();
  const scrollRef = useRef<ScrollView>(null);
  const [index, setIndex] = useState(0);
  const indexRef = useRef(0);
  const finishedRef = useRef(false);

  const last = index === SLIDES.length - 1;
  // Smaller phones get a smaller illustration so headline, body and buttons all stay on screen.
  const visual = Math.min(220, Math.max(150, Math.round(height * 0.27)));

  function finish(next?: 'login') {
    // A double tap must not finish twice.
    if (finishedRef.current) {
      return;
    }
    finishedRef.current = true;
    onFinish(next);
  }

  function goTo(page: number) {
    scrollRef.current?.scrollTo({ x: width * page, animated: !reduceMotion });
  }

  function syncIndex(event: NativeSyntheticEvent<NativeScrollEvent>) {
    const page = Math.min(
      SLIDES.length - 1,
      Math.max(0, Math.round(event.nativeEvent.contentOffset.x / width))
    );
    if (page !== indexRef.current) {
      indexRef.current = page;
      setIndex(page);
    }
  }

  // Android back steps to the previous slide instead of closing the app mid-intro.
  useEffect(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (indexRef.current > 0) {
        scrollRef.current?.scrollTo({ x: width * (indexRef.current - 1), animated: true });
        return true;
      }
      return false;
    });

    return () => subscription.remove();
  }, [width]);

  return (
    <SafeAreaView className="flex-1 bg-white">
      <StatusBar style="dark" />

      <View className="h-12 flex-row items-center justify-end px-2">
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Lewati pengenalan"
          onPress={() => finish()}
          className="h-11 min-w-11 items-center justify-center rounded-full px-3 active:opacity-60">
          <Text className="text-[14px] font-bold text-om-navy">Lewati</Text>
        </Pressable>
      </View>

      <Animated.View style={{ flex: 1 }} entering={reduceMotion ? undefined : FadeIn.duration(350)}>
        <ScrollView
          ref={scrollRef}
          horizontal
          pagingEnabled
          scrollsToTop={false}
          bounces={false}
          snapToInterval={width}
          decelerationRate="fast"
          disableIntervalMomentum
          showsHorizontalScrollIndicator={false}
          scrollEventThrottle={16}
          onScroll={syncIndex}
          onMomentumScrollEnd={syncIndex}
          style={{ flex: 1 }}>
          {SLIDES.map((slide, i) => (
            <View
              key={slide.key}
              // Top-aligned at a fixed offset so the illustration sits in the same place on every
              // slide, whatever the headline length.
              style={{ width, paddingTop: Math.round(height * 0.04) }}
              // Screen readers only see the slide on screen; the button moves to the next one.
              accessibilityElementsHidden={i !== index}
              importantForAccessibility={i === index ? 'auto' : 'no-hide-descendants'}
              className="items-center">
              <View
                style={{ width: visual, height: visual }}
                className="items-center justify-center rounded-full bg-om-navy-soft">
                {slide.icon ? (
                  <Icon as={slide.icon} size={Math.round(visual * 0.33)} className="text-om-navy" />
                ) : (
                  <Image
                    source={MARK}
                    style={{ width: Math.round(visual * 0.68), height: Math.round(visual * 0.68) }}
                    contentFit="contain"
                    accessible={false}
                  />
                )}
              </View>
              <Text
                role="heading"
                aria-level={1}
                maxFontSizeMultiplier={1.3}
                className="mt-8 px-8 text-center text-[24px] font-extrabold text-om-navy">
                {slide.title}
              </Text>
              <Text
                maxFontSizeMultiplier={1.3}
                className="mt-3 px-10 text-center text-[14px] leading-6 text-muted-foreground">
                {slide.body}
              </Text>
            </View>
          ))}
        </ScrollView>
      </Animated.View>

      <View className="px-5 pb-3 pt-2">
        <View
          accessibilityElementsHidden
          importantForAccessibility="no-hide-descendants"
          className="flex-row items-center justify-center gap-1.5">
          {SLIDES.map((slide, i) => (
            <View
              key={slide.key}
              className={`h-1.5 rounded-full ${i === index ? 'w-4 bg-om-navy' : 'w-1.5 bg-om-navy/25'}`}
            />
          ))}
        </View>

        <Button
          accessibilityLabel={last ? 'Mulai Belanja' : 'Lanjut'}
          accessibilityHint={`Halaman ${index + 1} dari ${SLIDES.length}`}
          className="mt-5 min-h-12 rounded-sm"
          onPress={() => (last ? finish() : goTo(index + 1))}>
          <Text className="text-[15px] font-bold">{last ? 'Mulai Belanja' : 'Lanjut'}</Text>
        </Button>

        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Sudah punya akun? Masuk"
          onPress={() => finish('login')}
          className="mt-1 min-h-11 items-center justify-center active:opacity-60">
          <Text className="text-[13px] text-muted-foreground">
            Sudah punya akun? <Text className="text-[13px] font-bold text-om-navy">Masuk</Text>
          </Text>
        </Pressable>
      </View>
    </SafeAreaView>
  );
}
