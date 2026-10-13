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

export default function RegisterScreen() {
  const { register, user } = useAuth();
  const router = useRouter();
  const navigation = useNavigation();
  const params = useLocalSearchParams<{ redirect?: string }>();
  const next = safeInternalPath(params.redirect, '/(tabs)/account');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Only the visible auth screen redirects; a login screen buried under
  // register must not navigate on its own when the user signs in.
  useEffect(() => {
    if (user && navigation.isFocused()) {
      router.replace(next as Href);
    }
  }, [navigation, next, router, user]);

  const mismatch = confirm.length > 0 && password !== confirm;
  const canSubmit =
    firstName.trim() !== '' &&
    lastName.trim() !== '' &&
    email.trim() !== '' &&
    password.length >= 8 &&
    password === confirm &&
    !busy;

  async function submit() {
    if (!canSubmit) {
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await register({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        email: email.trim(),
        phone_number: phone.trim() || undefined,
        password,
        password_confirmation: confirm,
      });
      router.replace(next as Href);
    } catch (e) {
      setError(errorMessage(e, 'Gagal daftar'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthShell title="Buat akun baru" subtitle="Gratis dan cuma butuh satu menit.">
      {error ? (
        <Alert icon={CircleAlert} variant="destructive" className="mb-3">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}
      <View className="flex-row gap-2.5">
        <View className="flex-1">
          <Field
            label="Nama depan"
            value={firstName}
            onChangeText={setFirstName}
            autoComplete="given-name"
            placeholder="Nama depan"
          />
        </View>
        <View className="flex-1">
          <Field
            label="Nama belakang"
            value={lastName}
            onChangeText={setLastName}
            autoComplete="family-name"
            placeholder="Nama belakang"
          />
        </View>
      </View>
      <View className="mt-3 gap-3">
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
          label="Nomor HP (opsional)"
          value={phone}
          onChangeText={setPhone}
          keyboardType="phone-pad"
          autoComplete="tel"
          placeholder="08…"
        />
        <Field
          label="Kata sandi"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete="new-password"
          placeholder="Minimal 8 karakter"
        />
        <Field
          label="Ulangi kata sandi"
          value={confirm}
          onChangeText={setConfirm}
          secureTextEntry
          autoComplete="new-password"
          placeholder="Ulangi kata sandi"
        />
        {mismatch ? (
          <Text className="text-[12px] text-destructive">Kata sandi tidak sama.</Text>
        ) : null}
      </View>
      <Button className="mt-6 h-12 rounded-sm" disabled={!canSubmit} onPress={() => void submit()}>
        <Text>{busy ? 'Mendaftar...' : 'Daftar'}</Text>
      </Button>
      <View className="mt-4 flex-row justify-center gap-1">
        <Text className="text-[13px] text-muted-foreground">Sudah punya akun?</Text>
        <Link
          replace
          href={{
            pathname: '/login',
            params: params.redirect ? { redirect: params.redirect } : {},
          }}>
          <Text className="text-[13px] font-semibold text-om-navy">Masuk</Text>
        </Link>
      </View>
    </AuthShell>
  );
}
