import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { LoadMore } from '@/components/load-more';
import { LoginPrompt } from '@/components/login-prompt';
import { ProductImage } from '@/components/product-image';
import { Screen } from '@/components/screen';
import { StatusPill } from '@/components/shop';
import { OrderCardSkeleton } from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type OrderSummary } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatIdr, mediaUrl } from '@/lib/format';
import {
  awaitsReceiptConfirmation,
  ORDER_TABS,
  orderStage,
  type OrderTab,
} from '@/lib/order-status';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { ripple } from '@/lib/ripple';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { CircleAlert, PackageOpen, ReceiptText, Store } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { FlatList, Platform, Pressable, ScrollView, View, type ListRenderItem } from 'react-native';

type Counts = Partial<Record<OrderTab, number>>;
type TabKey = OrderTab | 'all';

const orderKey = (order: OrderSummary) => order.number;

function isTabKey(value: unknown): value is OrderTab {
  return ORDER_TABS.some((tab) => tab.key === value && tab.key !== 'all');
}

export default function OrdersScreen() {
  const { user } = useAuth();
  const router = useRouter();
  const params = useLocalSearchParams<{ status?: string }>();
  const [tab, setTab] = useState<TabKey>(isTabKey(params.status) ? params.status : 'all');
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [counts, setCounts] = useState<Counts>({});
  const [meta, setMeta] = useState<PageMeta | null>(null);
  // True on the first load and when the tab changes: a focus reload keeps the list on screen.
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  // A failed load or refresh, and (separately) a failed next page: that one keeps the list.
  const [error, setError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const request = useRef(0);
  const tabsRef = useRef<ScrollView>(null);
  const tabX = useRef<Partial<Record<TabKey, number>>>({});

  const path = tab === 'all' ? '/orders' : `/orders?status=${tab}`;
  const userId = user?.id ?? null;

  const load = useCallback(async () => {
    if (userId === null) {
      setOrders([]);
      setLoading(false);
      return;
    }
    const id = ++request.current;
    try {
      const res = await api<{ data: OrderSummary[]; meta?: PageMeta & { counts?: Counts } }>(path);
      if (id !== request.current) {
        return;
      }
      setOrders(res.data ?? []);
      setMeta(res.meta ?? null);
      setCounts(res.meta?.counts ?? {});
      setError(null);
      setLoadError(null);
    } catch (e) {
      if (id === request.current) {
        setError(errorMessage(e, 'Gagal memuat pesanan'));
      }
    } finally {
      if (id === request.current) {
        setLoading(false);
      }
    }
  }, [path, userId]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load])
  );

  const { refreshing, onRefresh } = useRefresh(load);

  function selectTab(next: TabKey) {
    if (next === tab) {
      return;
    }
    // A different tab is a different list: show placeholders, not the previous tab's orders.
    setTab(next);
    setOrders([]);
    setMeta(null);
    setError(null);
    setLoadError(null);
    setLoading(true);
  }

  // A link that opens Pesanan on a given tab (the Akun shortcuts) while it is already mounted.
  useEffect(() => {
    if (isTabKey(params.status)) {
      selectTab(params.status);
    }
  }, [params.status]);

  // Keep the selected tab in view on the horizontal strip.
  useEffect(() => {
    const x = tabX.current[tab];
    if (x !== undefined) {
      tabsRef.current?.scrollTo({ x: Math.max(0, x - 24), animated: true });
    }
  }, [tab]);

  async function loadMore(force = false) {
    if (!meta || !hasMorePages(meta) || loadingMore || (loadError && !force)) {
      return;
    }
    const id = request.current;
    setLoadingMore(true);
    setLoadError(null);
    try {
      const res = await api<{ data: OrderSummary[]; meta?: PageMeta }>(
        withPage(path, meta.current_page + 1)
      );
      if (id !== request.current) {
        return;
      }
      setOrders((current) => appendUnique(current, res.data ?? []));
      setMeta(res.meta ?? null);
    } catch (e) {
      if (id === request.current) {
        setLoadError(errorMessage(e, 'Gagal memuat pesanan berikutnya'));
      }
    } finally {
      setLoadingMore(false);
    }
  }

  const renderOrder = useCallback<ListRenderItem<OrderSummary>>(
    ({ item }) => (
      <OrderCard
        order={item}
        onOpen={() => router.push({ pathname: '/order/[number]', params: { number: item.number } })}
      />
    ),
    [router]
  );

  function goShopping() {
    if (router.canDismiss()) {
      router.dismissTo('/(tabs)');
    } else {
      router.replace('/(tabs)');
    }
  }

  if (!user) {
    return (
      <Screen title="Pesanan saya">
        <LoginPrompt
          icon={PackageOpen}
          title="Pesananmu ada di sini"
          body="Masuk untuk melihat status pesanan, nomor resi, dan riwayat belanja."
          redirect={isTabKey(params.status) ? `/orders?status=${params.status}` : '/orders'}
        />
      </Screen>
    );
  }

  return (
    <Screen title="Pesanan saya" surface>
      <View className="border-b border-border bg-white">
        <ScrollView
          ref={tabsRef}
          horizontal
          scrollsToTop={false}
          accessibilityRole="tablist"
          showsHorizontalScrollIndicator={false}
          contentContainerClassName="px-2">
          {ORDER_TABS.map((item) => {
            const active = item.key === tab;
            const count = item.key === 'all' ? 0 : (counts[item.key] ?? 0);
            return (
              <Pressable
                key={item.key}
                accessibilityRole="tab"
                accessibilityLabel={count > 0 ? `${item.label}, ${count} pesanan` : item.label}
                accessibilityState={{ selected: active }}
                onLayout={(event) => {
                  const x = event.nativeEvent.layout.x;
                  tabX.current[item.key] = x;
                  if (active) {
                    tabsRef.current?.scrollTo({ x: Math.max(0, x - 24), animated: false });
                  }
                }}
                onPress={() => selectTab(item.key)}
                android_ripple={ripple.row}
                className={cn(
                  'min-h-11 flex-row items-center gap-1 border-b-2 px-3',
                  active ? 'border-om-navy' : 'border-transparent'
                )}>
                <Text
                  maxFontSizeMultiplier={1.2}
                  className={cn(
                    'text-[13px]',
                    active ? 'font-bold text-om-navy' : 'text-muted-foreground'
                  )}>
                  {item.label}
                </Text>
                {count > 0 ? (
                  <View className="min-w-[16px] items-center rounded-full bg-om-sale px-1">
                    <Text maxFontSizeMultiplier={1.2} className="text-[10px] font-bold text-white">
                      {count}
                    </Text>
                  </View>
                ) : null}
              </Pressable>
            );
          })}
        </ScrollView>
      </View>

      {loading ? (
        <View accessibilityLiveRegion="polite" className="flex-1 overflow-hidden">
          <Text className="absolute h-px w-px opacity-0">Memuat…</Text>
          <OrderCardSkeleton />
          <OrderCardSkeleton />
        </View>
      ) : error && orders.length === 0 ? (
        <EmptyState
          tone="error"
          icon={CircleAlert}
          title="Pesanan belum bisa dimuat"
          message={error}
          actionLabel="Coba lagi"
          onAction={() => {
            setLoading(true);
            void load();
          }}
          className="bg-om-surface"
        />
      ) : orders.length === 0 ? (
        tab === 'all' ? (
          <EmptyState
            icon={ReceiptText}
            title="Belum ada pesanan"
            message="Pesanan yang kamu buat akan muncul di sini."
            actionLabel="Mulai belanja"
            onAction={goShopping}
            className="bg-om-surface"
          />
        ) : (
          <EmptyState
            icon={ReceiptText}
            title="Tidak ada pesanan di sini"
            message="Pesanan dengan status ini akan muncul di sini."
            actionLabel="Lihat semua pesanan"
            onAction={() => selectTab('all')}
            className="bg-om-surface"
          />
        )
      ) : (
        <FlatList
          data={orders}
          keyExtractor={orderKey}
          renderItem={renderOrder}
          className="flex-1"
          contentContainerStyle={{ paddingBottom: 32 }}
          refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
          ListHeaderComponent={
            error ? <ErrorBanner message={error} onRetry={() => void load()} /> : null
          }
          ListFooterComponent={
            <LoadMore
              hasMore={hasMorePages(meta)}
              loading={loadingMore}
              count={orders.length}
              error={loadError}
              onRetry={() => void loadMore(true)}
              endLabel="Semua pesanan sudah ditampilkan"
              showEnd={(meta?.last_page ?? 1) > 1}
            />
          }
          onEndReached={() => void loadMore()}
          onEndReachedThreshold={0.6}
          // Android only: detaching off-screen rows saves memory, but on iOS it flashes blank rows.
          removeClippedSubviews={Platform.OS === 'android'}
          initialNumToRender={6}
          maxToRenderPerBatch={6}
          windowSize={7}
        />
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
      : awaitsReceiptConfirmation(order)
        ? 'Konfirmasi penerimaan'
        : stage.key === 'processing' || stage.key === 'shipped'
          ? 'Lacak paket'
          : 'Lihat detail';
  const summary = [
    `Pesanan ${order.number}`,
    stage.label,
    order.first_item
      ? `${order.first_item.name}${more > 0 ? `, dan ${more} produk lainnya` : ''}`
      : null,
    `total ${formatIdr(order.amount)}`,
  ]
    .filter((part) => Boolean(part))
    .join(', ');

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={summary}
      accessibilityHint={cta}
      onPress={onOpen}
      android_ripple={ripple.row}
      // The card sits on the gray page, so a gray pressed state would vanish: use the accent tint.
      className="mt-2 bg-white px-4 py-3 active:bg-accent">
      <View className="flex-row items-center gap-2">
        <Icon as={Store} size={14} className="text-om-navy" />
        <Text className="flex-1 text-[13px] font-bold">OceanMall Official</Text>
        <StatusPill label={stage.label} tone={stage.tone} />
      </View>
      <Text className="mt-1 text-[12px] text-muted-foreground">
        {formatDate(order.created_at)} · {order.number}
      </Text>

      {order.first_item ? (
        <View className="mt-3 flex-row gap-3">
          <ProductImage
            uri={mediaUrl(order.first_item.thumbnail)}
            width={64}
            height={64}
            radius={6}
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
          <Text className="text-[12px] text-muted-foreground">Total belanja</Text>
          <Text className="text-[15px] font-extrabold text-foreground">
            {formatIdr(order.amount)}
          </Text>
        </View>
        <Button
          size="sm"
          variant={stage.key === 'unpaid' ? 'default' : 'outline'}
          onPress={onOpen}
          importantForAccessibility="no-hide-descendants"
          className={cn('min-h-11 rounded-sm px-4', stage.key !== 'unpaid' && 'border-om-navy')}>
          <Text className={cn('text-[12px] font-bold', stage.key !== 'unpaid' && 'text-om-navy')}>
            {cta}
          </Text>
        </Button>
      </View>
    </Pressable>
  );
}
