import { EmptyState } from '@/components/empty-state';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type AppNotification } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { useFocusEffect, useRouter } from 'expo-router';
import { Bell, CircleAlert, User } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView } from 'react-native';

export default function NotificationsScreen() {
  const { user } = useAuth();
  const router = useRouter();
  const [items, setItems] = useState<AppNotification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!user) {
      setItems([]);
      setLoading(false);
      return;
    }
    try {
      const res = await api<{ data: AppNotification[] }>('/notifications');
      setItems(res.data ?? []);
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

  async function markAll() {
    try {
      await api('/notifications/read-all', { method: 'POST' });
      setItems((current) => current.map((row) => ({ ...row, read_at: row.read_at ?? new Date().toISOString() })));
    } catch (e) {
      setError(errorMessage(e, 'Gagal menandai dibaca'));
    }
  }

  async function open(item: AppNotification) {
    if (!item.read_at) {
      try {
        await api(`/notifications/${item.id}/read`, { method: 'POST' });
        setItems((current) =>
          current.map((row) => (row.id === item.id ? { ...row, read_at: new Date().toISOString() } : row))
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
          onAction={() => router.push({ pathname: '/login', params: { redirect: '/notifications' } })}
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
        <EmptyState icon={CircleAlert} message={error} actionLabel="Coba lagi" onAction={() => void load()} />
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
    <Screen title="Notifikasi">
      <ScrollView
        className="flex-1"
        contentContainerClassName="px-3 pb-10 pt-3"
        refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}>
        {error ? <Text className="mb-3 text-[12px] text-destructive">{error}</Text> : null}
        {unread ? (
          <Pressable onPress={() => void markAll()} className="mb-2 self-end">
            <Text className="text-[12px] font-semibold text-om-navy">Tandai semua dibaca</Text>
          </Pressable>
        ) : null}

        {items.map((item) => (
          <Pressable
            key={item.id}
            onPress={() => void open(item)}
            className="mb-2 border-b border-border py-3">
            <Text className={`text-[13px] ${item.read_at ? 'font-medium' : 'font-bold'}`}>{item.title}</Text>
            {item.body ? (
              <Text className="mt-0.5 text-[12px] leading-5 text-muted-foreground">{item.body}</Text>
            ) : null}
            <Text className="mt-1 text-[11px] text-muted-foreground">{formatDate(item.created_at)}</Text>
          </Pressable>
        ))}
      </ScrollView>
    </Screen>
  );
}
