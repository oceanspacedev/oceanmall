import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import type { ErrorBoundaryProps } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { CircleAlert } from 'lucide-react-native';
import { useEffect } from 'react';
import { ScrollView, View } from 'react-native';
import { SafeAreaView } from '@/components/safe-area-view';

/**
 * Root error boundary. It replaces the whole root layout, so nothing here may depend on the
 * router, auth or cart providers: the only way out is retry().
 */
export function ErrorScreen({ error, retry }: ErrorBoundaryProps) {
  // The native splash is held until the app is ready; a crash must never leave it on top.
  useEffect(() => {
    SplashScreen.hideAsync().catch(() => {
      // Already hidden.
    });
  }, []);

  return (
    <SafeAreaView className="flex-1 bg-white">
      <StatusBar style="dark" />
      <ScrollView
        contentContainerClassName="grow items-center justify-center px-8 py-10"
        showsVerticalScrollIndicator={false}>
        <View className="h-24 w-24 items-center justify-center rounded-full bg-om-sale-soft">
          <Icon as={CircleAlert} size={40} className="text-om-sale" />
        </View>
        <Text role="heading" aria-level={1} className="mt-6 text-center text-[18px] font-bold">
          Terjadi kesalahan
        </Text>
        <Text className="mt-2 text-center text-[13px] text-muted-foreground">
          Aplikasi mengalami gangguan. Coba buka lagi halaman ini.
        </Text>
        <Button className="mt-6 min-h-12 w-full max-w-xs rounded-sm" onPress={() => void retry()}>
          <Text className="text-[15px] font-bold">Coba lagi</Text>
        </Button>
        {__DEV__ ? (
          <View className="mt-6 w-full rounded-sm bg-om-surface p-3">
            <Text className="font-mono text-[11px] text-muted-foreground">{error.message}</Text>
          </View>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  );
}
