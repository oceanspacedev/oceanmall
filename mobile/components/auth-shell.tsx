import { BrandLogo } from '@/components/brand-logo';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { COLORS } from '@/lib/theme';
import { useRouter } from 'expo-router';
import { ChevronLeft } from 'lucide-react-native';
import type { ReactNode, RefObject } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, View } from 'react-native';
import { SafeAreaView } from '@/components/safe-area-view';

/** Navy brand header with the form on a white sheet (login, register, reset). */
export function AuthShell({
  title,
  subtitle,
  scrollRef,
  children,
}: {
  title: string;
  /** One line under the title (white, 80%). */
  subtitle?: string;
  /** Lets a form scroll to the top (`scrollTo({ y: 0 })`) when it shows an error banner. */
  scrollRef?: RefObject<ScrollView | null>;
  children: ReactNode;
}) {
  const router = useRouter();

  const content = (
    <ScrollView
      ref={scrollRef}
      className="flex-1"
      contentContainerClassName="grow"
      keyboardShouldPersistTaps="handled"
      // iOS insets the scroll content itself; Android is handled by the KeyboardAvoidingView
      // below. Using both on iOS would adjust twice and leave a blank gap.
      automaticallyAdjustKeyboardInsets
      bounces={false}>
      <View className="px-4 pb-8 pt-1">
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Kembali"
          onPress={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))}
          hitSlop={8}
          className="-ml-2 h-10 w-10 items-center justify-center">
          <Icon as={ChevronLeft} size={24} color={COLORS.white} />
        </Pressable>
        <View className="mt-3">
          <BrandLogo variant="light" size={36} />
        </View>
        <Text role="heading" aria-level={1} className="mt-2 text-[20px] font-bold text-white">
          {title}
        </Text>
        {subtitle ? <Text className="mt-1 text-[13px] text-white/80">{subtitle}</Text> : null}
      </View>
      <View className="flex-1 rounded-t-md bg-white px-5 pb-10 pt-6">{children}</View>
    </ScrollView>
  );

  return (
    <SafeAreaView className="flex-1 bg-om-navy" edges={['top']}>
      {Platform.OS === 'android' ? (
        <KeyboardAvoidingView behavior="height" style={{ flex: 1 }}>
          {content}
        </KeyboardAvoidingView>
      ) : (
        content
      )}
    </SafeAreaView>
  );
}
