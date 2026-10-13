import { Field } from '@/components/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { AuthShell } from '@/components/auth-shell';
import { Text } from '@/components/ui/text';
import { api, errorMessage } from '@/lib/api';
import { CircleAlert } from 'lucide-react-native';
import { Link } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

export default function ForgotPasswordScreen() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api('/auth/forgot-password', {
        method: 'POST',
        body: JSON.stringify({ email: email.trim() }),
      });
      setDone(true);
    } catch (e) {
      setError(errorMessage(e, 'Gagal mengirim tautan reset'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthShell
      title="Lupa kata sandi?"
      subtitle="Masukkan email akunmu, kami kirim tautan untuk membuat kata sandi baru.">
      {error ? (
        <Alert icon={CircleAlert} variant="destructive" className="mb-3">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}

      {done ? (
        <Text variant="muted" className="text-[13px] leading-5">
          Jika email terdaftar, tautan reset sudah dikirim. Buka tautan di browser, lalu masuk lagi
          di aplikasi.
        </Text>
      ) : (
        <>
          <View>
            <Field
              label="Email"
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              keyboardType="email-address"
              autoComplete="email"
              placeholder="email@contoh.com"
            />
          </View>
          <Button
            className="mt-6 h-12 rounded-sm"
            disabled={busy || email.trim() === ''}
            onPress={() => void submit()}>
            <Text>{busy ? 'Mengirim...' : 'Kirim tautan'}</Text>
          </Button>
        </>
      )}

      <View className="mt-4 flex-row justify-center">
        <Link href="/login">
          <Text className="text-[13px] font-semibold text-om-navy">Kembali masuk</Text>
        </Link>
      </View>
    </AuthShell>
  );
}
