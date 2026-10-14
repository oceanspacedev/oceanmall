import { AuthShell } from '@/components/auth-shell';
import { ErrorBanner } from '@/components/error-banner';
import { Field } from '@/components/field';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { announce } from '@/lib/a11y';
import { ApiError, api, errorMessage } from '@/lib/api';
import { emailError, isEmail } from '@/lib/validate';
import { useRouter } from 'expo-router';
import { MailCheck } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { Keyboard, Platform, Pressable, View } from 'react-native';

/** Seconds before the link can be sent again; keeps customers clear of the reset-mail throttle. */
const RESEND_SECONDS = 60;

export default function ForgotPasswordScreen() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [touched, setTouched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [countdown, setCountdown] = useState(0);

  const address = email.trim();

  useEffect(() => {
    if (!done || countdown <= 0) {
      return;
    }
    const timer = setTimeout(() => setCountdown((seconds) => seconds - 1), 1000);
    return () => clearTimeout(timer);
  }, [countdown, done]);

  async function submit() {
    if (busy || !isEmail(address)) {
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await api('/auth/forgot-password', {
        method: 'POST',
        body: JSON.stringify({ email: address }),
      });
      Keyboard.dismiss();
      setDone(true);
      setCountdown(RESEND_SECONDS);
      if (Platform.OS === 'ios') {
        announce(`Cek email kamu. Tautan reset kata sandi dikirim ke ${address}.`);
      }
    } catch (e) {
      setError(errorMessage(e, 'Gagal mengirim tautan reset'));
      // Throttled: the server says how long to wait, so the button counts down the same time.
      if (done && e instanceof ApiError && e.retryAfter) {
        setCountdown(Math.min(Math.max(e.retryAfter, 1), 300));
      }
    } finally {
      setBusy(false);
    }
  }

  const backToLogin = () => (router.canGoBack() ? router.back() : router.replace('/login'));

  return (
    <AuthShell title="Lupa kata sandi" subtitle="Kami kirim tautan untuk membuat kata sandi baru.">
      {done ? (
        <View>
          <View className="items-center">
            <View className="size-24 items-center justify-center rounded-full bg-om-success-soft">
              <Icon as={MailCheck} size={40} className="text-om-success" />
            </View>
            <Text
              role="heading"
              aria-level={2}
              className="mt-4 text-center text-[18px] font-bold text-foreground">
              Cek email kamu
            </Text>
            <Text className="mt-2 text-center text-[13px] leading-5 text-muted-foreground">
              Tautan reset kata sandi dikirim ke{' '}
              <Text className="text-[13px] font-bold leading-5 text-foreground">{address}</Text>.
              Buka tautannya di browser, buat kata sandi baru, lalu masuk lagi di aplikasi.
            </Text>
          </View>

          {error ? <ErrorBanner message={error} className="mx-0 mb-0 mt-5" /> : null}

          <Button className="mt-6 min-h-12 rounded-sm" onPress={backToLogin}>
            <Text className="font-bold">Kembali ke halaman masuk</Text>
          </Button>
          <Pressable
            accessibilityRole="button"
            accessibilityState={{ disabled: countdown > 0 || busy, busy }}
            disabled={countdown > 0 || busy}
            onPress={() => void submit()}
            className="mt-2 min-h-11 items-center justify-center active:opacity-60">
            <Text
              className={
                countdown > 0 || busy
                  ? 'text-[13px] font-bold text-muted-foreground'
                  : 'text-[13px] font-bold text-om-navy'
              }>
              {busy ? 'Mengirim…' : countdown > 0 ? `Kirim ulang (${countdown})` : 'Kirim ulang'}
            </Text>
          </Pressable>
          <Text className="mt-3 text-center text-[12px] leading-4 text-muted-foreground">
            Jika email terdaftar, tautan akan terkirim. Cek juga folder spam.
          </Text>
        </View>
      ) : (
        <View>
          <Field
            label="Email"
            value={email}
            onChangeText={setEmail}
            onBlur={() => setTouched(true)}
            error={touched ? emailError(email) : null}
            autoFocus
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            autoComplete="email"
            placeholder="contoh@email.com"
            returnKeyType="send"
            onSubmitEditing={() => void submit()}
          />
          {error ? <ErrorBanner message={error} className="mx-0 mb-0 mt-5" /> : null}
          <Button
            className="mt-6 min-h-12 rounded-sm"
            disabled={!isEmail(address)}
            loading={busy}
            onPress={() => void submit()}>
            <Text className="font-bold">Kirim tautan</Text>
          </Button>

          <Pressable
            accessibilityRole="link"
            onPress={backToLogin}
            className="mt-4 min-h-11 items-center justify-center active:opacity-60">
            <Text className="text-[13px] font-bold text-om-navy">Kembali ke halaman masuk</Text>
          </Pressable>
        </View>
      )}
    </AuthShell>
  );
}
