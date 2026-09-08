import { EmptyState } from '@/components/empty-state';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { Separator } from '@/components/ui/separator';
import { Text } from '@/components/ui/text';
import { api, type AppNotification } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { Link, useFocusEffect, useRouter } from 'expo-router';
import { ChevronRight, User } from 'lucide-react-native';
import { useCallback, useState } from 'react';
import { Alert as RNAlert, Pressable, ScrollView, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaView } from 'react-native-safe-area-context';

export default function AccountScreen() {
  const { user, logout } = useAuth();
  const router = useRouter();
  const [unread, setUnread] = useState(0);

  useFocusEffect(
    useCallback(() => {
      if (!user) {
        setUnread(0);
        return;
      }
      void api<{ data: AppNotification[]; meta?: { unread?: number } }>('/notifications')
        .then((res) => setUnread(res.meta?.unread ?? 0))
        .catch(() => setUnread(0));
    }, [user])
  );

  const initials = user
    ? `${user.first_name?.[0] ?? ''}${user.last_name?.[0] ?? ''}`.toUpperCase() || 'OM'
    : '';

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <StatusBar style="light" />
      <View className="bg-om-navy px-3 pb-3 pt-1">
        <View className="min-h-10 flex-row items-center gap-2.5">
          {user ? (
            <>
              <Avatar alt={`${user.first_name} ${user.last_name}`} className="size-9">
                <AvatarFallback className="bg-white/20">
                  <Text className="text-[11px] font-bold text-white">{initials}</Text>
                </AvatarFallback>
              </Avatar>
              <View className="flex-1">
                <Text className="text-[15px] font-bold text-white" numberOfLines={1}>
                  {user.first_name} {user.last_name}
                </Text>
                <Text className="text-[11px] text-white/70" numberOfLines={1}>
                  {user.email}
                </Text>
              </View>
            </>
          ) : (
            <Text className="text-[15px] font-bold leading-10 text-white">Akun</Text>
          )}
        </View>
      </View>

      {!user ? (
        <EmptyState
          icon={User}
          message="Masuk untuk pesanan, alamat, dan notifikasi."
          actionLabel="Masuk"
          onAction={() => router.push('/login')}>
          <View className="mt-3 flex-row justify-center gap-1">
            <Text variant="muted" className="text-[13px]">
              Belum punya akun?
            </Text>
            <Link href="/register">
              <Text className="text-[13px] font-semibold text-om-navy">Daftar</Text>
            </Link>
          </View>
        </EmptyState>
      ) : (
        <ScrollView className="flex-1 bg-background">
          <Row label="Pesanan" onPress={() => router.push('/orders')} />
          <Separator />
          <Row label="Alamat" onPress={() => router.push('/addresses')} />
          <Separator />
          <Row label="Notifikasi" badge={unread} onPress={() => router.push('/notifications')} />
          <Separator />
          <Row label="Profil" onPress={() => router.push('/profile')} />
          <Separator />
          <Pressable
            onPress={() =>
              RNAlert.alert('Keluar?', 'Kamu akan keluar dari akun ini.', [
                { text: 'Batal', style: 'cancel' },
                { text: 'Keluar', style: 'destructive', onPress: () => void logout() },
              ])
            }
            className="px-4 py-3.5">
            <Text className="text-[14px] text-destructive">Keluar</Text>
          </Pressable>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

function Row({
  label,
  onPress,
  badge = 0,
}: {
  label: string;
  onPress: () => void;
  badge?: number;
}) {
  return (
    <Pressable onPress={onPress} className="flex-row items-center px-4 py-3.5">
      <Text className="flex-1 text-[14px]">{label}</Text>
      {badge > 0 ? (
        <Badge variant="sale" className="mr-2 min-w-[18px] px-1.5">
          <Text className="text-[10px] font-bold">{badge > 99 ? '99+' : badge}</Text>
        </Badge>
      ) : null}
      <Icon as={ChevronRight} size={16} className="text-muted-foreground" />
    </Pressable>
  );
}
