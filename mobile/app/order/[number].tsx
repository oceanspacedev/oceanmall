import { EmptyState } from '@/components/empty-state';
import { PaymentPanel } from '@/components/payment-panel';
import { Screen } from '@/components/screen';
import {
  CopyButton,
  LogoImage,
  Section,
  StatusPill,
  StickyBar,
  SummaryRow,
} from '@/components/shop';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type OrderDetail } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatIdr, mediaUrl, statusLabel } from '@/lib/format';
import { orderStage } from '@/lib/order-status';
import { cn } from '@/lib/utils';
import { Image } from 'expo-image';
import { Link, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import {
  CircleAlert,
  CircleCheckBig,
  Clock,
  CreditCard,
  FileText,
  MapPin,
  PackageOpen,
  Receipt,
  Store,
  Truck,
  User,
  XCircle,
  type LucideIcon,
} from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';

const CLOSED_STATUSES = ['cancelled', 'canceled', 'refunded', 'voided'];

const STAGE_STYLE: Record<string, { bg: string; icon: LucideIcon }> = {
  unpaid: { bg: 'bg-om-warning', icon: Clock },
  processing: { bg: 'bg-om-navy', icon: PackageOpen },
  shipped: { bg: 'bg-om-navy', icon: Truck },
  completed: { bg: 'bg-om-success', icon: CircleCheckBig },
  cancelled: { bg: 'bg-zinc-500', icon: XCircle },
};

/** A fresh charge is only useful when there is none, or the current one expired. */
function paymentNeedsRenewal(payment: OrderDetail['payment']): boolean {
  if (
    !payment ||
    (!payment.virtual_account_number && !payment.qris_string && !payment.payment_url)
  ) {
    return true;
  }
  if (!payment.expiry_date) {
    return false;
  }
  const expires = new Date(payment.expiry_date).getTime();
  return !Number.isNaN(expires) && expires < Date.now();
}

export default function OrderScreen() {
  const { number, notice } = useLocalSearchParams<{ number: string; notice?: string }>();
  const { user } = useAuth();
  const router = useRouter();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!number || !user) {
      setLoading(false);
      return;
    }
    try {
      const res = await api<{ data: OrderDetail }>(`/orders/${number}`);
      setOrder(res.data);
      setError(null);
    } catch (e) {
      setError(errorMessage(e, 'Pesanan tidak ditemukan'));
    } finally {
      setLoading(false);
    }
  }, [number, user]);

  useFocusEffect(
    useCallback(() => {
      setLoading(true);
      void load();
    }, [load])
  );

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await action();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  if (!user) {
    return (
      <Screen title="Detail pesanan">
        <EmptyState
          icon={User}
          message="Masuk untuk melihat pesanan ini."
          actionLabel="Masuk"
          onAction={() =>
            router.push({ pathname: '/login', params: { redirect: `/order/${number}` } })
          }
        />
      </Screen>
    );
  }

  if (loading) {
    return <Screen title="Detail pesanan" loading />;
  }

  if (!order) {
    return (
      <Screen title="Detail pesanan">
        <EmptyState icon={CircleAlert} message={error ?? 'Pesanan tidak ditemukan'} />
      </Screen>
    );
  }

  const stage = orderStage(order);
  const style = STAGE_STYLE[stage.key] ?? STAGE_STYLE.processing;
  const unpaid = order.payment_status !== 'paid' && !CLOSED_STATUSES.includes(order.status);
  const hasAwb = order.shipments.some((shipment) =>
    Boolean(shipment.awb || shipment.tracking_number)
  );
  const canConfirm = order.payment_status === 'paid' && hasAwb && order.status !== 'completed';
  const firstSlug = order.items.find((item) => item.slug)?.slug;

  async function syncPayment() {
    await run(async () => {
      const res = await api<{ data: { payment_status: string; payment: OrderDetail['payment'] } }>(
        `/orders/${order?.number}/sync-payment`,
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
      setError('Pembayaran belum diterima. Coba lagi beberapa saat setelah membayar.');
    });
  }

  return (
    <Screen title="Detail pesanan" surface>
      <ScrollView
        className="flex-1"
        contentContainerClassName="pb-4"
        refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
        {/* Status banner */}
        <View className={cn('flex-row items-center gap-3 px-4 py-4', style.bg)}>
          <View className="size-11 items-center justify-center rounded-full bg-white/20">
            <Icon as={style.icon} size={22} color="#fff" />
          </View>
          <View className="flex-1">
            <Text className="text-[16px] font-extrabold text-white">{stage.label}</Text>
            <Text className="mt-0.5 text-[12px] leading-4 text-white/90">{stage.hint}</Text>
          </View>
        </View>

        {notice && unpaid ? (
          <View className="mx-4 mt-3 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2">
            <Icon as={CircleAlert} size={14} className="text-om-sale" />
            <Text className="flex-1 text-[12px] text-om-sale">{notice}</Text>
          </View>
        ) : null}

        {/* Payment */}
        {unpaid ? (
          <Section title="Instruksi pembayaran" icon={CreditCard}>
            <PaymentPanel payment={order.payment} />
            {order.can_retry_payment && paymentNeedsRenewal(order.payment) ? (
              <Button
                variant="outline"
                disabled={busy}
                className="mt-3 rounded-sm border-om-navy"
                onPress={() =>
                  void run(async () => {
                    const res = await api<{ data: { payment: OrderDetail['payment'] } }>(
                      `/orders/${order.number}/retry-payment`,
                      { method: 'POST' }
                    );
                    setOrder((current) =>
                      current ? { ...current, payment: res.data.payment } : current
                    );
                  })
                }>
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
                      <Text className="text-[11px] text-muted-foreground">No. resi</Text>
                      <Text selectable className="text-[14px] font-bold">
                        {awb ?? 'Belum tersedia'}
                      </Text>
                    </View>
                    {awb ? <CopyButton value={awb} /> : null}
                  </View>

                  {history.length > 0 ? (
                    <View className="mt-3">
                      {history.slice(0, 6).map((event, eventIndex) => (
                        <View key={eventIndex} className="flex-row gap-3">
                          <View className="items-center">
                            <View
                              className={cn(
                                'mt-1 size-2.5 rounded-full',
                                eventIndex === 0 ? 'bg-om-navy' : 'bg-om-disabled'
                              )}
                            />
                            {eventIndex < Math.min(history.length, 6) - 1 ? (
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
                              <Text className="text-[11px] text-muted-foreground">
                                {event.date}
                              </Text>
                            ) : null}
                          </View>
                        </View>
                      ))}
                    </View>
                  ) : null}

                  {awb ? (
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={busy}
                      className="mt-1 self-start rounded-sm border-om-navy"
                      onPress={() =>
                        void run(async () => {
                          const res = await api<{ data: OrderDetail }>(
                            `/orders/${order.number}/shipments/${shipment.id}/track`,
                            { method: 'POST' }
                          );
                          setOrder(res.data);
                        })
                      }>
                      <Text className="text-[12px] font-bold text-om-navy">
                        Perbarui status lacak
                      </Text>
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
                <Image
                  source={{ uri: mediaUrl(item.thumbnail) }}
                  style={{ width: 60, height: 60, borderRadius: 4, backgroundColor: '#f4f4f5' }}
                  contentFit="cover"
                />
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
            return item.slug ? (
              <Link key={`${item.name}-${index}`} href={`/product/${item.slug}`} asChild>
                <Pressable>{row}</Pressable>
              </Link>
            ) : (
              <View key={`${item.name}-${index}`}>{row}</View>
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
              <CopyButton value={order.number} />
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
      </ScrollView>

      {unpaid || canConfirm || (stage.key === 'completed' && firstSlug) || error ? (
        <StickyBar>
          {error ? (
            <View className="mb-2.5 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2">
              <Icon as={CircleAlert} size={15} className="text-om-sale" />
              <Text className="flex-1 text-[12px] text-om-sale">{error}</Text>
            </View>
          ) : null}
          {unpaid ? (
            <Button disabled={busy} onPress={() => void syncPayment()} className="h-11 rounded-sm">
              <Text className="font-bold">
                {busy ? 'Mengecek...' : 'Saya sudah bayar, cek status'}
              </Text>
            </Button>
          ) : canConfirm ? (
            <Button
              disabled={busy}
              className="h-11 rounded-sm"
              onPress={() =>
                void run(async () => {
                  const res = await api<{ data: OrderDetail }>(
                    `/orders/${order.number}/confirm-received`,
                    {
                      method: 'POST',
                    }
                  );
                  setOrder(res.data);
                })
              }>
              <Text className="font-bold">Pesanan diterima</Text>
            </Button>
          ) : stage.key === 'completed' && firstSlug ? (
            <Button
              onPress={() => router.push(`/product/${firstSlug}`)}
              className="h-11 rounded-sm">
              <Text className="font-bold">Beli lagi</Text>
            </Button>
          ) : null}
        </StickyBar>
      ) : null}
    </Screen>
  );
}
