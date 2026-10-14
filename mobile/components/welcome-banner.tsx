import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { announce, useReducedMotion } from '@/lib/a11y';
import { ripple } from '@/lib/ripple';
import { PartyPopper, X } from 'lucide-react-native';
import { useEffect } from 'react';
import { Platform, Pressable, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';

/**
 * The one-time nudge on Akun right after sign-up: the account is ready, and the first checkout
 * saves the shipping address for next time. Addresses are only added inside checkout (the address
 * book can set a default and delete, not add), so the call to action is "start shopping", never
 * "add an address". The caller decides when it shows and dismisses it.
 *
 * `overlap` pulls the card up over the bottom of the navy profile header, the way the orders card
 * does when there is no banner (the card that follows the header takes the overlap).
 */
export function WelcomeBanner({
  firstName,
  onShop,
  onDismiss,
  overlap = false,
}: {
  firstName: string;
  onShop: () => void;
  onDismiss: () => void;
  overlap?: boolean;
}) {
  const reduceMotion = useReducedMotion();
  const title = firstName ? `Akunmu sudah aktif, ${firstName}!` : 'Akunmu sudah aktif!';
  const body = 'Alamat pengiriman kamu isi saat checkout dan tersimpan untuk belanja berikutnya.';

  useEffect(() => {
    // Android reads the live region below; iOS needs the explicit announcement.
    if (Platform.OS === 'ios') {
      announce(`${title} ${body}`);
    }
  }, [title]);

  return (
    // Layout and animation sit on the animated node as plain styles; className stays on the
    // plain Views inside it.
    <Animated.View
      entering={reduceMotion ? undefined : FadeInDown.duration(300)}
      style={{ marginHorizontal: 12, marginTop: overlap ? -40 : 12 }}>
      <View
        accessibilityLiveRegion="polite"
        className="flex-row items-start gap-3 rounded-sm bg-om-success-soft py-3 pl-3 pr-1">
        <Icon as={PartyPopper} size={20} className="mt-0.5 text-om-success" />
        <View className="flex-1 pb-0.5">
          <Text className="text-[14px] font-bold text-om-success">{title}</Text>
          <Text className="mt-0.5 text-[12px] leading-4 text-foreground">{body}</Text>
          <Pressable
            accessibilityRole="button"
            onPress={onShop}
            className="mt-1 min-h-11 justify-center self-start active:opacity-60">
            <Text className="text-[13px] font-bold text-om-navy">Mulai belanja</Text>
          </Pressable>
        </View>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Tutup"
          onPress={onDismiss}
          android_ripple={ripple.icon}
          className="h-11 w-11 items-center justify-center active:opacity-60">
          <Icon as={X} size={16} className="text-muted-foreground" />
        </Pressable>
      </View>
    </Animated.View>
  );
}
