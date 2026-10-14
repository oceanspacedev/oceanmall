import { Countdown } from '@/components/countdown';
import { EmptyState } from '@/components/empty-state';
import { PaymentPanel } from '@/components/payment-panel';
import { CopyButton, Section, StickyBar } from '@/components/shop';
import { DetailSkeleton } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { announce, useReducedMotion } from '@/lib/a11y';
import { api, errorMessage, type OrderDetail } from '@/lib/api';
import { useCountdown, usePolling } from '@/lib/countdown';
import { formatDateTime, formatIdr } from '@/lib/format';
import { orderStage } from '@/lib/order-status';
import { ripple } from '@/lib/ripple';
import { COLORS } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { CircleAlert, CircleCheckBig, Clock, CreditCard } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { BackHandler, Pressable, ScrollView, View } from 'react-native';
import Animated, { ZoomIn } from 'react-native-reanimated';
import { SafeAreaView } from '@/components/safe-area-view';

const MISSING_PAYMENT_NOTICE =
  'Instruksi pembayaran belum tersedia. Buka pesananmu untuk melanjutkan pembayaran.';

/**
 * The moment after "Bayar": a check, the order number, the total, a live countdown to the
 * payment deadline and the virtual account or QR code to pay with.
 *
 * It is not a <Screen>: that header's Back would return to a cart that has just been emptied.
 * Swipe-back is off, Android's back button goes to Beranda, and "Selesai" does the same. The
 * order is fetched here (not passed in), so re-entering the screen works too.
 */
export default function OrderPlacedScreen() {
  const { number, notice } = useLocalSearchParams<{ number: string; notice?: string }>();
  const router = useRouter();
  const reduceMotion = useReducedMotion();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const wasPaid = useRef<boolean | null>(null);

  const goHome = useCallback(() => {
    // The stack is [Beranda, this]: pop back to the existing Beranda instead of stacking a second.
    if (router.canDismiss()) {
      router.dismissTo('/(tabs)');
    } else {
      router.replace('/(tabs)');
    }
  }, [router]);

  function openOrder() {
    router.replace({
      pathname: '/order/[number]',
      params: { number, ...(notice ? { notice } : {}) },
    });
  }

  const applyOrder = useCallback((next: OrderDetail) => {
    const nowPaid = next.payment_status === 'paid';
    const before = wasPaid.current;
    wasPaid.current = nowPaid;
    setOrder((current) =>
      current && JSON.stringify(current) === JSON.stringify(next) ? current : next
    );
    if (before === false && nowPaid) {
      toast.show({ tone: 'success', message: 'Pembayaran diterima' });
      announce('Pembayaran diterima');
    }
  }, []);

  const load = useCallback(async () => {
    if (!number) {
      setLoading(false);
      return;
    }
    try {
      const res = await api<{ data: OrderDetail }>(`/orders/${number}`);
      applyOrder(res.data);
      setLoadError(null);
    } catch (e) {
      setLoadError(errorMessage(e, 'Pesanan tidak ditemukan'));
    } finally {
      setLoading(false);
    }
  }, [number, applyOrder]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load])
  );

  // Android's back button leaves for Beranda, like "Selesai": going back would reach a cart that
  // has just been emptied.
  useFocusEffect(
    useCallback(() => {
      const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
        goHome();
        return true;
      });

      return () => subscription.remove();
    }, [goHome])
  );

  useEffect(() => {
    announce('Pesanan berhasil dibuat');
  }, []);

  const payment = order?.payment ?? null;
  const hasPaymentDetails = Boolean(
    payment && (payment.virtual_account_number || payment.qris_string || payment.payment_url)
  );
  const countdown = useCountdown(payment?.expiry_date, () => void load());
  const paid = order?.payment_status === 'paid';
  const expired = countdown?.expired ?? false;
  const unpaid = order ? orderStage(order).key === 'unpaid' : false;

  // Paying from a banking app and coming back flips this screen to "Pembayaran diterima".
  usePolling(
    useCallback(async () => {
      if (!number) {
        return;
      }
      const res = await api<{ data: OrderDetail }>(`/orders/${number}`);
      applyOrder(res.data);
    }, [number, applyOrder]),
    unpaid && hasPaymentDetails && !expired
  );

  // The payment sheet was dismissed: look at the status once without nagging about "not paid yet".
  const checkAfterSheet = useCallback(async () => {
    if (!number) {
      return;
    }
    try {
      await api(`/orders/${number}/sync-payment`, { method: 'POST' });
      await load();
    } catch {
      // The poll will pick it up.
    }
  }, [number, load]);

  const showPayment = Boolean(order) && unpaid && hasPaymentDetails && !expired;
  const missingPayment = Boolean(order) && !paid && !showPayment;
  const title = paid
    ? 'Pembayaran diterima!'
    : missingPayment
      ? 'Pesanan dibuat'
      : 'Pesanan berhasil dibuat!';
  const subtitle = paid
    ? 'Pesananmu sedang disiapkan.'
    : missingPayment
      ? null
      : 'Selesaikan pembayaran agar pesananmu segera diproses.';

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <Stack.Screen options={{ gestureEnabled: false }} />

      <View className="min-h-[52px] flex-row items-center bg-om-navy px-1">
        <View className="w-20" />
        <Text
          role="heading"
          aria-level={1}
          numberOfLines={1}
          maxFontSizeMultiplier={1.2}
          className="flex-1 text-center text-[16px] font-bold text-white">
          Pesanan dibuat
        </Text>
        <View className="w-20 items-end">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Selesai, ke beranda"
            onPress={goHome}
            android_ripple={ripple.onNavy}
            className="h-11 min-w-11 items-center justify-center px-3 active:opacity-70">
            <Text maxFontSizeMultiplier={1.2} className="text-[14px] font-bold text-white">
              Selesai
            </Text>
          </Pressable>
        </View>
      </View>

      <View className="flex-1 bg-om-surface">
        {loading && !order ? (
          <View accessibilityLiveRegion="polite" className="flex-1 overflow-hidden">
            <Text className="absolute h-px w-px opacity-0">Memuat…</Text>
            <DetailSkeleton />
          </View>
        ) : !order ? (
          <EmptyState
            tone="error"
            icon={CircleAlert}
            title="Pesananmu sudah dibuat"
            message={`${loadError ?? 'Detail pesanan belum bisa dimuat.'} Kamu bisa membukanya dari daftar pesanan.`}
            actionLabel="Coba lagi"
            onAction={() => {
              setLoading(true);
              void load();
            }}
            secondaryLabel="Lihat pesanan"
            onSecondary={openOrder}
            className="bg-om-surface"
          />
        ) : (
          <>
            <ScrollView className="flex-1" contentContainerClassName="pb-4">
              <View className="items-center bg-white px-6 pb-5 pt-7">
                <Animated.View
                  entering={reduceMotion ? undefined : ZoomIn.springify()}
                  style={{ width: 88, height: 88, borderRadius: 44, overflow: 'hidden' }}>
                  <View className="size-full items-center justify-center bg-om-success-soft">
                    <Icon as={CircleCheckBig} size={52} color={COLORS.success} strokeWidth={2.25} />
                  </View>
                </Animated.View>
                <Text
                  role="heading"
                  aria-level={2}
                  className="mt-4 text-center text-[20px] font-extrabold text-foreground">
                  {title}
                </Text>
                {subtitle ? (
                  <Text className="mt-1 text-center text-[13px] leading-5 text-muted-foreground">
                    {subtitle}
                  </Text>
                ) : null}
              </View>

              <Section>
                <View className="flex-row items-center justify-between py-1">
                  <Text className="text-[13px] text-muted-foreground">No. pesanan</Text>
                  <View className="flex-row items-center gap-2">
                    <Text selectable className="text-[13px] font-semibold">
                      {order.number}
                    </Text>
                    <CopyButton value={order.number} label="Salin nomor" />
                  </View>
                </View>
                <View className="flex-row items-center justify-between py-1">
                  <Text className="text-[13px] text-muted-foreground">Total belanja</Text>
                  <Text className="text-[16px] font-extrabold text-om-navy">
                    {formatIdr(order.amount)}
                  </Text>
                </View>

                {showPayment && countdown ? (
                  <View className="mt-2 rounded-sm bg-om-warning-soft px-3 py-2.5">
                    <View className="flex-row items-center gap-2">
                      <Icon as={Clock} size={15} className="text-om-warning" />
                      <Text className="flex-1 text-[13px] font-semibold text-om-warning">
                        Bayar dalam
                      </Text>
                      <Countdown state={countdown} size="lg" />
                    </View>
                    {payment?.expiry_date ? (
                      <Text className="mt-1.5 text-[12px] text-om-warning">
                        Batas bayar {formatDateTime(payment.expiry_date)}
                      </Text>
                    ) : null}
                  </View>
                ) : null}
              </Section>

              {showPayment ? (
                <Section title="Instruksi pembayaran" icon={CreditCard}>
                  <PaymentPanel compact payment={payment} onClosed={() => void checkAfterSheet()} />
                </Section>
              ) : null}

              {missingPayment ? (
                <View
                  accessibilityRole="alert"
                  className="mx-4 mt-3 flex-row items-start gap-2 rounded-sm bg-om-sale-soft px-3 py-3">
                  <Icon as={CircleAlert} size={15} className="mt-0.5 text-om-sale" />
                  <Text className="flex-1 text-[13px] leading-5 text-om-sale-text">
                    {expired
                      ? 'Batas pembayaran sudah lewat. Buka pesananmu untuk membuat pembayaran baru.'
                      : (notice ?? MISSING_PAYMENT_NOTICE)}
                  </Text>
                </View>
              ) : null}
            </ScrollView>

            <StickyBar>
              <Button onPress={openOrder} className="min-h-12 rounded-sm">
                <Text className="font-bold">
                  {showPayment ? 'Lihat cara bayar' : 'Lihat pesanan'}
                </Text>
              </Button>
              <Pressable
                accessibilityRole="button"
                onPress={goHome}
                className="mt-1 min-h-11 items-center justify-center active:opacity-60">
                <Text className="text-[14px] font-semibold text-om-navy">Kembali ke beranda</Text>
              </Pressable>
            </StickyBar>
          </>
        )}
      </View>
    </SafeAreaView>
  );
}
