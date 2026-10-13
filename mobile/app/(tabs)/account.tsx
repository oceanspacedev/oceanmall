import { MenuRow } from '@/components/shop';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, type AppNotification } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { Link, useFocusEffect, useRouter, type Href } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import {
  Bell,
  ChevronRight,
  CircleCheckBig,
  LogOut,
  MapPin,
  PackageOpen,
  ShieldCheck,
  Truck,
  UserRound,
  Wallet,
  type LucideIcon,
} from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Alert as RNAlert, Pressable, ScrollView, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

type OrderCounts = Partial<
  Record<'unpaid' | 'processing' | 'shipped' | 'completed' | 'cancelled', number>
>;

const ORDER_SHORTCUTS: { key: keyof OrderCounts; label: string; icon: LucideIcon }[] = [
  { key: 'unpaid', label: 'Belum bayar', icon: Wallet },
  { key: 'processing', label: 'Diproses', icon: PackageOpen },
  { key: 'shipped', label: 'Dikirim', icon: Truck },
  { key: 'completed', label: 'Selesai', icon: CircleCheckBig },
];

export default function AccountScreen() {
  const { user, logout } = useAuth();
  const router = useRouter();
  const [unread, setUnread] = useState(0);
  const [counts, setCounts] = useState<OrderCounts>({});

  useFocusEffect(
    useCallback(() => {
      if (!user) {
        setUnread(0);
        setCounts({});
        return;
      }
      void api<{ data: AppNotification[]; meta?: { unread?: number } }>('/notifications')
        .then((res) => setUnread(res.meta?.unread ?? 0))
        .catch(() => setUnread(0));
      void api<{ meta?: { counts?: OrderCounts } }>('/orders')
        .then((res) => setCounts(res.meta?.counts ?? {}))
        .catch(() => setCounts({}));
    }, [user])
  );

  const initials = user
    ? `${user.first_name?.[0] ?? ''}${user.last_name?.[0] ?? ''}`.toUpperCase() || 'OM'
    : '';

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <StatusBar style="light" />
      <ScrollView className="flex-1 bg-om-surface" contentContainerClassName="pb-8">
        {/* Profile header */}
        <View className="bg-om-navy px-4 pb-14 pt-3">
          {user ? (
            <Pressable
              onPress={() => router.push('/profile')}
              className="flex-row items-center gap-3">
              <Avatar
                alt={`${user.first_name} ${user.last_name}`}
                className="size-14 border-2 border-white/30">
                <AvatarFallback className="bg-white/15">
                  <Text className="text-[18px] font-extrabold text-white">{initials}</Text>
                </AvatarFallback>
              </Avatar>
              <View className="flex-1">
                <Text className="text-[18px] font-extrabold text-white" numberOfLines={1}>
                  {user.first_name} {user.last_name}
                </Text>
                <Text className="text-[12px] text-white/75" numberOfLines={1}>
                  {user.email}
                </Text>
                <View className="mt-1.5 flex-row items-center gap-1 self-start rounded-sm bg-white/15 px-2 py-0.5">
                  <Icon as={ShieldCheck} size={11} color="#fff" />
                  <Text className="text-[10px] font-bold text-white">Member OceanMall</Text>
                </View>
              </View>
              <Icon as={ChevronRight} size={18} color="rgba(255,255,255,0.8)" />
            </Pressable>
          ) : (
            <View>
              <Text className="text-[20px] font-extrabold text-white">Halo, selamat datang!</Text>
              <Text className="mt-1 text-[13px] text-white/80">
                Masuk untuk melacak pesanan, menyimpan alamat, dan checkout lebih cepat.
              </Text>
              <View className="mt-4 flex-row gap-2.5">
                <Button
                  className="flex-1 rounded-sm bg-white"
                  onPress={() => router.push('/login')}>
                  <Text className="font-bold text-om-navy">Masuk</Text>
                </Button>
                <Link href="/register" asChild>
                  <Button
                    variant="outline"
                    className="flex-1 rounded-sm border-white bg-transparent">
                    <Text className="font-bold text-white">Daftar</Text>
                  </Button>
                </Link>
              </View>
            </View>
          )}
        </View>

        {/* Orders card overlaps the header */}
        <View className="mx-3 -mt-10 rounded-sm bg-white px-2 pb-3 pt-3.5 shadow-sm shadow-black/10">
          <Pressable
            onPress={() =>
              user
                ? router.push('/orders')
                : router.push({ pathname: '/login', params: { redirect: '/orders' } })
            }
            className="mb-3 flex-row items-center px-2">
            <Text className="flex-1 text-[15px] font-bold">Pesanan saya</Text>
            <Text className="text-[12px] font-semibold text-om-navy">Lihat semua</Text>
            <Icon as={ChevronRight} size={14} className="text-om-navy" />
          </Pressable>
          <View className="flex-row">
            {ORDER_SHORTCUTS.map((item) => {
              const count = counts[item.key] ?? 0;
              const href = { pathname: '/orders', params: { status: item.key } } as unknown as Href;
              return (
                <Pressable
                  key={item.key}
                  onPress={() =>
                    user
                      ? router.push(href)
                      : router.push({ pathname: '/login', params: { redirect: '/orders' } })
                  }
                  className="flex-1 items-center gap-1.5 py-1">
                  <View>
                    <Icon as={item.icon} size={24} className="text-om-navy" />
                    {count > 0 ? (
                      <View className="absolute -right-2.5 -top-1.5 min-w-[18px] items-center rounded-full border-2 border-white bg-om-sale px-1">
                        <Text className="text-[9px] font-extrabold text-white">
                          {count > 99 ? '99+' : count}
                        </Text>
                      </View>
                    ) : null}
                  </View>
                  <Text className="text-[11px] text-foreground">{item.label}</Text>
                </Pressable>
              );
            })}
          </View>
        </View>

        {user ? (
          <>
            <View className="mx-3 mt-3 overflow-hidden rounded-sm bg-white">
              <MenuRow
                icon={MapPin}
                label="Daftar alamat"
                hint="Atur alamat pengiriman"
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

            <View className="mx-3 mt-3 overflow-hidden rounded-sm bg-white">
              <MenuRow
                icon={LogOut}
                label="Keluar"
                danger
                last
                onPress={() =>
                  RNAlert.alert('Keluar dari akun?', 'Kamu bisa masuk lagi kapan saja.', [
                    { text: 'Batal', style: 'cancel' },
                    { text: 'Keluar', style: 'destructive', onPress: () => void logout() },
                  ])
                }
              />
            </View>
          </>
        ) : null}

        <Text className="mt-6 text-center text-[11px] text-muted-foreground">
          OceanMall · Belanja gadget resmi
        </Text>
      </ScrollView>
    </SafeAreaView>
  );
}
