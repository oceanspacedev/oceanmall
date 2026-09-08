import { EmptyState } from '@/components/empty-state';
import { PaymentPanel } from '@/components/payment-panel';
import { PillButton } from '@/components/pill-button';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type OrderDetail } from '@/lib/api';
import { formatDate, formatIdr, statusLabel } from '@/lib/format';
import { useFocusEffect, useLocalSearchParams } from 'expo-router';
import { CircleAlert } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { RefreshControl, ScrollView, View } from 'react-native';

export default function OrderScreen() {
  const { number } = useLocalSearchParams<{ number: string }>();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!number) {
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
  }, [number]);

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

  if (loading) {
    return <Screen title="Pesanan" loading />;
  }

  if (!order) {
    return (
      <Screen title="Pesanan">
        <EmptyState icon={CircleAlert} message={error ?? 'Pesanan tidak ditemukan'} />
      </Screen>
    );
  }

  const canConfirm =
    order.payment_status === 'paid' &&
    order.shipments.some((shipment) => Boolean(shipment.awb || shipment.tracking_number));

  return (
    <Screen title={order.number}>
      <ScrollView
        className="flex-1"
        contentContainerClassName="px-3 pb-8 pt-3"
        refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
        <Text className="text-[11px] text-muted-foreground">{formatDate(order.created_at)}</Text>
        <Text className="mt-1 text-[12px] text-muted-foreground">
          {statusLabel(order.payment_status)} · {statusLabel(order.status)}
          {order.shipping_status ? ` · ${statusLabel(order.shipping_status)}` : ''}
        </Text>
        <Text className="mt-1 text-[16px] font-extrabold text-om-navy">{formatIdr(order.amount)}</Text>

        {error ? <Text className="mt-3 text-[12px] text-destructive">{error}</Text> : null}

        <Text className="mt-5 mb-1.5 text-[13px] font-bold text-om-navy">Barang</Text>
        {order.items.map((item, index) => (
          <View key={`${item.name}-${index}`} className="flex-row justify-between gap-3 py-1">
            <View className="min-w-0 flex-1">
              <Text className="text-[13px]">{item.name}</Text>
              <Text className="text-[12px] text-muted-foreground">
                × {item.quantity} · {formatIdr(item.unit_price)}
              </Text>
            </View>
            <Text className="text-[13px] font-medium">{formatIdr(item.unit_price * item.quantity)}</Text>
          </View>
        ))}

        {order.payment_status !== 'paid' ? (
          <View className="mt-5">
            <PaymentPanel payment={order.payment} />
            <View className="mt-3 gap-2">
              <PillButton
                outline
                disabled={busy}
                label="Cek status bayar"
                onPress={() =>
                  void run(async () => {
                    const res = await api<{ data: { payment_status: string; payment: OrderDetail['payment'] } }>(
                      `/orders/${order.number}/sync-payment`,
                      { method: 'POST' }
                    );
                    setOrder((current) =>
                      current
                        ? {
                            ...current,
                            payment_status: res.data.payment_status,
                            payment: res.data.payment,
                          }
                        : current
                    );
                  })
                }
              />
              {order.can_retry_payment ? (
                <PillButton
                  disabled={busy}
                  label="Buat pembayaran baru"
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
                  }
                />
              ) : null}
            </View>
          </View>
        ) : null}

        <Text className="mt-5 mb-1.5 text-[13px] font-bold text-om-navy">Pengiriman</Text>
        {order.shipments.length === 0 ? (
          <Text className="text-[13px] text-muted-foreground">
            Resi belum terbit. Muncul setelah gudang memproses.
          </Text>
        ) : (
          order.shipments.map((shipment) => (
            <View key={shipment.id} className="mb-3 rounded-lg border border-border bg-white p-3">
              <Text className="text-[13px] font-medium">
                {shipment.carrier ?? 'Kurir'} {shipment.service ? `· ${shipment.service}` : ''}
              </Text>
              <Text className="mt-0.5 text-[12px] text-muted-foreground">{statusLabel(shipment.status)}</Text>
              <Text selectable className="mt-1 text-[13px]">
                {shipment.awb || shipment.tracking_number || 'AWB belum ada'}
              </Text>
              {(shipment.tracking_history ?? []).slice(0, 5).map((event, index) => (
                <Text key={index} className="mt-0.5 text-[11px] text-muted-foreground">
                  {event.date ? `${event.date} · ` : ''}
                  {event.description}
                </Text>
              ))}
              <View className="mt-2">
                <PillButton
                  outline
                  disabled={busy}
                  label="Lacak"
                  onPress={() =>
                    void run(async () => {
                      const res = await api<{ data: OrderDetail }>(
                        `/orders/${order.number}/shipments/${shipment.id}/track`,
                        { method: 'POST' }
                      );
                      setOrder(res.data);
                    })
                  }
                />
              </View>
            </View>
          ))
        )}

        {canConfirm && order.status !== 'completed' ? (
          <View className="mt-2">
            <PillButton
              disabled={busy}
              label="Konfirmasi barang diterima"
              onPress={() =>
                void run(async () => {
                  const res = await api<{ data: OrderDetail }>(`/orders/${order.number}/confirm-received`, {
                    method: 'POST',
                  });
                  setOrder(res.data);
                })
              }
            />
          </View>
        ) : null}
      </ScrollView>
    </Screen>
  );
}
