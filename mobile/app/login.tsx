import { Field } from '@/components/field';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { CircleAlert } from 'lucide-react-native';
import { errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { safeInternalPath } from '@/lib/redirect';
import { Link, useLocalSearchParams, useRouter, type Href } from 'expo-router';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';

export default function LoginScreen() {
  const { login, user } = useAuth();
  const router = useRouter();
  const params = useLocalSearchParams<{ redirect?: string }>();
  const next = safeInternalPath(params.redirect, '/(tabs)/account');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (user) {
      router.replace(next as Href);
    }
  }, [next, router, user]);

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
    <Screen title="Masuk">
      <KeyboardAvoidingView
        className="flex-1"
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerClassName="px-3 pb-10 pt-5"
          keyboardShouldPersistTaps="handled">
          <Text variant="muted" className="text-[13px]">
            Masuk dengan email akun customer.
          </Text>
          {error ? (
            <Alert icon={CircleAlert} variant="destructive" className="mt-3">
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
          <Button className="mt-5" disabled={busy || !email || !password} onPress={() => void submit()}>
            <Text>{busy ? 'Masuk...' : 'Masuk'}</Text>
          </Button>
          <View className="mt-3 items-center">
            <Link href="/forgot-password">
              <Text className="text-[13px] font-semibold text-om-navy">Lupa kata sandi?</Text>
            </Link>
          </View>
          <View className="mt-4 flex-row justify-center gap-1">
            <Text className="text-[13px] text-muted-foreground">Belum punya akun?</Text>
            <Link href={{ pathname: '/register', params: params.redirect ? { redirect: params.redirect } : {} }}>
              <Text className="text-[13px] font-semibold text-om-navy">Daftar</Text>
            </Link>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </Screen>
  );
}
