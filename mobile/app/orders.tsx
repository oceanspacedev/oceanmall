import { EmptyState } from '@/components/empty-state';
import { Screen } from '@/components/screen';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type OrderSummary } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatIdr, statusLabel } from '@/lib/format';
import { useFocusEffect, useRouter } from 'expo-router';
import { CircleAlert, Package, User } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';

export default function OrdersScreen() {
  const { user } = useAuth();
  const router = useRouter();
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    if (!user) {
      setOrders([]);
      setLoading(false);
      return;
    }
    setError(null);
    try {
      const res = await api<{ data: OrderSummary[] }>('/orders');
      setOrders(res.data ?? []);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat pesanan'));
    } finally {
      setLoading(false);
    }
  }, [user]);

  useFocusEffect(
    useCallback(() => {
      setLoading(true);
      void load();
    }, [load])
  );

  if (!user) {
    return (
      <Screen title="Pesanan">
        <EmptyState
          icon={User}
          message="Masuk untuk melihat pesanan."
          actionLabel="Masuk"
          onAction={() => router.push({ pathname: '/login', params: { redirect: '/orders' } })}
        />
      </Screen>
    );
  }

  return (
    <Screen title="Pesanan">
      {loading ? (
        <View className="px-3 pt-3">
          <Skeleton className="mb-2.5 h-24 rounded-lg" />
          <Skeleton className="h-24 rounded-lg" />
        </View>
      ) : error && orders.length === 0 ? (
        <EmptyState icon={CircleAlert} message={error} actionLabel="Coba lagi" onAction={() => void load()} />
      ) : orders.length === 0 ? (
        <EmptyState
          icon={Package}
          message="Belum ada pesanan."
          actionLabel="Belanja sekarang"
          onAction={() => router.replace('/(tabs)')}
        />
      ) : (
        <ScrollView
          className="flex-1"
          contentContainerClassName="px-3 pb-8 pt-3"
          refreshControl={
            <RefreshControl
              refreshing={refreshing}
              onRefresh={() => {
                setRefreshing(true);
                void load().finally(() => setRefreshing(false));
              }}
            />
          }>
          {orders.map((order) => (
            <Pressable key={order.number} onPress={() => router.push(`/order/${order.number}`)}>
              <Card className="mb-2.5">
                <CardContent>
                  <View className="flex-row items-start justify-between gap-3">
                    <View className="flex-1">
                      <Text className="text-[13px] font-bold">{order.number}</Text>
                      <Text variant="muted" className="mt-0.5 text-[11px]">
                        {formatDate(order.created_at)}
                      </Text>
                    </View>
                    <Badge variant={order.payment_status === 'paid' ? 'default' : 'secondary'}>
                      <Text>{statusLabel(order.payment_status)}</Text>
                    </Badge>
                  </View>
                  <Text className="mt-1.5 text-[14px] font-extrabold text-om-navy">{formatIdr(order.amount)}</Text>
                </CardContent>
              </Card>
            </Pressable>
          ))}
        </ScrollView>
      )}
    </Screen>
  );
}
