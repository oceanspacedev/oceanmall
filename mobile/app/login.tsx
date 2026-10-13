import { Field } from '@/components/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { AuthShell } from '@/components/auth-shell';
import { Text } from '@/components/ui/text';
import { CircleAlert } from 'lucide-react-native';
import { errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { safeInternalPath } from '@/lib/redirect';
import { Link, useLocalSearchParams, useNavigation, useRouter, type Href } from 'expo-router';
import { useEffect, useState } from 'react';
import { View } from 'react-native';

export default function LoginScreen() {
  const { login, user } = useAuth();
  const router = useRouter();
  const navigation = useNavigation();
  const params = useLocalSearchParams<{ redirect?: string }>();
  const next = safeInternalPath(params.redirect, '/(tabs)/account');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Only the visible auth screen redirects; a login screen buried under
  // register must not navigate on its own when the user signs in.
  useEffect(() => {
    if (user && navigation.isFocused()) {
      router.replace(next as Href);
    }
  }, [navigation, next, router, user]);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await login(email.trim(), password);
      router.replace(next as Href);
    } catch (e) {
      setError(errorMessage(e, 'Gagal masuk'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthShell
      title="Masuk ke akunmu"
      subtitle="Lanjutkan belanja, cek pesanan, dan checkout lebih cepat.">
      {error ? (
        <Alert icon={CircleAlert} variant="destructive" className="mb-1">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}
      <View className="mt-4 gap-3">
        <Field
          label="Email"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          keyboardType="email-address"
          autoComplete="email"
          placeholder="email@contoh.com"
        />
        <Field
          label="Kata sandi"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete="password"
          placeholder="Kata sandi"
        />
      </View>
      <Button
        className="mt-6 h-12 rounded-sm"
        disabled={busy || !email || !password}
        onPress={() => void submit()}>
        <Text>{busy ? 'Masuk...' : 'Masuk'}</Text>
      </Button>
      <View className="mt-3 items-center">
        <Link href="/forgot-password">
          <Text className="text-[13px] font-semibold text-om-navy">Lupa kata sandi?</Text>
        </Link>
      </View>
      <View className="mt-4 flex-row justify-center gap-1">
        <Text className="text-[13px] text-muted-foreground">Belum punya akun?</Text>
        <Link
          replace
          href={{
            pathname: '/register',
            params: params.redirect ? { redirect: params.redirect } : {},
          }}>
          <Text className="text-[13px] font-semibold text-om-navy">Daftar</Text>
        </Link>
      </View>
    </AuthShell>
  );
}
