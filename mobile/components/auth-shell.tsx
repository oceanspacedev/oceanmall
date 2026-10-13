import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { useRouter } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { ChevronLeft, ShieldCheck, Truck, Wallet } from 'lucide-react-native';
import type { ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

/** Brand hero + white form card shared by login, register and password reset. */
export function AuthShell({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children: ReactNode;
}) {
  const router = useRouter();

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      <StatusBar style="light" />
      <KeyboardAvoidingView
        className="flex-1"
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          className="flex-1"
          contentContainerClassName="grow"
          keyboardShouldPersistTaps="handled"
          bounces={false}>
          <View className="px-4 pb-10 pt-1">
            <Pressable
              accessibilityLabel="Kembali"
              onPress={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))}
              hitSlop={8}
              className="-ml-2 h-10 w-10 items-center justify-center">
              <Icon as={ChevronLeft} size={24} color="#fff" />
            </Pressable>
            <Text className="mt-2 text-[28px] font-extrabold tracking-tight text-white">
              Ocean<Text className="text-[28px] font-extrabold text-sky-300">Mall</Text>
            </Text>
            <Text className="mt-3 text-[20px] font-bold text-white">{title}</Text>
            <Text className="mt-1 text-[13px] leading-5 text-white/80">{subtitle}</Text>
            <View className="mt-4 flex-row gap-4">
              <Perk icon={ShieldCheck} label="Produk resmi" />
              <Perk icon={Truck} label="Kirim se-Indonesia" />
              <Perk icon={Wallet} label="Bayar VA & QRIS" />
            </View>
          </View>
          <View className="-mt-4 flex-1 rounded-t-md bg-white px-5 pb-10 pt-6">{children}</View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function Perk({ icon, label }: { icon: typeof Truck; label: string }) {
  return (
    <View className="flex-row items-center gap-1">
      <Icon as={icon} size={13} color="rgba(255,255,255,0.85)" />
      <Text className="text-[11px] text-white/85">{label}</Text>
    </View>
  );
}
