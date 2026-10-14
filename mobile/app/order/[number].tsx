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
import { api, errorMessage, type OrderDetail } from '@/lib/api';
import { announce, useReducedMotion } from '@/lib/a11y';
import { useAuth } from '@/lib/auth';
import { HAS_SUPPORT_CHANNEL, sendSupportMessage } from '@/lib/support';
import { usePolling } from '@/lib/countdown';
import {
  formatDate,
  formatDateTime,
  formatIdr,
  mediaUrl,
  parseApiDate,
  statusLabel,
} from '@/lib/format';
import { canReviewOrder, orderStage, orderSteps } from '@/lib/order-status';
import { getSecureItem, setSecureItem } from '@/lib/storage';
import { COLORS } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { useRefresh } from '@/lib/use-refresh';
import { ripple } from '@/lib/ripple';
import { cn } from '@/lib/utils';
import { Link, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
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
import { Alert as RNAlert, Pressable, ScrollView, View } from 'react-native';
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
  const { number, notice } = useLocalSearchParams<{ number: string; notice?: string }>();
  const { user } = useAuth();
  const router = useRouter();
  const reduceMotion = useReducedMotion();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  // True only until the first answer: later focus reloads keep the page on screen.
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busyAction, setBusyAction] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Bumped when the payment deadline passes, so the "renew" check is re-evaluated.
  const [now, setNow] = useState(() => Date.now());
  const [justPaid, setJustPaid] = useState(false);
  const [expandedTracking, setExpandedTracking] = useState<Record<number, boolean>>({});
  const [reviewed, setReviewed] = useState<Record<string, boolean>>({});
  const [reviewing, setReviewing] = useState<ReviewTarget | null>(null);
  const [reviewOpen, setReviewOpen] = useState(false);
  const wasPaid = useRef<boolean | null>(null);
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

  const applyOrder = useCallback((next: OrderDetail) => {
    const nowPaid = next.payment_status === 'paid';
    const before = wasPaid.current;
    wasPaid.current = nowPaid;
    // Polling returns the same payload most of the time: keep the old object so nothing re-renders.
    setOrder((current) =>
      current && JSON.stringify(current) === JSON.stringify(next) ? current : next
    );
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
  const unpaid = stage?.key === 'unpaid';
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
    unpaid && !paymentExpired
  );

  async function run(action: string, task: () => Promise<void>) {
    setBusyAction(action);
    setError(null);
    try {
      await task();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusyAction(null);
    }
  }

  async function syncPayment(quiet = false) {
    if (!order || busy) {
      return;
    }
    const orderNumber = order.number;
    const task = async () => {
      const res = await api<{ data: { payment_status: string; payment: OrderDetail['payment'] } }>(
        `/orders/${orderNumber}/sync-payment`,
        { method: 'POST' }
      );
      if (res.data.payment_status === 'paid') {
        await load();
        return;
      }
      setOrder((current) =>
        current
          ? { ...current, payment_status: res.data.payment_status, payment: res.data.payment }
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
    if (!order) {
      return;
    }
    await run('retry', async () => {
      const res = await api<{ data: { payment: OrderDetail['payment'] } }>(
        `/orders/${order.number}/retry-payment`,
        { method: 'POST' }
      );
      setNow(Date.now());
      setOrder((current) => (current ? { ...current, payment: res.data.payment } : current));
    });
  }

  async function trackShipment(shipmentId: number) {
    if (!order) {
      return;
    }
    await run(`track-${shipmentId}`, async () => {
      const res = await api<{ data: OrderDetail }>(
        `/orders/${order.number}/shipments/${shipmentId}/track`,
        { method: 'POST' }
      );
      applyOrder(res.data);
      toast.show({
        tone: 'success',
        message: 'Status pengiriman diperbarui',
        bottomOffset: BAR_OFFSET,
      });
    });
  }

  function confirmReceived() {
    if (!order) {
      return;
    }
    const orderNumber = order.number;
    RNAlert.alert(
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

  const style = STAGE_STYLE[stage.key] ?? STAGE_STYLE.processing;
  const hasAwb = order.shipments.some((shipment) =>
    Boolean(shipment.awb || shipment.tracking_number)
  );
  const canConfirm = order.payment_status === 'paid' && hasAwb && order.status !== 'completed';
  const reviewable = canReviewOrder(order);
  const firstSlug = order.items.find((item) => item.slug)?.slug;
  const showStickyBar =
    unpaid || canConfirm || (stage.key === 'completed' && Boolean(firstSlug)) || Boolean(error);

  return (
    <Screen title="Detail pesanan" surface>
      <ScrollView
        className="flex-1"
        contentContainerClassName="pb-4"
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
        </Section>

        {/* Payment */}
        {unpaid ? (
          <Section title="Instruksi pembayaran" icon={CreditCard}>
            <PaymentPanel
              payment={order.payment}
              onExpire={() => {
                // The cron may already have cancelled the order: look again before offering a retry.
                setNow(Date.now());
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
                            {event.date ? (
                              <Text className="text-[12px] text-muted-foreground">
                                {formatDateTime(event.date)}
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

                  {awb ? (
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
                    {item.quantity} × {formatIdr(item.unit_price)}
                  </Text>
                </View>
                <Text className="text-[13px] font-bold">
                  {formatIdr(item.unit_price * item.quantity)}
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
                  reviewed[slug] ? (
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
            value={formatIdr(order.totals?.subtotal ?? order.amount)}
          />
          {order.totals ? (
            <SummaryRow label="Ongkos kirim" value={formatIdr(order.totals.shipping)} />
          ) : null}
          {order.totals && order.totals.adjustment !== 0 ? (
            <SummaryRow
              label={order.totals.adjustment < 0 ? 'Diskon' : 'Biaya lainnya'}
              value={`${order.totals.adjustment < 0 ? '−' : ''}${formatIdr(Math.abs(order.totals.adjustment))}`}
              valueClassName={order.totals.adjustment < 0 ? 'text-om-success' : undefined}
            />
          ) : null}
          <View className="my-1.5 h-px bg-border" />
          <SummaryRow label="Total belanja" value={formatIdr(order.amount)} strong />
        </Section>

        {/* Order info */}
        <Section title="Info pesanan" icon={FileText}>
          <View className="flex-row items-center justify-between py-1">
            <Text className="text-[13px] text-muted-foreground">No. pesanan</Text>
            <View className="flex-row items-center gap-2">
              <Text selectable className="text-[13px] font-semibold">
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
          {unpaid ? (
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
