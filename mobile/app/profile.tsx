import { EmptyState } from '@/components/empty-state';
import { Screen } from '@/components/screen';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useAuth } from '@/lib/auth';
import { cn } from '@/lib/utils';
import { useRouter, type Href } from 'expo-router';
import { ChevronRight, KeyRound, ShieldCheck, User } from 'lucide-react-native';
import { Pressable, ScrollView, View } from 'react-native';

export default function ProfileScreen() {
  const { user } = useAuth();
  const router = useRouter();

  if (!user) {
    return (
      <Screen title="Profil & keamanan">
        <EmptyState
          icon={User}
          message="Masuk untuk melihat profilmu."
          actionLabel="Masuk"
          onAction={() => router.push({ pathname: '/login', params: { redirect: '/profile' } })}
        />
      </Screen>
    );
  }

  const fullName = `${user.first_name ?? ''} ${user.last_name ?? ''}`.trim();
  const initials =
    `${user.first_name?.[0] ?? ''}${user.last_name?.[0] ?? ''}`.toUpperCase() || 'OM';
  const edit = (field: 'name' | 'email' | 'phone') =>
    router.push({ pathname: '/profile-edit', params: { field } } as unknown as Href);

  return (
    <Screen title="Profil & keamanan" surface>
      <ScrollView className="flex-1" contentContainerClassName="pb-10">
        <View className="items-center bg-white pb-5 pt-6">
          <Avatar alt={fullName} className="size-20">
            <AvatarFallback className="bg-om-navy">
              <Text className="text-[26px] font-extrabold text-white">{initials}</Text>
            </AvatarFallback>
          </Avatar>
          <Text className="mt-3 text-[17px] font-bold">{fullName}</Text>
          <Text className="text-[12px] text-muted-foreground">{user.email}</Text>
        </View>

        <Text className="px-4 pb-2 pt-5 text-[12px] font-bold uppercase tracking-wide text-muted-foreground">
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

        <Text className="px-4 pb-2 pt-6 text-[12px] font-bold uppercase tracking-wide text-muted-foreground">
          Keamanan
        </Text>
        <View className="bg-white">
          <Pressable
            onPress={() => router.push('/change-password' as Href)}
            className="flex-row items-center gap-3 px-4 py-4 active:bg-om-surface">
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
            Akunmu hanya bisa aktif di satu perangkat. Masuk di perangkat lain akan mengeluarkan
            perangkat ini.
          </Text>
        </View>
      </ScrollView>
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
    <Pressable onPress={onPress} className="flex-row items-center pl-4 active:bg-om-surface">
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
