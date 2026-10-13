import { LoadMore } from '@/components/load-more';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { EmptyState } from '@/components/empty-state';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type AppNotification } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { useFocusEffect, useRouter } from 'expo-router';
import {
  Bell,
  CircleAlert,
  CircleCheckBig,
  PackageCheck,
  Truck,
  User,
  Wallet,
  XCircle,
  type LucideIcon,
} from 'lucide-react-native';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';

export default function NotificationsScreen() {
  const { user } = useAuth();
  const router = useRouter();
  const [items, setItems] = useState<AppNotification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);

  const load = useCallback(async () => {
    if (!user) {
      setItems([]);
      setLoading(false);
      return;
    }
    try {
      const res = await api<{ data: AppNotification[]; meta?: PageMeta }>('/notifications');
      setItems(res.data ?? []);
      setMeta(res.meta ?? null);
      setError(null);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat notifikasi'));
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

  async function loadMore() {
    if (!meta || !hasMorePages(meta) || loadingMore) {
      return;
    }
    setLoadingMore(true);
    try {
      const res = await api<{ data: AppNotification[]; meta?: PageMeta }>(
        withPage('/notifications', meta.current_page + 1)
      );
      setItems((current) => appendUnique(current, res.data ?? []));
      setMeta(res.meta ?? null);
    } catch (e) {
      setError(errorMessage(e, 'Gagal memuat halaman berikutnya'));
    } finally {
      setLoadingMore(false);
    }
  }

  async function markAll() {
    try {
      await api('/notifications/read-all', { method: 'POST' });
      setItems((current) =>
        current.map((row) => ({ ...row, read_at: row.read_at ?? new Date().toISOString() }))
      );
    } catch (e) {
      setError(errorMessage(e, 'Gagal menandai dibaca'));
    }
  }

  async function open(item: AppNotification) {
    if (!item.read_at) {
      try {
        await api(`/notifications/${item.id}/read`, { method: 'POST' });
        setItems((current) =>
          current.map((row) =>
            row.id === item.id ? { ...row, read_at: new Date().toISOString() } : row
          )
        );
      } catch {
        // still navigate
      }
    }
    if (item.order_number) {
      router.push(`/order/${item.order_number}`);
    }
  }

  if (!user) {
    return (
      <Screen title="Notifikasi">
        <EmptyState
          icon={User}
          message="Masuk untuk melihat notifikasi."
          actionLabel="Masuk"
          onAction={() =>
            router.push({ pathname: '/login', params: { redirect: '/notifications' } })
          }
        />
      </Screen>
    );
  }

  if (loading) {
    return <Screen title="Notifikasi" loading />;
  }

  const unread = items.some((item) => !item.read_at);

  if (error && items.length === 0) {
    return (
      <Screen title="Notifikasi">
        <EmptyState
          icon={CircleAlert}
          message={error}
          actionLabel="Coba lagi"
          onAction={() => void load()}
        />
      </Screen>
    );
  }

  if (items.length === 0) {
    return (
      <Screen title="Notifikasi">
        <EmptyState icon={Bell} message="Belum ada notifikasi." />
      </Screen>
    );
  }

  return (
    <Screen
      title="Notifikasi"
      surface
      right={
        unread ? (
          <Pressable onPress={() => void markAll()} hitSlop={8}>
            <Text className="text-[12px] font-semibold text-white">Baca semua</Text>
          </Pressable>
        ) : undefined
      }>
      <ScrollView
        className="flex-1"
        contentContainerClassName="pb-10"
        refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
        {error ? <Text className="mx-4 mt-3 text-[12px] text-destructive">{error}</Text> : null}
        <View className="mt-2 bg-white">
          {items.map((item, index) => {
            const kind = NOTIFICATION_KIND[item.type ?? ''] ?? NOTIFICATION_KIND.default;
            return (
              <Pressable
                key={item.id}
                onPress={() => void open(item)}
                className={cn(
                  'flex-row gap-3 px-4 py-3.5',
                  !item.read_at && 'bg-om-navy-soft',
                  index < items.length - 1 && 'border-b border-border'
                )}>
                <View className={cn('size-10 items-center justify-center rounded-full', kind.bg)}>
                  <Icon as={kind.icon} size={18} className={kind.fg} />
                </View>
                <View className="min-w-0 flex-1">
                  <View className="flex-row items-start gap-2">
                    <Text
                      className={cn(
                        'flex-1 text-[14px] leading-5',
                        item.read_at ? 'font-medium' : 'font-bold'
                      )}>
                      {item.title}
                    </Text>
                    {!item.read_at ? (
                      <View className="mt-1.5 size-2 rounded-full bg-om-sale" />
                    ) : null}
                  </View>
                  {item.body ? (
                    <Text className="mt-0.5 text-[13px] leading-5 text-muted-foreground">
                      {item.body}
                    </Text>
                  ) : null}
                  <Text className="mt-1 text-[11px] text-muted-foreground">
                    {formatDate(item.created_at)}
                  </Text>
                </View>
              </Pressable>
            );
          })}
        </View>
        <LoadMore
          visible={hasMorePages(meta)}
          loading={loadingMore}
          onPress={() => void loadMore()}
        />
      </ScrollView>
    </Screen>
  );
}

const NOTIFICATION_KIND: Record<string, { icon: LucideIcon; bg: string; fg: string }> = {
  awaiting_payment: { icon: Wallet, bg: 'bg-om-warning-soft', fg: 'text-om-warning' },
  paid: { icon: CircleCheckBig, bg: 'bg-om-success-soft', fg: 'text-om-success' },
  shipped: { icon: Truck, bg: 'bg-om-navy-soft', fg: 'text-om-navy' },
  delivered: { icon: PackageCheck, bg: 'bg-om-success-soft', fg: 'text-om-success' },
  cancelled: { icon: XCircle, bg: 'bg-om-sale-soft', fg: 'text-om-sale' },
  default: { icon: Bell, bg: 'bg-om-navy-soft', fg: 'text-om-navy' },
};
