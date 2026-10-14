import { ErrorBanner } from '@/components/error-banner';
import { ProductImage } from '@/components/product-image';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, api, errorMessage } from '@/lib/api';
import { HAS_SUPPORT_CHANNEL, sendSupportMessage } from '@/lib/support';
import { mediaUrl } from '@/lib/format';
import { ripple } from '@/lib/ripple';
import { COLORS } from '@/lib/theme';
import { toast, type ToastTone } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { Flag, Star, X } from 'lucide-react-native';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Switch,
  View,
  useWindowDimensions,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export type ReviewTarget = {
  slug: string;
  name: string;
  thumbnail?: string | null;
};

export type ReviewResult = {
  slug: string;
  /** The server's own sentence ("Ulasan terkirim dan menunggu moderasi."). */
  message: string;
  /** The server said this product was already reviewed. */
  alreadyReviewed: boolean;
};

const RATING_LABELS = ['', 'Buruk', 'Kurang', 'Cukup', 'Bagus', 'Sangat bagus'];
/** Long enough for the slide-out to finish on Android, which has no onDismiss. */
const DISMISS_FALLBACK_MS = 450;

/**
 * Bottom sheet to review one product from a delivered order.
 *
 * It is a Modal, which on Android is a separate window: it is made translucent behind the system
 * bars and padded by the bottom inset so the button clears the gesture bar, and the keyboard is
 * handled here (padding on iOS, height on Android, like the sign-in screens). The toast layer
 * renders under a Modal, so nothing here toasts while the sheet is open: errors stay inline, and
 * the success toast waits until the sheet has gone.
 *
 * Reviews are user-generated content, so the sheet says they are reviewed before they appear and
 * offers "Laporkan" (WhatsApp or e-mail with the order number) when support is configured.
 *
 * The parent keeps `target` while closing, so the content does not blank during the slide-out.
 */
export function ReviewSheet({
  visible,
  target,
  orderNumber,
  onClose,
  onSubmitted,
  toastBottomOffset,
}: {
  visible: boolean;
  target: ReviewTarget | null;
  orderNumber: string;
  onClose: () => void;
  onSubmitted?: (result: ReviewResult) => void;
  /** Lifts the success toast above a sticky bar on the screen underneath. */
  toastBottomOffset?: number;
}) {
  const insets = useSafeAreaInsets();
  const { height: windowHeight } = useWindowDimensions();
  const [rating, setRating] = useState(0);
  const [content, setContent] = useState('');
  const [recommend, setRecommend] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const pendingToast = useRef<{ message: string; tone: ToastTone } | null>(null);
  const slug = target?.slug;

  useEffect(() => {
    if (visible) {
      setRating(0);
      setContent('');
      setRecommend(true);
      setBusy(false);
      setError(null);
    }
  }, [visible, slug]);

  const flushToast = useCallback(() => {
    const next = pendingToast.current;
    if (next) {
      pendingToast.current = null;
      toast.show({
        message: next.message,
        tone: next.tone,
        duration: 4000,
        bottomOffset: toastBottomOffset,
      });
    }
  }, [toastBottomOffset]);

  function requestClose() {
    if (!busy) {
      onClose();
    }
  }

  async function submit() {
    if (!target || busy) {
      return;
    }
    if (rating < 1) {
      setError('Pilih jumlah bintang dulu, ya.');
      return;
    }

    setBusy(true);
    setError(null);
    try {
      const res = await api<{ message?: string }>(`/catalog/products/${target.slug}/reviews`, {
        method: 'POST',
        body: JSON.stringify({
          rating,
          content: content.trim() === '' ? undefined : content.trim(),
          is_recommended: recommend,
        }),
      });
      const message = res.message?.trim() || 'Ulasan terkirim. Terima kasih!';
      pendingToast.current = { message, tone: 'success' };
      onSubmitted?.({ slug: target.slug, message, alreadyReviewed: false });
      closeThenToast();
    } catch (e) {
      const apiMessage =
        e instanceof ApiError && e.status === 422
          ? String((e.body as { message?: unknown } | null)?.message ?? '')
          : '';
      if (/sudah menulis/i.test(apiMessage)) {
        // Reviewed already (on another device, say): the sheet has nothing left to do.
        pendingToast.current = { message: apiMessage, tone: 'info' };
        onSubmitted?.({ slug: target.slug, message: apiMessage, alreadyReviewed: true });
        closeThenToast();
      } else {
        setError(errorMessage(e, 'Ulasan belum terkirim. Coba lagi, ya.'));
      }
    } finally {
      setBusy(false);
    }
  }

  function closeThenToast() {
    onClose();
    // iOS calls onDismiss when the sheet is gone; Android never does, so a timer backs both up.
    setTimeout(flushToast, DISMISS_FALLBACK_MS);
  }

  async function report() {
    if (!target) {
      return;
    }
    const opened = await sendSupportMessage(
      `Halo OceanMall, saya ingin melaporkan ulasan atau masalah pada produk "${target.name}" di pesanan ${orderNumber}.`,
      `Laporan ulasan pesanan ${orderNumber}`
    );
    if (!opened) {
      setError('WhatsApp dan email belum bisa dibuka di perangkat ini.');
    }
  }

  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      statusBarTranslucent
      navigationBarTranslucent
      onRequestClose={requestClose}
      onDismiss={flushToast}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
        style={styles.fill}>
        <View className="flex-1 justify-end bg-black/50">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Tutup"
            onPress={requestClose}
            style={StyleSheet.absoluteFill}
          />
          <View
            className="rounded-t-xl bg-white"
            style={{
              flexShrink: 1,
              maxHeight: windowHeight * 0.92,
              paddingBottom: Math.max(insets.bottom, 12),
            }}>
            <View className="items-center pt-2">
              <View className="h-1 w-9 rounded-full bg-om-disabled" />
            </View>

            <View className="flex-row items-center pl-4 pr-1 pt-1">
              <Text
                role="heading"
                aria-level={2}
                className="flex-1 text-[16px] font-extrabold text-foreground">
                Tulis ulasan
              </Text>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel="Tutup"
                onPress={requestClose}
                android_ripple={ripple.icon}
                className="h-11 w-11 items-center justify-center active:opacity-70">
                <Icon as={X} size={20} className="text-foreground" />
              </Pressable>
            </View>

            <ScrollView
              style={styles.shrink}
              keyboardShouldPersistTaps="handled"
              contentContainerClassName="px-4 pb-2"
              showsVerticalScrollIndicator={false}>
              {target ? (
                <View className="flex-row items-center gap-3">
                  <ProductImage
                    uri={mediaUrl(target.thumbnail)}
                    width={48}
                    height={48}
                    radius={6}
                  />
                  <Text numberOfLines={2} className="flex-1 text-[13px] leading-5">
                    {target.name}
                  </Text>
                </View>
              ) : null}

              <View accessibilityRole="radiogroup" className="mt-4 flex-row items-center">
                {[1, 2, 3, 4, 5].map((value) => {
                  const filled = value <= rating;
                  return (
                    <Pressable
                      key={value}
                      accessibilityRole="radio"
                      accessibilityLabel={`${value} bintang`}
                      accessibilityState={{ checked: rating === value }}
                      onPress={() => {
                        setRating(value);
                        setError(null);
                      }}
                      className="h-11 w-11 items-center justify-center active:opacity-70">
                      <Icon
                        as={Star}
                        size={30}
                        color={filled ? COLORS.star : COLORS.starEmpty}
                        fill={filled ? COLORS.star : 'transparent'}
                      />
                    </Pressable>
                  );
                })}
                <Text
                  className={cn(
                    'ml-2 flex-1 text-[13px]',
                    rating > 0 ? 'font-semibold text-foreground' : 'text-muted-foreground'
                  )}>
                  {rating > 0 ? RATING_LABELS[rating] : 'Ketuk bintang untuk menilai'}
                </Text>
              </View>

              <Textarea
                value={content}
                onChangeText={setContent}
                maxLength={2000}
                placeholder="Ceritakan pengalamanmu dengan produk ini (opsional)"
                accessibilityLabel="Isi ulasan"
                numberOfLines={5}
                className="mt-3 min-h-24 text-[14px]"
              />

              <View className="mt-3 flex-row items-center justify-between gap-3">
                <Text className="flex-1 text-[13px]">Aku merekomendasikan produk ini</Text>
                <Switch
                  value={recommend}
                  onValueChange={setRecommend}
                  accessibilityLabel="Rekomendasikan produk ini"
                  trackColor={{ false: COLORS.disabled, true: COLORS.navy }}
                  thumbColor={COLORS.white}
                  ios_backgroundColor={COLORS.disabled}
                />
              </View>

              <Text className="mt-3 text-[12px] leading-4 text-muted-foreground">
                Ulasan ditinjau tim OceanMall sebelum tampil di halaman produk.
              </Text>
              {HAS_SUPPORT_CHANNEL ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="Laporkan ulasan atau masalah"
                  onPress={() => void report()}
                  className="min-h-11 flex-row items-center gap-1.5 self-start active:opacity-60">
                  <Icon as={Flag} size={13} className="text-om-navy" />
                  <Text className="text-[12px] font-bold text-om-navy">
                    Laporkan ulasan atau masalah
                  </Text>
                </Pressable>
              ) : null}
            </ScrollView>

            <View className="px-4 pt-2">
              {error ? <ErrorBanner message={error} className="mx-0 mb-2.5 mt-0" /> : null}
              <Button
                loading={busy}
                disabled={rating < 1}
                onPress={() => void submit()}
                className="min-h-12 rounded-sm">
                <Text className="font-bold">Kirim ulasan</Text>
              </Button>
            </View>
          </View>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  shrink: { flexGrow: 0, flexShrink: 1 },
});
