import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { cn } from '@/lib/utils';
import { useRouter } from 'expo-router';
import type { LucideIcon } from 'lucide-react-native';
import { Pressable, View } from 'react-native';

/**
 * The body of a screen that needs an account (checkout, orders, notifications, addresses,
 * profile): a soft navy circle with the screen's icon, a headline, one line of why, "Masuk" and a
 * "Daftar" link. Both go to the auth screens with `redirect`, so the customer lands back here
 * afterwards. Render it inside the screen's own `<Screen>`.
 *
 * `redirect` is an internal path such as '/orders' or '/orders?status=unpaid'.
 */
export function LoginPrompt({
  icon,
  title,
  body,
  redirect,
  className,
}: {
  icon: LucideIcon;
  title: string;
  body: string;
  redirect: string;
  /** Overrides the root, for example `bg-om-surface` on a gray page. */
  className?: string;
}) {
  const router = useRouter();

  return (
    <View className={cn('flex-1 items-center justify-center bg-background px-8', className)}>
      <View className="size-24 items-center justify-center rounded-full bg-om-navy-soft">
        <Icon as={icon} size={40} className="text-om-navy" />
      </View>
      <Text
        role="heading"
        aria-level={2}
        className="mt-4 text-center text-[16px] font-bold text-foreground">
        {title}
      </Text>
      <Text className="mt-1 text-center text-[13px] leading-5 text-muted-foreground">{body}</Text>
      <Button
        className="mt-5 min-h-11 rounded-sm px-8"
        onPress={() => router.push({ pathname: '/login', params: { redirect } })}>
        <Text className="font-bold">Masuk</Text>
      </Button>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel="Belum punya akun? Daftar"
        onPress={() => router.push({ pathname: '/register', params: { redirect } })}
        className="mt-2 min-h-11 flex-row items-center justify-center gap-1 px-4 active:opacity-60">
        <Text className="text-[13px] text-muted-foreground">Belum punya akun?</Text>
        <Text className="text-[13px] font-bold text-om-navy">Daftar</Text>
      </Pressable>
    </View>
  );
}
