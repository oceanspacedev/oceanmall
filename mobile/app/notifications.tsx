import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { EmptyState } from '@/components/empty-state';
import { ErrorBanner } from '@/components/error-banner';
import { LoadMore } from '@/components/load-more';
import { LoginPrompt } from '@/components/login-prompt';
import { Screen } from '@/components/screen';
import { NotificationRowSkeleton } from '@/components/skeletons';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, errorMessage, type AppNotification } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatRelative, parseApiDate } from '@/lib/format';
import { appendUnique, hasMorePages, withPage, type PageMeta } from '@/lib/paging';
import { ripple } from '@/lib/ripple';
import { toast } from '@/lib/toast';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { useFocusEffect, useRouter } from 'expo-router';
import {
  Bell,
  CircleAlert,
  CircleCheckBig,
  PackageCheck,
  Truck,
  Wallet,
  XCircle,
  type LucideIcon,
} from 'lucide-react-native';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Platform, Pressable, SectionList, View, type SectionListRenderItem } from 'react-native';
import { useBottomPad } from '@/components/safe-area-view';

const WIB_OFFSET_MS = 7 * 60 * 60 * 1000;
const DAY_MS = 24 * 60 * 60 * 1000;

const GROUPS = ['Hari ini', 'Kemarin', 'Minggu ini', 'Lebih lama'] as const;
type GroupLabel = (typeof GROUPS)[number];

/** Calendar day in WIB, as a whole number, so "today" and "yesterday" follow Jakarta's midnight. */
function wibDay(ms: number): number {
  return Math.floor((ms + WIB_OFFSET_MS) / DAY_MS);
}

function groupOf(value: string | null | undefined, today: number): GroupLabel {
  const date = parseApiDate(value);
  if (!date) {
    return 'Lebih lama';
  }
  const age = today - wibDay(date.getTime());
  if (age <= 0) {
    return 'Hari ini';
  }
  if (age === 1) {
    return 'Kemarin';
  }

  return age <= 6 ? 'Minggu ini' : 'Lebih lama';
}

type NotificationSection = { title: GroupLabel; data: AppNotification[] };

const notificationKey = (item: AppNotification) => item.id;

function renderHeader({ section }: { section: NotificationSection }) {
  return (
    <Text
      role="heading"
      aria-level={2}
      className="px-4 pb-1 pt-4 text-[12px] font-semibold uppercase tracking-wide text-muted-foreground">
      {section.title}
    </Text>
  );
}

export default function NotificationsScreen() {
  // Clears the Android navigation bar (the app draws edge-to-edge).
  const bottomPad = useBottomPad(40);
  const { user, sessionPending } = useAuth();
  const router = useRouter();
  const [items, setItems] = useState<AppNotification[]>([]);
  // True only until the first answer: later focus reloads keep the list on screen.
  const [loading, setLoading] = useState(true);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  // A failed load or refresh; a failed next page; a failed "mark all as read".
  const [error, setError] = useState<string | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [marking, setMarking] = useState(false);
  // The latest load: an older answer (or a page that was loading meanwhile) is dropped.
  const request = useRef(0);
  // Pages on screen (loadMore raises it), so a focus reload can bring all of them back.
  const shownPages = useRef(1);
  const userId = user?.id ?? null;

  // `keepPages` (a focus reload, e.g. back from an order) refetches every page already shown and
  // swaps them in at once, so the list keeps its length and scroll position. Pull-to-refresh and
  // "Coba lagi" start over from page 1.
  const load = useCallback(
    async (keepPages = false) => {
      if (userId === null) {
        setItems([]);
        setLoading(false);
        return;
      }
      const id = ++request.current;
      const pages = keepPages ? Math.max(1, shownPages.current) : 1;
      try {
        const responses = await Promise.all(
          Array.from({ length: pages }, (_, index) =>
            api<{ data: AppNotification[]; meta?: PageMeta }>(
              index === 0 ? '/notifications' : withPage('/notifications', index + 1)
            )
          )
        );
        if (id !== request.current) {
          return;
        }
        setItems(
          responses.reduce<AppNotification[]>((rows, res) => appendUnique(rows, res.data ?? []), [])
        );
        setMeta(responses[responses.length - 1].meta ?? null);
        setError(null);
        setLoadError(null);
      } catch (e) {
        if (id === request.current) {
          setError(errorMessage(e, 'Gagal memuat notifikasi'));
        }
      } finally {
        if (id === request.current) {
          setLoading(false);
        }
      }
    },
    [userId]
  );

  useEffect(() => {
    shownPages.current = meta?.current_page ?? 1;
  }, [meta]);

  useFocusEffect(
    useCallback(() => {
      void load(true);
    }, [load])
  );

  const { refreshing, onRefresh } = useRefresh(() => load());

  async function loadMore(force = false) {
    if (!meta || !hasMorePages(meta) || loadingMore || (loadError && !force)) {
      return;
    }
    const id = request.current;
    setLoadingMore(true);
    setLoadError(null);
    try {
      const res = await api<{ data: AppNotification[]; meta?: PageMeta }>(
        withPage('/notifications', meta.current_page + 1)
      );
      if (id !== request.current) {
        return;
      }
      setItems((current) => appendUnique(current, res.data ?? []));
      setMeta(res.meta ?? null);
    } catch (e) {
      if (id === request.current) {
        setLoadError(errorMessage(e, 'Gagal memuat notifikasi berikutnya'));
      }
    } finally {
      setLoadingMore(false);
    }
  }

  async function markAll() {
    if (marking) {
      return;
    }
    setMarking(true);
    setActionError(null);
    try {
      await api('/notifications/read-all', { method: 'POST' });
      const stamp = new Date().toISOString();
      setItems((current) => current.map((row) => ({ ...row, read_at: row.read_at ?? stamp })));
      toast.show({ tone: 'success', message: 'Semua notifikasi ditandai dibaca' });
    } catch (e) {
      setActionError(errorMessage(e, 'Gagal menandai notifikasi'));
    } finally {
      setMarking(false);
    }
  }

  function open(item: AppNotification) {
    if (!item.read_at) {
      // Update the row and move on at once: the server catches up in the background, and a
      // failure only means the dot comes back on the next load.
      const stamp = new Date().toISOString();
      setItems((current) =>
        current.map((row) => (row.id === item.id ? { ...row, read_at: stamp } : row))
      );
      void api(`/notifications/${item.id}/read`, { method: 'POST' }).catch(() => {});
    }
    if (item.order_number) {
      // navigate, not push: a second tap before the transition starts reuses the order screen
      // just opened instead of stacking another copy of it.
      router.navigate({ pathname: '/order/[number]', params: { number: item.order_number } });
    }
  }

  function goShopping() {
    if (router.canDismiss()) {
      router.dismissTo('/(tabs)');
    } else {
      router.replace('/(tabs)');
    }
  }

  const sections = useMemo(() => {
    const today = wibDay(Date.now());
    const buckets = new Map<GroupLabel, AppNotification[]>();
    for (const item of items) {
      const label = groupOf(item.created_at, today);
      buckets.set(label, [...(buckets.get(label) ?? []), item]);
    }

    return GROUPS.filter((label) => buckets.has(label)).map((label) => ({
      title: label,
      data: buckets.get(label) ?? [],
    }));
  }, [items]);

  // Signed in, profile still loading (auth retries it): show the list skeleton, not a login prompt.
  if (!user && !sessionPending) {
    return (
      <Screen title="Notifikasi">
        <LoginPrompt
          icon={Bell}
          title="Belum ada notifikasi"
          body="Masuk untuk menerima info pembayaran dan pengiriman."
          redirect="/notifications"
        />
      </Screen>
    );
  }

  if (!user || (loading && items.length === 0)) {
    return (
      <Screen
        title="Notifikasi"
        loading={
          <View className="mt-2">
            {Array.from({ length: 6 }, (_, index) => (
              <NotificationRowSkeleton key={index} />
            ))}
          </View>
        }
      />
    );
  }

  if (error && items.length === 0) {
    return (
      <Screen title="Notifikasi">
        <EmptyState
          tone="error"
          icon={CircleAlert}
          title="Notifikasi belum bisa dimuat"
          message={error}
          actionLabel="Coba lagi"
          onAction={() => {
            setLoading(true);
            void load();
          }}
        />
      </Screen>
    );
  }

  if (items.length === 0) {
    return (
      <Screen title="Notifikasi">
        <EmptyState
          icon={Bell}
          title="Belum ada notifikasi"
          message="Info pembayaran dan pengiriman pesananmu akan muncul di sini."
          actionLabel="Mulai belanja"
          onAction={goShopping}
        />
      </Screen>
    );
  }

  const hasUnread = items.some((item) => !item.read_at);

  const renderRow: SectionListRenderItem<AppNotification, NotificationSection> = ({
    item,
    index,
    section,
  }) => (
    <NotificationRow
      item={item}
      last={index === section.data.length - 1}
      onOpen={() => open(item)}
    />
  );

  return (
    <Screen
      title="Notifikasi"
      surface
      right={
        hasUnread ? (
          <Pressable
            accessibilityRole="button"
            disabled={marking}
            onPress={() => void markAll()}
            android_ripple={ripple.onNavy}
            className="min-h-11 justify-center px-2 active:opacity-70">
            <Text
              numberOfLines={1}
              maxFontSizeMultiplier={1.2}
              className="text-[12px] font-semibold text-white">
              Tandai semua dibaca
            </Text>
          </Pressable>
        ) : undefined
      }>
      <SectionList
        sections={sections}
        keyExtractor={notificationKey}
        renderItem={renderRow}
        renderSectionHeader={renderHeader}
        stickySectionHeadersEnabled={false}
        className="flex-1"
        contentContainerStyle={{ paddingBottom: bottomPad }}
        refreshControl={<BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
        ListHeaderComponent={
          error || actionError ? (
            <View>
              {error ? <ErrorBanner message={error} onRetry={() => void load()} /> : null}
              {actionError ? <ErrorBanner message={actionError} /> : null}
            </View>
          ) : null
        }
        ListFooterComponent={
          <LoadMore
            hasMore={hasMorePages(meta)}
            loading={loadingMore}
            count={items.length}
            error={loadError}
            onRetry={() => void loadMore(true)}
            endLabel="Tidak ada notifikasi lain"
            showEnd={(meta?.last_page ?? 1) > 1}
          />
        }
        onEndReached={() => void loadMore()}
        onEndReachedThreshold={0.6}
        // Android only: detaching off-screen rows saves memory, but on iOS it flashes blank rows.
        removeClippedSubviews={Platform.OS === 'android'}
        initialNumToRender={10}
        maxToRenderPerBatch={10}
        windowSize={7}
      />
    </Screen>
  );
}

function NotificationRow({
  item,
  last,
  onOpen,
}: {
  item: AppNotification;
  last: boolean;
  onOpen: () => void;
}) {
  const kind = NOTIFICATION_KIND[item.type ?? ''] ?? NOTIFICATION_KIND.default;
  const read = Boolean(item.read_at);
  const time = formatRelative(item.created_at);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${read ? '' : 'Belum dibaca, '}${item.title}. ${item.body ?? ''} ${time}`.replace(
        /\s+/g,
        ' '
      )}
      onPress={onOpen}
      android_ripple={ripple.row}
      className={cn(
        'flex-row gap-3 px-4 py-3.5',
        read ? 'bg-white' : 'bg-om-navy-soft',
        Platform.OS === 'ios' && (read ? 'active:bg-om-surface' : 'active:bg-om-navy/10'),
        !last && 'border-b border-border'
      )}>
      <View className={cn('size-10 items-center justify-center rounded-full', kind.bg)}>
        <Icon as={kind.icon} size={18} className={kind.fg} />
      </View>
      <View className="min-w-0 flex-1">
        <View className="flex-row items-start gap-2">
          <Text className={cn('flex-1 text-[14px] leading-5', read ? 'font-medium' : 'font-bold')}>
            {item.title}
          </Text>
          {!read ? <View className="mt-1.5 size-2 rounded-full bg-om-sale" /> : null}
        </View>
        {item.body ? (
          <Text className="mt-0.5 text-[13px] leading-5 text-muted-foreground">{item.body}</Text>
        ) : null}
        {time ? <Text className="mt-1 text-[12px] text-muted-foreground">{time}</Text> : null}
      </View>
    </Pressable>
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
