import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { API_CONFIG_ERROR_MESSAGE } from '@/lib/config';
import { StatusBar } from 'expo-status-bar';
import { ServerOff } from 'lucide-react-native';
import { View } from 'react-native';
import { SafeAreaView } from '@/components/safe-area-view';

/**
 * Shown by AuthGate instead of the app when a release build has no usable API host (see
 * lib/config.ts). metro.config.js refuses to bundle such a build, so this is the second layer: it
 * needs no router, auth, cart or network, and offers no action because nothing the user does here
 * can fix a missing build setting.
 */
export function ConfigErrorScreen() {
  return (
    <SafeAreaView className="flex-1 bg-white">
      <StatusBar style="dark" />
      <View className="flex-1 items-center justify-center px-8">
        <View className="h-24 w-24 items-center justify-center rounded-full bg-om-sale-soft">
          <Icon as={ServerOff} size={40} className="text-om-sale" />
        </View>
        <Text role="heading" aria-level={1} className="mt-6 text-center text-[18px] font-bold">
          {API_CONFIG_ERROR_MESSAGE}
        </Text>
        <Text className="mt-2 text-center text-[13px] text-muted-foreground">
          Aplikasi ini belum terhubung ke server OceanMall. Hubungi tim OceanMall, atau pasang versi
          terbaru dari toko aplikasi.
        </Text>
      </View>
    </SafeAreaView>
  );
}
