import { BrandLogo } from '@/components/brand-logo';
import { BrandRefreshControl } from '@/components/brand-refresh-control';
import { MenuRow } from '@/components/shop';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { WelcomeBanner } from '@/components/welcome-banner';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { APP_VERSION, APP_VERSION_LABEL } from '@/lib/legal-content';
import { useUnreadCount } from '@/lib/notifications';
import { ripple } from '@/lib/ripple';
import { contactSupport, supportChannels } from '@/lib/support';
import { COLORS, SHADOW } from '@/lib/theme';
import { useRefresh } from '@/lib/use-refresh';
import { cn } from '@/lib/utils';
import { consumeWelcomePending } from '@/lib/welcome';
import { Image } from 'expo-image';
import { Link, useFocusEffect, useRouter, useScrollToTop, type Href } from 'expo-router';
import {
  Bell,
  ChevronRight,
  CircleCheckBig,
  FileText,
  Info,
  LifeBuoy,
  LogOut,
  MapPin,
  MessageCircle,
  PackageOpen,
  ShieldCheck,
  Truck,
  UserRound,
  Wallet,
  type LucideIcon,
} from 'lucide-react-native';
import { useCallback, useRef, useState } from 'react';
import { Alert as RNAlert, Pressable, ScrollView, View } from 'react-native';
import { SafeAreaView } from '@/components/safe-area-view';

type OrderCounts = Partial<
  Record<'unpaid' | 'processing' | 'shipped' | 'completed' | 'cancelled', number>
>;

const ORDER_SHORTCUTS: { key: keyof OrderCounts; label: string; icon: LucideIcon }[] = [
  { key: 'unpaid', label: 'Belum bayar', icon: Wallet },
  { key: 'processing', label: 'Diproses', icon: PackageOpen },
  { key: 'shipped', label: 'Dikirim', icon: Truck },
  { key: 'completed', label: 'Selesai', icon: CircleCheckBig },
];

const LOGO_MARK = require('@/assets/images/logo-mark.png');

export default function AccountScreen() {
  const { user, logout } = useAuth();
  const router = useRouter();
  const { unread, refresh: refreshUnread } = useUnreadCount();
  const [counts, setCounts] = useState<OrderCounts>({});
  const [welcome, setWelcome] = useState(false);
  const scrollRef = useRef<ScrollView>(null);
  useScrollToTop(scrollRef);

  const loadCounts = useCallback(async () => {
    if (!user) {
      setCounts({});
      return;
    }
    try {
      const res = await api<{ meta?: { counts?: OrderCounts } }>('/orders');
      setCounts(res.meta?.counts ?? {});
    } catch {
      // Keep the last counts: a failed refresh must not blank the badges.
    }
  }, [user]);

  useFocusEffect(
    useCallback(() => {
      void loadCounts();
    }, [loadCounts])
  );

  // The welcome nudge shows once, right after sign-up (register.tsx sets the flag), and is gone
  // as soon as the customer leaves the tab.
  useFocusEffect(
    useCallback(() => {
      if (consumeWelcomePending()) {
        setWelcome(true);
      }
      return () => setWelcome(false);
    }, [])
  );

  const { refreshing, onRefresh } = useRefresh(
    useCallback(async () => {
      await Promise.all([loadCounts(), refreshUnread()]);
    }, [loadCounts, refreshUnread])
  );

  const initials = user
    ? `${user.first_name?.[0] ?? ''}${user.last_name?.[0] ?? ''}`.toUpperCase() || 'OM'
    : '';
  const showWelcome = welcome && Boolean(user);
  const channels = supportChannels();

  // The legal and help pages are new routes: typed routes only know them after Metro regenerates.
  const open = (path: Href) => router.push(path);

  const confirmLogout = () =>
    RNAlert.alert('Keluar dari akun?', 'Kamu bisa masuk lagi kapan saja.', [
      { text: 'Batal', style: 'cancel' },
      { text: 'Keluar', style: 'destructive', onPress: () => void logout() },
    ]);

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <ScrollView
        ref={scrollRef}
        className="flex-1 bg-om-surface"
        contentContainerClassName="pb-8"
        refreshControl={
          user ? <BrandRefreshControl refreshing={refreshing} onRefresh={onRefresh} /> : undefined
        }>
        {/* Profile header */}
        <View className="bg-om-navy px-4 pb-14 pt-3">
          {user ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`${user.first_name} ${user.last_name}, ${user.email}. Buka profil`}
              onPress={() => router.push('/profile')}
              className="flex-row items-center gap-3 active:opacity-80">
              <Avatar
                alt={`${user.first_name} ${user.last_name}`}
                className="size-14 border-2 border-white/30">
                <AvatarFallback className="bg-white/15">
                  <Text className="text-[18px] font-extrabold text-white">{initials}</Text>
                </AvatarFallback>
              </Avatar>
              <View className="flex-1">
                <Text
                  role="heading"
                  aria-level={1}
                  className="text-[18px] font-extrabold text-white"
                  numberOfLines={1}>
                  {user.first_name} {user.last_name}
                </Text>
                <Text className="text-[12px] text-white/75" numberOfLines={1}>
                  {user.email}
                </Text>
              </View>
              <Icon as={ChevronRight} size={18} color="rgba(255,255,255,0.8)" />
            </Pressable>
          ) : (
            <View>
              <View className="flex-row items-center gap-3">
                <View className="size-14 items-center justify-center rounded-full bg-white">
                  <Image
                    source={LOGO_MARK}
                    style={{ width: 40, height: 40 }}
                    contentFit="contain"
                    accessible={false}
                  />
                </View>
                <Text
                  role="heading"
                  aria-level={1}
                  className="flex-1 text-[18px] font-extrabold leading-6 text-white">
                  Halo, selamat datang di OceanMall!
                </Text>
              </View>
              <View className="mt-3 gap-1.5">
                <Benefit icon={Truck} text="Lacak pesanan & resi pengiriman" />
                <Benefit icon={Wallet} text="Pantau status pembayaran" />
              </View>
              <View className="mt-4 flex-row gap-2.5">
                <Button
                  className="min-h-11 flex-1 rounded-sm bg-white"
                  onPress={() => router.push('/login')}>
                  <Text className="font-bold text-om-navy">Masuk</Text>
                </Button>
                <Link href="/register" asChild>
                  <Button
                    variant="outline"
                    className="min-h-11 flex-1 rounded-sm border-white bg-transparent">
                    <Text className="font-bold text-white">Daftar</Text>
                  </Button>
                </Link>
              </View>
            </View>
          )}
        </View>

        {showWelcome && user ? (
          <WelcomeBanner
            overlap
            firstName={user.first_name}
            onShop={() => {
              setWelcome(false);
              router.navigate('/(tabs)');
            }}
            onDismiss={() => setWelcome(false)}
          />
        ) : null}

        {/* The first card after the header overlaps it. */}
        <View
          style={SHADOW.card}
          className={cn('mx-3 rounded-sm bg-white px-2 py-3.5', showWelcome ? 'mt-3' : '-mt-10')}>
          <View className="flex-row">
            {ORDER_SHORTCUTS.map((item) => {
              const count = user ? (counts[item.key] ?? 0) : 0;
              return (
                <Pressable
                  key={item.key}
                  accessibilityRole="button"
                  accessibilityLabel={count > 0 ? `${item.label}, ${count} pesanan` : item.label}
                  onPress={() =>
                    user
                      ? router.push({ pathname: '/orders', params: { status: item.key } })
                      : // The tapped status survives sign-in: login sends them back to that tab.
                        router.push({
                          pathname: '/login',
                          params: { redirect: `/orders?status=${item.key}` },
                        })
                  }
                  android_ripple={ripple.row}
                  className="flex-1 items-center gap-1.5 rounded-sm py-1 active:opacity-70">
                  <View>
                    <Icon as={item.icon} size={24} className="text-om-navy" />
                    {count > 0 ? (
                      <View className="absolute -right-2.5 -top-1.5 min-w-[18px] items-center rounded-full border-2 border-white bg-om-sale px-1">
                        <Text className="text-[10px] font-extrabold text-white">
                          {count > 99 ? '99+' : count}
                        </Text>
                      </View>
                    ) : null}
                  </View>
                  <Text className="text-center text-[12px] text-foreground">{item.label}</Text>
                </Pressable>
              );
            })}
          </View>
        </View>

        {user ? (
          <View className="mx-3 mt-3 overflow-hidden rounded-sm bg-white">
            <MenuRow
              icon={MapPin}
              label="Daftar alamat"
              hint="Alamat utama dan alamat tersimpan"
              onPress={() => router.push('/addresses')}
            />
            <MenuRow
              icon={Bell}
              label="Notifikasi"
              hint="Info pesanan & pembayaran"
              badge={unread}
              onPress={() => router.push('/notifications')}
            />
            <MenuRow
              icon={UserRound}
              label="Profil & keamanan"
              hint="Ubah data diri dan kata sandi"
              onPress={() => router.push('/profile')}
              last
            />
          </View>
        ) : null}

        {/* Help, legal and about: for guests and signed-in customers alike. */}
        <View className="mx-3 mt-3 overflow-hidden rounded-sm bg-white">
          <MenuRow
            icon={LifeBuoy}
            label="Pusat bantuan"
            hint="Pertanyaan umum"
            onPress={() => open('/about')}
          />
          {channels.length > 0 ? (
            <MenuRow
              icon={MessageCircle}
              label="Hubungi kami"
              hint={channels.length > 1 ? 'WhatsApp atau email' : channels[0].label}
              onPress={contactSupport}
            />
          ) : null}
          <MenuRow
            icon={FileText}
            label="Syarat & ketentuan"
            onPress={() => open('/legal/syarat-ketentuan')}
          />
          <MenuRow
            icon={ShieldCheck}
            label="Kebijakan privasi"
            onPress={() => open('/legal/kebijakan-privasi')}
          />
          <MenuRow
            icon={Info}
            label="Tentang OceanMall"
            hint={`Versi ${APP_VERSION}`}
            onPress={() => open('/about')}
            last
          />
        </View>

        {user ? (
          <View className="mx-3 mt-3 overflow-hidden rounded-sm bg-white">
            <MenuRow icon={LogOut} label="Keluar" danger last onPress={confirmLogout} />
          </View>
        ) : null}

        <View className="mt-6 items-center gap-1.5">
          <BrandLogo variant="dark" size={20} />
          <Text className="text-[12px] text-muted-foreground">{APP_VERSION_LABEL}</Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

/** One short line under the guest greeting: what an account is for. */
function Benefit({ icon, text }: { icon: LucideIcon; text: string }) {
  return (
    <View className="flex-row items-center gap-2">
      <Icon as={icon} size={14} color={COLORS.white} />
      <Text className="flex-1 text-[12px] text-white/85">{text}</Text>
    </View>
  );
}
