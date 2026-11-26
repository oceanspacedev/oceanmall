import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { LoginPrompt } from '@/components/login-prompt';
import { OrderStepper } from '@/components/order-stepper';
import { PaymentPanel } from '@/components/payment-panel';
import { ProductImage } from '@/components/product-image';
import { ReviewSheet, type ReviewResult, type ReviewTarget } from '@/components/review-sheet';
import { Screen } from '@/components/screen';
import {
  CopyButton,
  LogoImage,
  MenuRow,
  Section,
  StatusPill,
  StickyBar,
  SummaryRow,
} from '@/components/shop';
import { DetailSkeleton } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useBottomPad } from '@/components/safe-area-view';
import {
  api,
  errorMessage,
  isOfflinePayment,
  offlinePaymentNote,
  paymentFromAction,
  PROVIDER_TIMEOUT_MS,
  serverNow,
  syncServerClock,
  type OrderDetail,
} from '@/lib/api';
import { announce, useReducedMotion } from '@/lib/a11y';
import { useAuth } from '@/lib/auth';
import { WEB_URL } from '@/lib/config';
import { confirmAlert } from '@/lib/confirm';
import { TimeoutError } from '@/lib/network';
import { HAS_SUPPORT_CHANNEL, sendSupportMessage } from '@/lib/support';
import { usePolling } from '@/lib/countdown';
import {
  formatDate,
  formatDateTime,
  formatIdr,
  formatMoney,
  mediaUrl,
  parseApiDate,
  statusLabel,
} from '@/lib/format';
import { canReviewOrder, isCancelled, orderStage, orderSteps } from '@/lib/order-status';
import { getSecureItem, setSecureItem } from '@/lib/storage';
import { COLORS } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { useRefresh } from '@/lib/use-refresh';
import { ripple } from '@/lib/ripple';
import { cn } from '@/lib/utils';
import {
  Link,
  Stack,
  useFocusEffect,
  useLocalSearchParams,
  useNavigation,
  useRouter,
} from 'expo-router';
import {
  Check,
  CircleAlert,
  CircleCheckBig,
  Clock,
  CreditCard,
  FileText,
  Headset,
  MapPin,
  PackageOpen,
  Receipt,
  Star,
  Store,
  Truck,
  XCircle,
  type LucideIcon,
} from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Linking, Platform, Pressable, ScrollView, TextInput, View } from 'react-native';
import Animated, { FadeInDown, ZoomIn } from 'react-native-reanimated';

const STAGE_STYLE: Record<string, { bg: string; icon: LucideIcon }> = {
  unpaid: { bg: 'bg-om-warning', icon: Clock },
  processing: { bg: 'bg-om-navy', icon: PackageOpen },
  shipped: { bg: 'bg-om-navy', icon: Truck },
  completed: { bg: 'bg-om-success', icon: CircleCheckBig },
  cancelled: { bg: 'bg-muted-foreground', icon: XCircle },
};

/** Tracking rows shown before "Lihat semua riwayat". */
const HISTORY_PREVIEW = 6;
/** Toasts clear the sticky action bar by this much. */
const BAR_OFFSET = 96;

/** A fresh charge is only useful when there is none, or the current one has expired. */
function paymentNeedsRenewal(payment: OrderDetail['payment'], now: number): boolean {
  if (
    !payment ||
    (!payment.virtual_account_number && !payment.qris_string && !payment.payment_url)
  ) {
    return true;
  }
  const expires = parseApiDate(payment.expiry_date)?.getTime();

  return expires !== undefined && expires < now;
}

function reviewedKey(userId: number, slug: string): string {
  // SecureStore keys may only hold letters, digits, '.', '-' and '_' (a ':' throws).
  return `oceanmall.reviewed.${userId}.${slug.replace(/[^A-Za-z0-9._-]/g, '_')}`;
}

export default function OrderScreen() {
  const { number, notice, from } = useLocalSearchParams<{
    number: string;
    notice?: string;
    from?: string;
  }>();
  const { user } = useAuth();
  const router = useRouter();
  const navigation = useNavigation();
  // Without the StickyBar nothing else clears the Android navigation bar.
  const bottomPad = useBottomPad(16);
  // Opened by "Lihat pesanan" right after checkout (order-placed passes `from=placed`).
  const fromPlaced = from === 'placed';
  const reduceMotion = useReducedMotion();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  // True only until the first answer: later focus reloads keep the page on screen.
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busyAction, setBusyAction] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Bumped when the payment deadline passes, so the "renew" check is re-evaluated.
  const [now, setNow] = useState(() => serverNow());
  const [justPaid, setJustPaid] = useState(false);
  const [expandedTracking, setExpandedTracking] = useState<Record<number, boolean>>({});
  const [reviewed, setReviewed] = useState<Record<string, boolean>>({});
  const [reviewing, setReviewing] = useState<ReviewTarget | null>(null);
  const [reviewOpen, setReviewOpen] = useState(false);
  const [returnOpen, setReturnOpen] = useState(false);
  const [returnReason, setReturnReason] = useState('Barang rusak / cacat');
  const [returnDescription, setReturnDescription] = useState('');
  const [returnQuantities, setReturnQuantities] = useState<Record<number, number>>({});
  const [proofOpen, setProofOpen] = useState(false);
  const [proofBank, setProofBank] = useState('');
  const [proofSenderName, setProofSenderName] = useState('');
  const [proofAmount, setProofAmount] = useState('');
  const [proofFile, setProofFile] = useState<File | null>(null);
  const [proofFileName, setProofFileName] = useState<string | null>(null);
  const wasPaid = useRef<boolean | null>(null);
  const busyRef = useRef(false);
  const busy = busyAction !== null;
  const userId = user?.id ?? null;

  // A different order (or account) starts clean.
  useEffect(() => {
    setOrder(null);
    setLoading(true);
    setLoadError(null);
    setError(null);
    setJustPaid(false);
    setReviewed({});
    wasPaid.current = null;
  }, [number, userId]);

  const applyOrder = useCallback((received: OrderDetail) => {
    syncServerClock(received.server_time);
    setNow(serverNow());
    // The panel renders `payment`: derive it from the neutral payment_action when present.
    const next = { ...received, payment: paymentFromAction(received, received.amount) };
    const nowPaid = next.payment_status === 'paid';
    const before = wasPaid.current;
    wasPaid.current = nowPaid;
    // Polling returns the same payload most of the time: keep the old object so nothing re-renders.
    setOrder((current) =>
      current && JSON.stringify(current) === JSON.stringify(next) ? current : next
    );
    setReturnQuantities((current) => {
      if (Object.keys(current).length > 0) {
        return current;
      }
      const seeded: Record<number, number> = {};
      for (const item of received.items) {
        if (item.id) {
          seeded[item.id] = item.quantity;
        }
      }
      return seeded;
    });
    setProofAmount((current) => (current ? current : String(received.amount ?? '')));
    if (before === false && nowPaid) {
      setJustPaid(true);
      toast.show({ tone: 'success', message: 'Pembayaran diterima' });
      announce('Pembayaran diterima');
    }
  }, []);

  const load = useCallback(async () => {
    if (!number || userId === null) {
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
  }, [number, userId, applyOrder]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load])
  );

  const { refreshing, onRefresh } = useRefresh(load);

  // Right after checkout the screen under this one is the cart that has just been emptied (or
  // the product page), so Back, from the header arrow or Android's button, goes to Beranda
  // instead, like "Selesai" and Back on order-placed. Other removals (dismissTo) pass through.
  useEffect(() => {
    if (!fromPlaced) {
      return;
    }

    return navigation.addListener('beforeRemove', (event) => {
      const type = event.data.action.type;
      if (type !== 'GO_BACK' && type !== 'POP') {
        return;
      }
      event.preventDefault();
      // Outside the event, so the new navigation is not dispatched while this one is running.
      setTimeout(() => {
        if (router.canDismiss()) {
          router.dismissTo('/(tabs)');
        } else {
          router.replace('/(tabs)');
        }
      }, 0);
    });
  }, [fromPlaced, navigation, router]);

  // Reviewed products are remembered per account, so "Sudah diulas" survives leaving the screen.
  useEffect(() => {
    if (!order || userId === null || !canReviewOrder(order)) {
      return;
    }
    let cancelled = false;
    for (const item of order.items) {
      if (!item.slug) {
        continue;
      }
      const slug = item.slug;
      getSecureItem(reviewedKey(userId, slug))
        .then((value) => {
          if (!cancelled && value === '1') {
            setReviewed((current) => (current[slug] ? current : { ...current, [slug]: true }));
          }
        })
        .catch(() => {});
    }

    return () => {
      cancelled = true;
    };
  }, [order, userId]);

  useEffect(() => {
    if (!justPaid) {
      return;
    }
    const timer = setTimeout(() => setJustPaid(false), 6000);

    return () => clearTimeout(timer);
  }, [justPaid]);

  const stage = order ? orderStage(order) : null;
  // From the payment itself, not the stage: an unpaid order that already shipped (COD, or an AWB
  // typed before the transfer arrived) still has to be payable.
  const unpaid = order
    ? !isCancelled(order) && order.status !== 'completed' && order.payment_status !== 'paid'
    : false;
  const offline = order ? isOfflinePayment(order) : false;
  const needsOnlinePayment = unpaid && !offline;
  const paymentExpiry = parseApiDate(order?.payment?.expiry_date)?.getTime();
  const paymentExpired = paymentExpiry !== undefined && paymentExpiry < now;

  // Paid in a banking app and came back: the status flips without a tap. It stops once the order
  // is paid, cancelled or past its deadline (the deadline handler refetches once itself).
  usePolling(
    useCallback(async () => {
      if (!number) {
        return;
      }
      const res = await api<{ data: OrderDetail }>(`/orders/${number}`);
      applyOrder(res.data);
    }, [number, applyOrder]),
    needsOnlinePayment && !paymentExpired
  );

  async function run(action: string, task: () => Promise<void>) {
    if (busyRef.current) {
      return;
    }
    busyRef.current = true;
    setBusyAction(action);
    setError(null);
    try {
      await task();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busyRef.current = false;
      setBusyAction(null);
    }
  }

  async function syncPayment(quiet = false) {
    if (!order || busy) {
      return;
    }
    const orderNumber = order.number;
    const task = async () => {
      const res = await api<{
        data: {
          payment_status: string;
          payment: OrderDetail['payment'];
          payment_action?: OrderDetail['payment_action'];
        };
      }>(`/orders/${orderNumber}/sync-payment`, {
        method: 'POST',
        // Waits on the payment gateway, not the catalog's 8 s.
        timeoutMs: PROVIDER_TIMEOUT_MS,
      });
      if (res.data.payment_status === 'paid') {
        await load();
        return;
      }
      setOrder((current) =>
        current
          ? {
              ...current,
              payment_status: res.data.payment_status,
              payment_action: res.data.payment_action,
              payment: paymentFromAction(res.data),
            }
          : current
      );
      if (!quiet) {
        toast.show({
          tone: 'info',
          message: 'Pembayaran belum diterima. Coba lagi beberapa saat setelah membayar.',
          bottomOffset: BAR_OFFSET,
        });
      }
    };

    if (quiet) {
      // Closing the payment sheet is not a request to be told "not paid yet".
      try {
        await task();
      } catch {
        // The poll and the pull-to-refresh will pick it up.
      }
      return;
    }
    await run('sync', task);
  }

  async function retryPayment() {
    if (!order || busy) {
      return;
    }
    await run('retry', async () => {
      let res: {
        data: { payment: OrderDetail['payment']; payment_action?: OrderDetail['payment_action'] };
      };
      try {
        res = await api(`/orders/${order.number}/retry-payment`, {
          method: 'POST',
          // Waits on the payment gateway, not the catalog's 8 s.
          timeoutMs: PROVIDER_TIMEOUT_MS,
        });
      } catch (e) {
        // The server may still have created the payment: refetch so it shows up (polling is off
        // once the old one has expired), then report the timeout.
        if (e instanceof TimeoutError) {
          void load();
        }
        throw e;
      }
      setNow(serverNow());
      setOrder((current) =>
        current
          ? {
              ...current,
              payment_action: res.data.payment_action,
              payment: paymentFromAction(res.data, current.amount),
            }
          : current
      );
    });
  }

  async function trackShipment(shipmentId: number) {
    if (!order || busy) {
      return;
    }
    await run(`track-${shipmentId}`, async () => {
      const res = await api<{ data: OrderDetail; tracking_refreshed?: boolean }>(
        `/orders/${order.number}/shipments/${shipmentId}/track`,
        { method: 'POST', timeoutMs: PROVIDER_TIMEOUT_MS }
      );
      applyOrder(res.data);
      toast.show({
        tone: 'success',
        // false: the parcel is final or was refreshed minutes ago (stored tracking returned).
        message:
          res.tracking_refreshed === false
            ? 'Status pengiriman sudah yang terbaru'
            : 'Status pengiriman diperbarui',
        bottomOffset: BAR_OFFSET,
      });
    });
  }

  function cancelOrder() {
    if (!order || busy) {
      return;
    }
    const orderNumber = order.number;
    confirmAlert(
      'Batalkan pesanan?',
      'Pesanan yang dibatalkan akan mengembalikan stok dan kupon promo ke akunmu.',
      [
        { text: 'Kembali', style: 'cancel' },
        {
          text: 'Ya, batalkan',
          style: 'destructive',
          onPress: () =>
            void run('cancel', async () => {
              const res = await api<{ data: OrderDetail }>(`/orders/${orderNumber}/cancel`, {
                method: 'POST',
                body: JSON.stringify({ reason: 'Dibatalkan oleh pelanggan via aplikasi' }),
              });
              applyOrder(res.data);
              toast.show({
                tone: 'success',
                message: 'Pesanan berhasil dibatalkan.',
                bottomOffset: BAR_OFFSET,
              });
            }),
        },
      ]
    );
  }

  function confirmReceived() {
    if (!order) {
      return;
    }
    const orderNumber = order.number;
    confirmAlert(
      'Pesanan sudah diterima?',
      'Pastikan paket sudah sampai dan sesuai pesanan. Setelah dikonfirmasi, pesanan ditandai selesai.',
      [
        { text: 'Batal', style: 'cancel' },
        {
          text: 'Ya, sudah diterima',
          onPress: () =>
            void run('confirm', async () => {
              const res = await api<{ data: OrderDetail }>(
                `/orders/${orderNumber}/confirm-received`,
                { method: 'POST' }
              );
              applyOrder(res.data);
              const first = res.data.items.find((item) => item.slug && !reviewed[item.slug ?? '']);
              toast.show({
                tone: 'success',
                message: 'Terima kasih! Pesanan selesai.',
                bottomOffset: BAR_OFFSET,
                action:
                  first && first.slug && canReviewOrder(res.data)
                    ? {
                        label: 'Beri ulasan',
                        onPress: () =>
                          openReview({
                            slug: first.slug as string,
                            name: first.name,
                            thumbnail: first.thumbnail,
                          }),
                      }
                    : undefined,
              });
            }),
        },
      ]
    );
  }

  function openReview(target: ReviewTarget) {
    setReviewing(target);
    setReviewOpen(true);
  }

  function onReviewed(result: ReviewResult) {
    setReviewed((current) => ({ ...current, [result.slug]: true }));
    if (userId !== null) {
      setSecureItem(reviewedKey(userId, result.slug), '1').catch(() => {});
    }
  }

  async function askForHelp() {
    if (!order) {
      return;
    }
    const opened = await sendSupportMessage(
      `Halo OceanMall, saya butuh bantuan untuk pesanan ${order.number}.`,
      `Bantuan pesanan ${order.number}`
    );
    if (!opened) {
      toast.show({
        tone: 'error',
        message: 'WhatsApp dan email belum bisa dibuka di perangkat ini.',
      });
    }
  }

  if (!user) {
    return (
      <Screen title="Detail pesanan">
        <LoginPrompt
          icon={Receipt}
          title="Masuk untuk melihat pesanan"
          body="Pesanan ini tersimpan di akunmu. Masuk dulu untuk membukanya."
          redirect={`/order/${number}`}
        />
      </Screen>
    );
  }

  if (loading && !order) {
    return <Screen title="Detail pesanan" loading={<DetailSkeleton />} />;
  }

  if (!order || !stage) {
    return (
      <Screen title="Detail pesanan">
        <EmptyState
          tone="error"
          icon={CircleAlert}
          title="Pesanan tidak bisa dibuka"
          message={loadError ?? 'Pesanan tidak ditemukan'}
          actionLabel="Coba lagi"
          onAction={() => {
            setLoading(true);
            void load();
          }}
        />
      </Screen>
    );
  }

  function openInvoice() {
    if (!order?.invoice_url) {
      return;
    }
    void Linking.openURL(order.invoice_url);
  }

  function pickProofWebFile() {
    if (Platform.OS !== 'web' || typeof document === 'undefined') {
      if (order) {
        void Linking.openURL(`${WEB_URL}/account/orders/${order.id}`);
      }
      return;
    }
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.jpg,.jpeg,.png,.webp,.pdf';
    input.onchange = () => {
      const picked = input.files?.[0] ?? null;
      setProofFile(picked);
      setProofFileName(picked?.name ?? null);
    };
    input.click();
  }

  async function submitProof() {
    if (!order || busy) {
      return;
    }
    if (!proofFile) {
      setError('Pilih file bukti transfer terlebih dahulu (.jpg, .png, atau .pdf).');
      return;
    }
    const formData = new FormData();
    formData.append('proof', proofFile);
    if (proofBank.trim()) {
      formData.append('sender_bank', proofBank.trim());
    }
    if (proofSenderName.trim()) {
      formData.append('sender_name', proofSenderName.trim());
    }
    if (proofAmount.trim()) {
      formData.append('transfer_amount', proofAmount.trim());
    }
    const orderNumber = order.number;
    await run('proof', async () => {
      const res = await api<{ data: OrderDetail }>(`/orders/${orderNumber}/payment-proof`, {
        method: 'POST',
        body: formData,
      });
      applyOrder(res.data);
      setProofOpen(false);
      setProofFile(null);
      setProofFileName(null);
      toast.show({
        tone: 'success',
        message: 'Bukti transfer berhasil diunggah.',
        bottomOffset: BAR_OFFSET,
      });
    });
  }

  async function submitReturnRequest() {
    if (!order || busy) {
      return;
    }
    const items = order.items
      .filter(
        (item): item is OrderDetail['items'][number] & { id: number } =>
          typeof item.id === 'number' && (returnQuantities[item.id] ?? 0) > 0
      )
      .map((item) => ({
        order_item_id: item.id,
        qty: Math.min(item.quantity, Math.max(1, returnQuantities[item.id] ?? 0)),
      }));
    if (items.length === 0) {
      setError('Pilih minimal 1 barang yang ingin diretur.');
      return;
    }
    const orderNumber = order.number;
    await run('return', async () => {
      const res = await api<{ data: OrderDetail }>(`/orders/${orderNumber}/returns`, {
        method: 'POST',
        body: JSON.stringify({
          reason: returnReason,
          description: returnDescription.trim() || null,
          items,
        }),
      });
      applyOrder(res.data);
      setReturnOpen(false);
      setReturnDescription('');
      toast.show({
        tone: 'success',
        message: 'Pengajuan retur (RMA) terkirim.',
        bottomOffset: BAR_OFFSET,
      });
    });
  }

  const style = STAGE_STYLE[stage.key] ?? STAGE_STYLE.processing;
  // Every parcel that still travels must have shipped (a cancelled one is not waited for).
  const liveShipments = order.shipments.filter((shipment) => shipment.status !== 'cancelled');
  const hasAwb =
    liveShipments.length > 0 &&
    liveShipments.every(
      (shipment) =>
        shipment.status !== 'returned' &&
        shipment.status !== 'delivery_failed' &&
        Boolean(shipment.awb || shipment.tracking_number)
    );
  const canConfirm =
    (order.payment_status === 'paid' || Boolean(order.is_cod)) &&
    hasAwb &&
    order.shipping_status !== 'delivered' &&
    liveShipments.some((shipment) => shipment.status.toLowerCase() !== 'delivered') &&
    order.status !== 'completed' &&
    order.status !== 'cancelled';
  const reviewable = canReviewOrder(order);
  const firstSlug = order.items.find((item) => item.slug)?.slug;
  const showStickyBar =
    needsOnlinePayment ||
    canConfirm ||
    Boolean(order.can_cancel) ||
    (stage.key === 'completed' && Boolean(firstSlug)) ||
    Boolean(error);

  return (
    <Screen title="Detail pesanan" surface>
      {/* Swipe-back would skip the Back handling above (iOS). */}
      {fromPlaced ? <Stack.Screen options={{ gestureEnabled: false }} /> : null}
      <ScrollView
        className="flex-1"
        contentContainerStyle={{ paddingBottom: showStickyBar ? 16 : bottomPad }}
        refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
        {/* Status banner */}
        <View
          accessible
          accessibilityRole="header"
          accessibilityLabel={`${stage.label}. ${stage.hint}`}
          className={cn('flex-row items-center gap-3 px-4 py-4', style.bg)}>
          <View className="size-11 items-center justify-center rounded-full bg-white/20">
            <Icon as={style.icon} size={22} color={COLORS.white} />
          </View>
          <View className="flex-1">
            <Text className="text-[16px] font-extrabold text-white">{stage.label}</Text>
            <Text className="mt-0.5 text-[12px] leading-4 text-white/90">{stage.hint}</Text>
          </View>
        </View>

        {loadError ? <ErrorBanner message={loadError} onRetry={() => void load()} /> : null}

        {justPaid ? (
          <Animated.View
            entering={reduceMotion ? undefined : FadeInDown.duration(250)}
            style={{ marginHorizontal: 16, marginTop: 12 }}>
            <View className="flex-row items-center gap-3 rounded-sm bg-om-success-soft px-3 py-3">
              <Animated.View
                entering={reduceMotion ? undefined : ZoomIn.springify()}
                style={{
                  width: 32,
                  height: 32,
                  borderRadius: 16,
                  alignItems: 'center',
                  justifyContent: 'center',
                  backgroundColor: COLORS.success,
                }}>
                <Icon as={Check} size={18} color={COLORS.white} strokeWidth={3} />
              </Animated.View>
              <View className="flex-1">
                <Text className="text-[14px] font-extrabold text-om-success">
                  Pembayaran diterima
                </Text>
                <Text className="text-[12px] leading-4 text-om-success">
                  Pesananmu sedang disiapkan.
                </Text>
              </View>
            </View>
          </Animated.View>
        ) : null}

        {notice && unpaid ? <ErrorBanner message={notice} /> : null}

        {/* Progress */}
        <Section>
          <OrderStepper steps={orderSteps(order)} />
          {order.shipping_status === 'delivered' && order.status !== 'completed' ? (
            <View className="mt-3 rounded-sm bg-om-warning-soft px-3 py-2.5">
              <Text className="text-[12px] font-medium text-om-warning">
                Paket sudah diterima · Menunggu konfirmasi penyelesaian pembayaran oleh toko.
              </Text>
            </View>
          ) : null}
        </Section>

        {/* Payment */}
        {order.payment_status !== 'paid' && !isCancelled(order) && offline ? (
          <Section title="Pembayaran" icon={CreditCard}>
            <Text className="text-[13px] text-muted-foreground">
              {offlinePaymentNote(order) ??
                'Pembayaran tidak dilakukan di aplikasi. Kami konfirmasi setelah pembayaran diterima.'}
            </Text>
          </Section>
        ) : needsOnlinePayment ? (
          <Section title="Instruksi pembayaran" icon={CreditCard}>
            <PaymentPanel
              payment={order.payment}
              canRetry={order.can_retry_payment ?? false}
              currency={order.currency}
              onExpire={() => {
                // The cron may already have cancelled the order: look again before offering a retry.
                setNow(serverNow());
                void load();
              }}
              onClosed={() => void syncPayment(true)}
            />
            {order.can_retry_payment && paymentNeedsRenewal(order.payment, now) ? (
              <Button
                variant="outline"
                loading={busyAction === 'retry'}
                disabled={busy}
                className="mt-3 min-h-11 rounded-sm border-om-navy"
                onPress={() => void retryPayment()}>
                <Text className="font-bold text-om-navy">Buat pembayaran baru</Text>
              </Button>
            ) : null}
          </Section>
        ) : null}

        {/* Shipping */}
        <Section title="Info pengiriman" icon={Truck}>
          {order.shipments.length === 0 ? (
            <Text className="text-[13px] text-muted-foreground">
              {unpaid
                ? 'Pengiriman diproses setelah pembayaran diterima.'
                : 'Resi muncul setelah pesanan dikemas.'}
            </Text>
          ) : (
            order.shipments.map((shipment, index) => {
              const awb = shipment.awb || shipment.tracking_number;
              const history = shipment.tracking_history ?? [];
              const expanded = Boolean(expandedTracking[shipment.id]);
              const shown = expanded ? history : history.slice(0, HISTORY_PREVIEW);
              return (
                <View
                  key={shipment.id}
                  className={cn(index > 0 && 'mt-4 border-t border-border pt-4')}>
                  <View className="flex-row items-center gap-3">
                    <View className="size-10 items-center justify-center rounded-sm bg-om-surface">
                      <LogoImage uri={shipment.carrier_logo} fallback={Truck} />
                    </View>
                    <View className="flex-1">
                      <Text className="text-[14px] font-semibold">
                        {shipment.carrier ?? 'Kurir'} {shipment.service ?? ''}
                      </Text>
                      <Text className="text-[12px] text-muted-foreground">
                        {statusLabel(shipment.status)}
                      </Text>
                    </View>
                  </View>
                  <View className="mt-3 flex-row items-center justify-between rounded-sm bg-om-surface px-3 py-2.5">
                    <View className="flex-1">
                      <Text className="text-[12px] text-muted-foreground">No. resi</Text>
                      <Text selectable className="text-[14px] font-bold">
                        {awb ?? 'Belum tersedia'}
                      </Text>
                    </View>
                    {awb ? <CopyButton value={awb} label="Salin resi" /> : null}
                  </View>

                  {history.length > 0 ? (
                    <View className="mt-3">
                      {shown.map((event, eventIndex) => (
                        <View key={eventIndex} className="flex-row gap-3">
                          <View className="items-center">
                            <View
                              className={cn(
                                'mt-1 size-2.5 rounded-full',
                                eventIndex === 0 ? 'bg-om-navy' : 'bg-om-disabled'
                              )}
                            />
                            {eventIndex < shown.length - 1 ? (
                              <View className="w-px flex-1 bg-om-disabled" />
                            ) : null}
                          </View>
                          <View className="flex-1 pb-3">
                            <Text
                              className={cn(
                                'text-[13px] leading-5',
                                eventIndex === 0 ? 'font-semibold' : 'text-muted-foreground'
                              )}>
                              {event.description}
                            </Text>
                            {(event.datetime ?? event.date) ? (
                              <Text className="text-[12px] text-muted-foreground">
                                {formatDateTime((event.datetime ?? event.date) as string)}
                              </Text>
                            ) : null}
                          </View>
                        </View>
                      ))}
                      {history.length > HISTORY_PREVIEW ? (
                        <Pressable
                          accessibilityRole="button"
                          onPress={() =>
                            setExpandedTracking((current) => ({
                              ...current,
                              [shipment.id]: !expanded,
                            }))
                          }
                          className="min-h-11 justify-center active:opacity-60">
                          <Text className="text-[13px] font-semibold text-om-navy">
                            {expanded
                              ? 'Tampilkan lebih sedikit'
                              : `Lihat semua riwayat (${history.length})`}
                          </Text>
                        </Pressable>
                      ) : null}
                    </View>
                  ) : null}

                  {awb && shipment.can_track !== false ? (
                    <Button
                      variant="outline"
                      size="sm"
                      loading={busyAction === `track-${shipment.id}`}
                      disabled={busy}
                      className="mt-1 min-h-11 self-start rounded-sm border-om-navy"
                      onPress={() => void trackShipment(shipment.id)}>
                      <Text className="text-[12px] font-bold text-om-navy">Perbarui pelacakan</Text>
                    </Button>
                  ) : null}
                </View>
              );
            })
          )}

          {order.shipping_address ? (
            <View className="mt-4 flex-row gap-3 border-t border-border pt-4">
              <Icon as={MapPin} size={16} className="mt-0.5 text-om-navy" />
              <View className="flex-1">
                <Text className="text-[13px] font-semibold">
                  {order.shipping_address.name}
                  {order.shipping_address.phone ? (
                    <Text className="text-[12px] font-normal text-muted-foreground">
                      {' '}
                      · {order.shipping_address.phone}
                    </Text>
                  ) : null}
                </Text>
                <Text className="mt-0.5 text-[12px] leading-5 text-muted-foreground">
                  {order.shipping_address.street_address}
                  {order.shipping_address.area ? `, ${order.shipping_address.area}` : ''}
                </Text>
              </View>
            </View>
          ) : null}
        </Section>

        {/* Items */}
        <Section title="OceanMall Official" icon={Store}>
          {order.items.map((item, index) => {
            const row = (
              <View className={cn('flex-row gap-3', index > 0 && 'mt-3')}>
                <ProductImage uri={mediaUrl(item.thumbnail)} width={60} height={60} radius={6} />
                <View className="min-w-0 flex-1">
                  <Text className="text-[13px] leading-5" numberOfLines={2}>
                    {item.name}
                  </Text>
                  <Text className="mt-0.5 text-[12px] text-muted-foreground">
                    {item.quantity} × {formatMoney(item.unit_price, order.currency)}
                  </Text>
                </View>
                <Text className="text-[13px] font-bold">
                  {formatMoney(item.unit_price * item.quantity, order.currency)}
                </Text>
              </View>
            );
            const slug = item.slug;
            return (
              <View key={`${item.name}-${index}`}>
                {slug ? (
                  <Link href={`/product/${slug}`} asChild>
                    <Pressable
                      accessibilityRole="link"
                      accessibilityLabel={`${item.name}, ${item.quantity} barang`}
                      android_ripple={ripple.row}
                      className="active:opacity-80">
                      {row}
                    </Pressable>
                  </Link>
                ) : (
                  row
                )}
                {slug && reviewable ? (
                  Boolean(item.reviewed || reviewed[slug]) ? (
                    <View className="mt-2 flex-row items-center justify-end gap-1.5">
                      <Icon as={Check} size={14} className="text-om-success" />
                      <Text className="text-[12px] font-semibold text-om-success">
                        Sudah diulas
                      </Text>
                    </View>
                  ) : (
                    <View className="mt-2 items-end">
                      <Button
                        variant="outline"
                        size="sm"
                        className="min-h-11 rounded-sm border-om-navy"
                        accessibilityLabel={`Tulis ulasan untuk ${item.name}`}
                        onPress={() =>
                          openReview({ slug, name: item.name, thumbnail: item.thumbnail })
                        }>
                        <Icon as={Star} size={14} className="text-om-navy" />
                        <Text className="text-[12px] font-bold text-om-navy">Tulis ulasan</Text>
                      </Button>
                    </View>
                  )
                ) : null}
              </View>
            );
          })}
        </Section>

        {/* Payment breakdown */}
        <Section title="Rincian pembayaran" icon={Receipt}>
          {order.payment_method ? (
            <SummaryRow label="Metode pembayaran" value={order.payment_method} />
          ) : null}
          <SummaryRow
            label={`Total harga (${order.items.reduce((sum, item) => sum + item.quantity, 0)} barang)`}
            value={formatMoney(
              order.breakdown?.subtotal ?? order.totals?.subtotal ?? order.amount,
              order.currency
            )}
          />
          {order.breakdown ? (
            <>
              <SummaryRow
                label="Ongkos kirim"
                value={formatMoney(order.breakdown.shipping, order.currency)}
              />
              {order.breakdown.discount > 0 ? (
                <SummaryRow
                  label="Diskon kupon"
                  value={`−${formatMoney(order.breakdown.discount, order.currency)}`}
                  valueClassName="text-om-success"
                />
              ) : null}
              {order.breakdown.tax > 0 ? (
                <SummaryRow
                  label={order.breakdown.tax_inclusive ? 'PPN (termasuk)' : 'Pajak'}
                  value={formatMoney(order.breakdown.tax, order.currency)}
                />
              ) : null}
              {order.breakdown.refunded > 0 ? (
                <SummaryRow
                  label="Direfund"
                  value={`−${formatMoney(order.breakdown.refunded, order.currency)}`}
                />
              ) : null}
            </>
          ) : (
            <>
              {order.totals ? (
                <SummaryRow
                  label="Ongkos kirim"
                  value={formatMoney(order.totals.shipping, order.currency)}
                />
              ) : null}
              {order.totals && order.totals.adjustment !== 0 ? (
                <SummaryRow
                  label={order.totals.adjustment < 0 ? 'Diskon' : 'Biaya lainnya'}
                  value={`${order.totals.adjustment < 0 ? '−' : ''}${formatMoney(Math.abs(order.totals.adjustment), order.currency)}`}
                  valueClassName={order.totals.adjustment < 0 ? 'text-om-success' : undefined}
                />
              ) : null}
            </>
          )}
          <View className="my-1.5 h-px bg-border" />
          <SummaryRow
            label="Total belanja"
            value={formatMoney(order.amount, order.currency)}
            strong
          />
        </Section>

        {order.can_upload_payment_proof || (order.payment_proofs?.length ?? 0) > 0 ? (
          <Section title="Bukti transfer bank" icon={CreditCard}>
            {(order.payment_proofs?.length ?? 0) > 0 ? (
              <View className="gap-1.5">
                {order.payment_proofs!.map((proof) => (
                  <View key={proof.id} className="rounded-sm bg-om-surface px-3 py-2">
                    <Text className="text-[13px] font-semibold">
                      {proof.original_name} · {proof.status}
                    </Text>
                    {proof.verification_notes ? (
                      <Text className="mt-0.5 text-[12px] text-muted-foreground">
                        {proof.verification_notes}
                      </Text>
                    ) : null}
                  </View>
                ))}
              </View>
            ) : null}
            {order.can_upload_payment_proof ? (
              <View className="mt-3">
                {!proofOpen ? (
                  <Button
                    variant="outline"
                    size="sm"
                    onPress={() => setProofOpen(true)}
                    className="min-h-10 self-start rounded-sm border-om-navy">
                    <Text className="text-[12px] font-bold text-om-navy">
                      Unggah bukti transfer
                    </Text>
                  </Button>
                ) : (
                  <View className="gap-2.5 rounded-sm border border-border p-3">
                    <Text className="text-[13px] font-bold">Unggah Bukti Transfer</Text>
                    <TextInput
                      value={proofBank}
                      onChangeText={setProofBank}
                      placeholder="Bank pengirim (mis. BCA)"
                      className="rounded-sm border border-border bg-white px-3 py-2 text-[13px] text-foreground"
                    />
                    <TextInput
                      value={proofSenderName}
                      onChangeText={setProofSenderName}
                      placeholder="Nama pemilik rekening"
                      className="rounded-sm border border-border bg-white px-3 py-2 text-[13px] text-foreground"
                    />
                    <TextInput
                      value={proofAmount}
                      onChangeText={setProofAmount}
                      keyboardType="numeric"
                      placeholder="Nominal transfer"
                      className="rounded-sm border border-border bg-white px-3 py-2 text-[13px] text-foreground"
                    />
                    <View className="flex-row flex-wrap items-center gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        onPress={pickProofWebFile}
                        className="min-h-10 rounded-sm border-om-navy">
                        <Text className="text-[12px] font-bold text-om-navy">
                          {Platform.OS === 'web'
                            ? (proofFileName ?? 'Pilih file (.jpg/.png/.pdf)')
                            : 'Unggah lewat Web'}
                        </Text>
                      </Button>
                      {Platform.OS === 'web' ? (
                        <Button
                          size="sm"
                          loading={busyAction === 'proof'}
                          disabled={busy}
                          onPress={() => void submitProof()}
                          className="min-h-10 rounded-sm">
                          <Text className="text-[12px] font-bold text-white">Kirim bukti</Text>
                        </Button>
                      ) : null}
                      <Button
                        variant="ghost"
                        size="sm"
                        onPress={() => setProofOpen(false)}
                        className="min-h-10 rounded-sm">
                        <Text className="text-[12px] font-semibold text-muted-foreground">
                          Batal
                        </Text>
                      </Button>
                    </View>
                  </View>
                )}
              </View>
            ) : null}
          </Section>
        ) : null}

        {order.can_request_return || (order.return_requests?.length ?? 0) > 0 ? (
          <Section title="Retur / Garansi (RMA)" icon={FileText}>
            {(order.return_requests?.length ?? 0) > 0 ? (
              <View className="gap-1.5">
                {order.return_requests!.map((ret) => (
                  <View key={ret.id} className="rounded-sm bg-om-surface px-3 py-2">
                    <Text className="text-[13px] font-semibold">
                      RMA #{ret.id} · {ret.reason} ({ret.status})
                    </Text>
                    {ret.resolution_notes ? (
                      <Text className="mt-0.5 text-[12px] text-muted-foreground">
                        {ret.resolution_notes}
                      </Text>
                    ) : null}
                  </View>
                ))}
              </View>
            ) : null}
            {order.can_request_return ? (
              <View className="mt-3">
                {!returnOpen ? (
                  <Button
                    variant="outline"
                    size="sm"
                    onPress={() => setReturnOpen(true)}
                    className="min-h-10 self-start rounded-sm border-om-navy">
                    <Text className="text-[12px] font-bold text-om-navy">Ajukan retur</Text>
                  </Button>
                ) : (
                  <View className="gap-2.5 rounded-sm border border-border p-3">
                    <Text className="text-[13px] font-bold">Form Pengajuan Retur</Text>
                    <View className="flex-row flex-wrap gap-1.5">
                      {['Barang rusak / cacat', 'Barang tidak sesuai', 'Barang kurang lengkap'].map(
                        (opt) => (
                          <Pressable
                            key={opt}
                            accessibilityRole="button"
                            onPress={() => setReturnReason(opt)}
                            className={cn(
                              'rounded-full border px-3 py-1.5',
                              returnReason === opt
                                ? 'border-om-navy bg-om-navy'
                                : 'border-border bg-white'
                            )}>
                            <Text
                              className={cn(
                                'text-[12px] font-semibold',
                                returnReason === opt ? 'text-white' : 'text-foreground'
                              )}>
                              {opt}
                            </Text>
                          </Pressable>
                        )
                      )}
                    </View>
                    <TextInput
                      value={returnDescription}
                      onChangeText={setReturnDescription}
                      multiline
                      placeholder="Jelaskan kondisi barang yang diterima"
                      className="min-h-16 rounded-sm border border-border bg-white px-3 py-2 text-[13px] text-foreground"
                    />
                    {order.items
                      .filter((item) => item.id)
                      .map((item) => {
                        const qty = returnQuantities[item.id as number] ?? 0;
                        return (
                          <View
                            key={item.id}
                            className="flex-row items-center justify-between gap-2 py-1">
                            <Text className="flex-1 text-[12px]" numberOfLines={1}>
                              {item.name} (Maks {item.quantity})
                            </Text>
                            <View className="flex-row items-center gap-2">
                              <Button
                                variant="outline"
                                size="sm"
                                onPress={() =>
                                  setReturnQuantities((prev) => ({
                                    ...prev,
                                    [item.id as number]: Math.max(0, qty - 1),
                                  }))
                                }
                                className="h-8 min-w-8 px-2">
                                <Text className="font-bold">−</Text>
                              </Button>
                              <Text className="w-6 text-center text-[13px] font-bold">{qty}</Text>
                              <Button
                                variant="outline"
                                size="sm"
                                onPress={() =>
                                  setReturnQuantities((prev) => ({
                                    ...prev,
                                    [item.id as number]: Math.min(item.quantity, qty + 1),
                                  }))
                                }
                                className="h-8 min-w-8 px-2">
                                <Text className="font-bold">+</Text>
                              </Button>
                            </View>
                          </View>
                        );
                      })}
                    <View className="flex-row gap-2">
                      <Button
                        size="sm"
                        loading={busyAction === 'return'}
                        disabled={busy}
                        onPress={() => void submitReturnRequest()}
                        className="min-h-10 rounded-sm">
                        <Text className="text-[12px] font-bold text-white">
                          Kirim pengajuan retur
                        </Text>
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onPress={() => setReturnOpen(false)}
                        className="min-h-10 rounded-sm">
                        <Text className="text-[12px] font-semibold text-muted-foreground">
                          Batal
                        </Text>
                      </Button>
                    </View>
                  </View>
                )}
              </View>
            ) : null}
          </Section>
        ) : null}

        {/* Order info */}
        <Section title="Info pesanan" icon={FileText}>
          {/* The number gives way at large font sizes (it wraps), so "Salin nomor" stays on screen. */}
          <View className="flex-row items-center justify-between py-1">
            <Text className="shrink-0 text-[13px] text-muted-foreground">No. pesanan</Text>
            <View className="ml-3 min-w-0 flex-1 flex-row items-center justify-end gap-2">
              <Text selectable className="shrink text-right text-[13px] font-semibold">
                {order.number}
              </Text>
              <CopyButton value={order.number} label="Salin nomor" />
            </View>
          </View>
          <SummaryRow
            label="Tanggal pembelian"
            value={formatDateTime(order.created_at) || formatDate(order.created_at)}
          />
          <View className="flex-row items-center justify-between py-1">
            <Text className="text-[13px] text-muted-foreground">Status</Text>
            <StatusPill label={stage.label} tone={stage.tone} />
          </View>
          {order.invoice_url ? (
            <Button
              variant="outline"
              size="sm"
              onPress={openInvoice}
              className="mt-3 min-h-10 self-start rounded-sm border-om-navy">
              <Text className="text-[12px] font-bold text-om-navy">
                Lihat / Cetak Invoice{order.invoice_number ? ` (${order.invoice_number})` : ''}
              </Text>
            </Button>
          ) : null}
        </Section>

        {HAS_SUPPORT_CHANNEL ? (
          <Section flush className="py-0">
            <MenuRow
              icon={Headset}
              label="Butuh bantuan?"
              hint="Hubungi OceanMall tentang pesanan ini"
              onPress={() => void askForHelp()}
              last
            />
          </Section>
        ) : null}
      </ScrollView>

      {showStickyBar ? (
        <StickyBar>
          {error ? <ErrorBanner message={error} className="mx-0 mb-2.5 mt-0" /> : null}
          {needsOnlinePayment ? (
            <Button
              loading={busyAction === 'sync'}
              disabled={busy}
              onPress={() => void syncPayment()}
              className="min-h-11 rounded-sm">
              <Text className="font-bold">Cek status pembayaran</Text>
            </Button>
          ) : canConfirm ? (
            <Button
              loading={busyAction === 'confirm'}
              disabled={busy}
              className="min-h-11 rounded-sm"
              onPress={confirmReceived}>
              <Text className="font-bold">Pesanan diterima</Text>
            </Button>
          ) : stage.key === 'completed' && firstSlug ? (
            <Button
              onPress={() => router.push(`/product/${firstSlug}`)}
              className="min-h-11 rounded-sm">
              <Text className="font-bold">Beli lagi</Text>
            </Button>
          ) : null}
          {order.can_cancel ? (
            <Button
              variant="outline"
              loading={busyAction === 'cancel'}
              disabled={busy}
              onPress={cancelOrder}
              className="mt-2 min-h-11 rounded-sm border-destructive">
              <Text className="font-bold text-destructive">Batalkan pesanan</Text>
            </Button>
          ) : null}
        </StickyBar>
      ) : null}

      <ReviewSheet
        visible={reviewOpen}
        target={reviewing}
        orderNumber={order.number}
        onClose={() => setReviewOpen(false)}
        onSubmitted={onReviewed}
        toastBottomOffset={BAR_OFFSET}
      />
    </Screen>
  );
}
