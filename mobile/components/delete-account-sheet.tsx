import { ErrorBanner } from '@/components/error-banner';
import { PasswordField } from '@/components/password-field';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { apiFieldErrors, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { ripple } from '@/lib/ripple';
import { toast } from '@/lib/toast';
import { useRouter } from 'expo-router';
import { X } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import {
  Keyboard,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  View,
  useWindowDimensions,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/**
 * In-app account deletion (App Store guideline 5.1.1(v), Google Play account-deletion policy).
 *
 * A bottom sheet, like the review sheet, so it needs no route of its own. It says what goes and
 * what stays, asks for the password (the server checks it) and only then deletes. The words here
 * match the privacy policy (lib/legal-content.json) and the public /hapus-akun page: change them
 * together.
 *
 * Android renders a Modal in a separate window, so it is made translucent behind the system bars,
 * padded by the bottom inset, and handles the keyboard itself.
 */
export function DeleteAccountSheet({
  visible,
  onClose,
}: {
  visible: boolean;
  onClose: () => void;
}) {
  const { deleteAccount } = useAuth();
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { height: windowHeight } = useWindowDimensions();
  const [password, setPassword] = useState('');
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (visible) {
      setPassword('');
      setPasswordError(null);
      setError(null);
      setBusy(false);
    }
  }, [visible]);

  function requestClose() {
    if (!busy) {
      onClose();
    }
  }

  async function confirm() {
    if (busy || password.length === 0) {
      return;
    }
    Keyboard.dismiss();
    setBusy(true);
    setError(null);
    setPasswordError(null);
    try {
      await deleteAccount(password);
    } catch (e) {
      setBusy(false);
      const fieldMessage = apiFieldErrors(e).password?.join(' ') ?? null;
      setPasswordError(fieldMessage);
      // Anything that is not about the password (offline, a throttle, a server fault) is a banner.
      setError(fieldMessage ? null : errorMessage(e, 'Akun belum bisa dihapus. Coba lagi, ya.'));
      return;
    }

    // The session is gone: the profile screen has already turned into the sign-in prompt, so
    // leave it for Beranda. The toast host sits above the navigator and survives the navigation.
    onClose();
    if (router.canDismiss()) {
      router.dismissTo('/(tabs)');
    } else {
      router.replace('/(tabs)');
    }
    toast.show({ tone: 'success', message: 'Akunmu sudah dihapus' });
  }

  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      statusBarTranslucent
      navigationBarTranslucent
      onRequestClose={requestClose}>
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
                Hapus akun?
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
              <Text className="text-[13px] leading-5 text-foreground">
                Akunmu langsung dihapus dan tidak bisa dikembalikan. Yang ikut terhapus: data
                profil, alamat tersimpan, notifikasi, ulasan, keranjang, dan semua sesi masuk di
                semua perangkatmu.
              </Text>
              <Text className="mt-2 text-[13px] leading-5 text-muted-foreground">
                Riwayat pesanan tetap kami simpan sebagai catatan transaksi, tetapi tidak lagi
                terhubung ke akunmu.
              </Text>

              <View className="mt-4">
                <PasswordField
                  label="Masukkan kata sandimu untuk mengonfirmasi"
                  value={password}
                  onChangeText={(value) => {
                    setPassword(value);
                    setPasswordError(null);
                  }}
                  error={passwordError}
                  autoComplete="current-password"
                  placeholder="Kata sandi"
                  returnKeyType="done"
                  onSubmitEditing={() => void confirm()}
                />
              </View>
            </ScrollView>

            <View className="px-4 pt-2">
              {error ? <ErrorBanner message={error} className="mx-0 mb-2.5 mt-0" /> : null}
              <Button
                variant="destructive"
                loading={busy}
                disabled={password.length === 0}
                onPress={() => void confirm()}
                className="min-h-12 rounded-sm">
                <Text className="font-bold">Hapus akun saya</Text>
              </Button>
              <Button
                variant="ghost"
                disabled={busy}
                onPress={requestClose}
                className="mt-1 min-h-11 rounded-sm">
                <Text className="font-semibold text-om-navy">Batal</Text>
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
