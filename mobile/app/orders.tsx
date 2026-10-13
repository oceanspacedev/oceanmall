import { EmptyState } from '@/components/empty-state';
import { LoadMore } from '@/components/load-more';
import { Screen } from '@/components/screen';
import { StatusPill } from '@/components/shop';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type OrderSummary } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatIdr, mediaUrl } from '@/lib/format';
import { ORDER_TABS, orderStage, type OrderTab } from '@/lib/order-status';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { cn } from '@/lib/utils';
import { Image } from 'expo-image';
import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { CircleAlert, Package, ShoppingBag, Store, User } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';

type Counts = Partial<Record<OrderTab, number>>;

export default function OrdersScreen() {
  const { user } = useAuth();
  const router = useRouter();
  const params = useLocalSearchParams<{ status?: string }>();
  const initialTab = ORDER_TABS.some((tab) => tab.key === params.status)
    ? (params.status as OrderTab)
    : 'all';
  const [tab, setTab] = useState<OrderTab | 'all'>(initialTab);
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [counts, setCounts] = useState<Counts>({});
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const path = tab === 'all' ? '/orders' : `/orders?status=${tab}`;

  const load = useCallback(async () => {
    if (!user) {
      setOrders([]);
      setLoading(false);
      return;
    }
    setError(null);
    try {
      const res = await api<{ data: OrderSummary[]; meta?: PageMeta & { counts?: Counts } }>(path);
      setOrders(res.data ?? []);
      setMeta(res.meta ?? null);
      setCounts(res.meta?.counts ?? {});
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat pesanan'));
    } finally {
      setLoading(false);
    }
  }, [path, user]);

  useFocusEffect(
    useCallback(() => {
      setLoading(true);
      void load();
    }, [load])
  );

  async function loadMore() {
    if (!meta || !hasMorePages(meta) || loadingMore) {
      return;
    }
    setLoadingMore(true);
    try {
      const res = await api<{ data: OrderSummary[]; meta?: PageMeta }>(
        withPage(path, meta.current_page + 1)
      );
      setOrders((current) => appendUnique(current, res.data ?? []));
      setMeta(res.meta ?? null);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat halaman berikutnya'));
    } finally {
      setLoadingMore(false);
    }
  }

  if (!user) {
    return (
      <Screen title="Pesanan saya">
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
    <Screen title="Pesanan saya" surface>
      <View className="border-b border-border bg-white">
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerClassName="px-2">
          {ORDER_TABS.map((item) => {
            const active = item.key === tab;
            const count = item.key === 'all' ? 0 : (counts[item.key] ?? 0);
            return (
              <Pressable
                key={item.key}
                onPress={() => {
                  if (item.key !== tab) {
                    setTab(item.key);
                    setLoading(true);
                  }
                }}
                className={cn(
                  'flex-row items-center gap-1 border-b-2 px-3 py-3',
                  active ? 'border-om-navy' : 'border-transparent'
                )}>
                <Text
                  className={cn(
                    'text-[13px]',
                    active ? 'font-bold text-om-navy' : 'text-muted-foreground'
                  )}>
                  {item.label}
                </Text>
                {count > 0 ? (
                  <View className="min-w-[16px] items-center rounded-full bg-om-sale px-1">
                    <Text className="text-[9px] font-bold text-white">{count}</Text>
                  </View>
                ) : null}
              </Pressable>
            );
          })}
        </ScrollView>
      </View>

      {loading ? (
        <View className="gap-2 pt-2">
          <Skeleton className="h-40 rounded-none" />
          <Skeleton className="h-40 rounded-none" />
        </View>
      ) : error && orders.length === 0 ? (
        <EmptyState
          icon={CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      ) : orders.length === 0 ? (
        <View className="flex-1 items-center justify-center px-10">
          <View className="mb-4 size-20 items-center justify-center rounded-full bg-om-navy-soft">
            <Icon as={Package} size={34} className="text-om-navy" />
          </View>
          <Text className="text-center text-[15px] font-bold">Belum ada pesanan di sini</Text>
          <Text className="mt-1 text-center text-[13px] text-muted-foreground">
            Pesanan yang kamu buat akan muncul di halaman ini.
          </Text>
          <Button className="mt-5 rounded-sm px-6" onPress={() => router.replace('/(tabs)')}>
            <Icon as={ShoppingBag} size={15} color="#fff" />
            <Text className="font-bold">Mulai belanja</Text>
          </Button>
        </View>
      ) : (
        <ScrollView
          className="flex-1"
          contentContainerClassName="pb-8"
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
            <OrderCard
              key={order.number}
              order={order}
              onOpen={() => router.push(`/order/${order.number}`)}
            />
          ))}
          <LoadMore
            visible={hasMorePages(meta)}
            loading={loadingMore}
            onPress={() => void loadMore()}
          />
        </ScrollView>
      )}
    </Screen>
  );
}

function OrderCard({ order, onOpen }: { order: OrderSummary; onOpen: () => void }) {
  const stage = orderStage(order);
  const more = Math.max(0, (order.line_count ?? 1) - 1);
  const cta =
    stage.key === 'unpaid'
      ? 'Bayar sekarang'
      : stage.key === 'shipped'
        ? 'Lacak paket'
        : stage.key === 'completed'
          ? 'Beri ulasan'
          : 'Lihat detail';

  return (
    <Pressable onPress={onOpen} className="mt-2 bg-white px-4 py-3 active:opacity-90">
      <View className="flex-row items-center gap-2">
        <Icon as={Store} size={14} className="text-om-navy" />
        <Text className="flex-1 text-[13px] font-bold">OceanMall Official</Text>
        <StatusPill label={stage.label} tone={stage.tone} />
      </View>
      <Text className="mt-1 text-[11px] text-muted-foreground">
        {formatDate(order.created_at)} · {order.number}
      </Text>

      {order.first_item ? (
        <View className="mt-3 flex-row gap-3">
          <Image
            source={{ uri: mediaUrl(order.first_item.thumbnail) }}
            style={{ width: 64, height: 64, borderRadius: 4, backgroundColor: '#f4f4f5' }}
            contentFit="cover"
          />
          <View className="min-w-0 flex-1">
            <Text className="text-[13px] leading-5" numberOfLines={2}>
              {order.first_item.name}
            </Text>
            <Text className="mt-0.5 text-[12px] text-muted-foreground">
              {order.first_item.quantity} barang{more > 0 ? ` · +${more} produk lainnya` : ''}
            </Text>
          </View>
        </View>
      ) : null}

      <View className="mt-3 flex-row items-center justify-between border-t border-border pt-3">
        <View>
          <Text className="text-[11px] text-muted-foreground">Total belanja</Text>
          <Text className="text-[15px] font-extrabold text-foreground">
            {formatIdr(order.amount)}
          </Text>
        </View>
        <Button
          size="sm"
          variant={stage.key === 'unpaid' ? 'default' : 'outline'}
          onPress={onOpen}
          className={cn('h-9 rounded-sm px-4', stage.key !== 'unpaid' && 'border-om-navy')}>
          <Text className={cn('text-[12px] font-bold', stage.key !== 'unpaid' && 'text-om-navy')}>
            {cta}
          </Text>
        </Button>
      </View>
    </Pressable>
  );
}
