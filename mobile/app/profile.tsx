import { DeleteAccountSheet } from '@/components/delete-account-sheet';
import { LoginPrompt } from '@/components/login-prompt';
import { Screen } from '@/components/screen';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useAuth } from '@/lib/auth';
import { ripple } from '@/lib/ripple';
import { cn } from '@/lib/utils';
import { useRouter } from 'expo-router';
import { ChevronRight, KeyRound, ShieldCheck, Trash2, UserRound } from 'lucide-react-native';
import { useState } from 'react';
import { Platform, Pressable, ScrollView, View } from 'react-native';

/** Pressed feedback for a full-width row: a ripple on Android, a gray wash on iOS (not both). */
const ROW_PRESSED = Platform.OS === 'ios' ? 'active:bg-om-surface' : '';

export default function ProfileScreen() {
  const { user } = useAuth();
  const router = useRouter();
  const [deleteOpen, setDeleteOpen] = useState(false);

  if (!user) {
    return (
      <Screen title="Profil & keamanan">
        <LoginPrompt
          icon={UserRound}
          title="Masuk ke akunmu"
          body="Kelola data diri dan keamanan akun setelah masuk."
          redirect="/profile"
        />
      </Screen>
    );
  }

  const fullName = `${user.first_name ?? ''} ${user.last_name ?? ''}`.trim();
  const initials =
    `${user.first_name?.[0] ?? ''}${user.last_name?.[0] ?? ''}`.toUpperCase() || 'OM';
  const edit = (field: 'name' | 'email' | 'phone') =>
    router.push({ pathname: '/profile-edit', params: { field } });

  return (
    <Screen title="Profil & keamanan" surface>
      <ScrollView className="flex-1" contentContainerClassName="pb-10">
        <View
          accessible
          accessibilityLabel={`${fullName}, ${user.email}`}
          className="items-center bg-white pb-5 pt-6">
          <Avatar alt={fullName} className="size-20">
            <AvatarFallback className="bg-om-navy">
              <Text className="text-[26px] font-extrabold text-white">{initials}</Text>
            </AvatarFallback>
          </Avatar>
          <Text className="mt-3 text-[17px] font-bold">{fullName}</Text>
          <Text className="text-[12px] text-muted-foreground">{user.email}</Text>
        </View>

        <Text
          role="heading"
          aria-level={2}
          className="px-4 pb-2 pt-5 text-[12px] font-bold uppercase tracking-wide text-muted-foreground">
          Info profil
        </Text>
        <View className="bg-white">
          <InfoRow label="Nama" value={fullName} onPress={() => edit('name')} />
          <InfoRow label="Email" value={user.email} onPress={() => edit('email')} />
          <InfoRow
            label="Nomor HP"
            value={user.phone_number || 'Belum diisi'}
            muted={!user.phone_number}
            onPress={() => edit('phone')}
            last
          />
        </View>

        <Text
          role="heading"
          aria-level={2}
          className="px-4 pb-2 pt-6 text-[12px] font-bold uppercase tracking-wide text-muted-foreground">
          Keamanan
        </Text>
        <View className="bg-white">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Ubah kata sandi, perangkat lain akan otomatis keluar"
            onPress={() => router.push('/change-password')}
            android_ripple={ripple.row}
            className={cn('min-h-11 flex-row items-center gap-3 px-4 py-4', ROW_PRESSED)}>
            <View className="size-9 items-center justify-center rounded-full bg-om-navy-soft">
              <Icon as={KeyRound} size={17} className="text-om-navy" />
            </View>
            <View className="flex-1">
              <Text className="text-[14px] font-medium">Ubah kata sandi</Text>
              <Text className="text-[12px] text-muted-foreground">
                Perangkat lain akan otomatis keluar
              </Text>
            </View>
            <Icon as={ChevronRight} size={16} className="text-muted-foreground" />
          </Pressable>
        </View>

        <View className="mx-4 mt-5 flex-row gap-2 rounded-sm bg-om-navy-soft p-3">
          <Icon as={ShieldCheck} size={16} className="mt-0.5 text-om-navy" />
          <Text className="flex-1 text-[12px] leading-5 text-om-navy">
            Satu akun aktif di satu perangkat. Kalau kamu masuk di perangkat lain, perangkat yang
            sebelumnya otomatis keluar. Mengubah kata sandi mengeluarkan semua perangkat lain.
          </Text>
        </View>

        <Text
          role="heading"
          aria-level={2}
          className="px-4 pb-2 pt-6 text-[12px] font-bold uppercase tracking-wide text-muted-foreground">
          Akun
        </Text>
        <View className="bg-white">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Hapus akun, data pribadimu dihapus permanen"
            onPress={() => setDeleteOpen(true)}
            android_ripple={ripple.row}
            className={cn('min-h-11 flex-row items-center gap-3 px-4 py-4', ROW_PRESSED)}>
            <View className="size-9 items-center justify-center rounded-full bg-destructive/10">
              <Icon as={Trash2} size={17} className="text-destructive" />
            </View>
            <View className="flex-1">
              <Text className="text-[14px] font-medium text-destructive">Hapus akun</Text>
              <Text className="text-[12px] text-muted-foreground">
                Data pribadimu dihapus permanen, riwayat pesanan tetap tersimpan
              </Text>
            </View>
            <Icon as={ChevronRight} size={16} className="text-muted-foreground" />
          </Pressable>
        </View>
      </ScrollView>

      <DeleteAccountSheet visible={deleteOpen} onClose={() => setDeleteOpen(false)} />
    </Screen>
  );
}

function InfoRow({
  label,
  value,
  onPress,
  muted = false,
  last = false,
}: {
  label: string;
  value: string;
  onPress: () => void;
  muted?: boolean;
  last?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`Ubah ${label}, ${value}`}
      onPress={onPress}
      android_ripple={ripple.row}
      className={cn('min-h-11 flex-row items-center pl-4', ROW_PRESSED)}>
      <View
        className={cn('flex-1 flex-row items-center py-4 pr-4', !last && 'border-b border-border')}>
        <Text className="w-24 text-[13px] text-muted-foreground">{label}</Text>
        <Text
          className={cn('flex-1 text-[14px]', muted ? 'text-muted-foreground' : 'font-medium')}
          numberOfLines={1}>
          {value}
        </Text>
        <Text className="ml-2 text-[13px] font-semibold text-om-navy">Ubah</Text>
        <Icon as={ChevronRight} size={15} className="text-om-navy" />
      </View>
    </Pressable>
  );
}
