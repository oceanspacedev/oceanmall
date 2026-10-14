import { AuthShell } from '@/components/auth-shell';
import { ErrorBanner } from '@/components/error-banner';
import { Field } from '@/components/field';
import { PasswordField } from '@/components/password-field';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { DEFAULT_AUTH_REDIRECT, authContext, leaveAuth, safeInternalPath } from '@/lib/redirect';
import { Link, useLocalSearchParams, useNavigation, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { Keyboard, Pressable, ScrollView, TextInput, View } from 'react-native';

const LINK_SLOP = { top: 14, bottom: 14, left: 12, right: 12 };

export default function LoginScreen() {
  const { login, user } = useAuth();
  const router = useRouter();
  const navigation = useNavigation();
  const params = useLocalSearchParams<{ redirect?: string }>();
  const next = safeInternalPath(params.redirect, DEFAULT_AUTH_REDIRECT);
  const context = authContext(params.redirect, 'login');
  const scrollRef = useRef<ScrollView>(null);
  const passwordRef = useRef<TextInput>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // The one place that leaves this screen: only the visible auth screen redirects, so a login
  // screen buried under register never navigates on its own when the user signs in.
  useEffect(() => {
    if (user && navigation.isFocused()) {
      leaveAuth(router, next);
    }
  }, [navigation, next, router, user]);

  const ready = email.trim() !== '' && password !== '';

  async function submit() {
    if (busy || !ready) {
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await login(email.trim(), password);
      // Stay busy: the effect above navigates as soon as the user is set.
    } catch (e) {
      setBusy(false);
      setError(errorMessage(e, 'Gagal masuk'));
      Keyboard.dismiss();
      scrollRef.current?.scrollTo({ y: 0, animated: true });
    }
  }

  const toRegister = () =>
    router.replace({
      pathname: '/register',
      params: params.redirect ? { redirect: params.redirect } : {},
    });

  return (
    <AuthShell title={context.title} subtitle={context.subtitle} scrollRef={scrollRef}>
      <View className="gap-4">
        <Field
          label="Email"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          autoComplete="username"
          textContentType="username"
          placeholder="contoh@email.com"
          returnKeyType="next"
          submitBehavior="submit"
          onSubmitEditing={() => passwordRef.current?.focus()}
        />
        <PasswordField
          ref={passwordRef}
          label="Kata sandi"
          value={password}
          onChangeText={setPassword}
          autoComplete="current-password"
          textContentType="password"
          placeholder="Masukkan kata sandi"
          returnKeyType="go"
          onSubmitEditing={() => void submit()}
        />
      </View>
      <View className="mt-3 items-end">
        <Link href="/forgot-password" asChild>
          <Pressable accessibilityRole="link" hitSlop={LINK_SLOP} className="active:opacity-60">
            <Text className="text-[13px] font-bold text-om-navy">Lupa kata sandi?</Text>
          </Pressable>
        </Link>
      </View>

      <View className="mt-5">
        {error ? <ErrorBanner message={error} className="mx-0 mb-3 mt-0" /> : null}
        <Button
          className="min-h-12 rounded-sm"
          disabled={!ready}
          loading={busy}
          onPress={() => void submit()}>
          <Text className="font-bold">Masuk</Text>
        </Button>
        {!ready && !busy ? (
          <Text className="mt-2 text-center text-[12px] text-muted-foreground">
            Isi email dan kata sandi untuk masuk
          </Text>
        ) : null}
      </View>

      <Pressable
        accessibilityRole="link"
        accessibilityLabel="Belum punya akun? Daftar"
        onPress={toRegister}
        hitSlop={LINK_SLOP}
        className="mt-5 min-h-11 flex-row items-center justify-center gap-1 active:opacity-60">
        <Text className="text-[13px] text-muted-foreground">Belum punya akun?</Text>
        <Text className="text-[13px] font-bold text-om-navy">Daftar</Text>
      </Pressable>
    </AuthShell>
  );
}
