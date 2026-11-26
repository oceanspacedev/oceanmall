import { AuthShell } from '@/components/auth-shell';
import { ErrorBanner } from '@/components/error-banner';
import { Field } from '@/components/field';
import { PasswordChecklist } from '@/components/password-checklist';
import { PasswordField } from '@/components/password-field';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, apiFieldErrors, errorMessage } from '@/lib/api';
import { PASSWORD_RULES } from '@/lib/password-rules';
import { emailError, isEmail } from '@/lib/validate';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { CheckCircle2 } from 'lucide-react-native';
import { useState } from 'react';
import { Keyboard, View } from 'react-native';

export default function ResetPasswordScreen() {
  const params = useLocalSearchParams<{ token?: string; email?: string }>();
  const router = useRouter();
  const [token, setToken] = useState(typeof params.token === 'string' ? params.token : '');
  const [email, setEmail] = useState(typeof params.email === 'string' ? params.email : '');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [touched, setTouched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [done, setDone] = useState(false);

  const allChecksPass = PASSWORD_RULES.every((rule) => rule.test(password));
  const canSubmit =
    !busy &&
    token.trim().length > 0 &&
    isEmail(email.trim()) &&
    allChecksPass &&
    password === confirm;

  async function submit() {
    if (!canSubmit) {
      setTouched(true);
      return;
    }
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      await api('/auth/reset-password', {
        method: 'POST',
        body: JSON.stringify({
          token: token.trim(),
          email: email.trim(),
          password,
          password_confirmation: confirm,
        }),
      });
      Keyboard.dismiss();
      setDone(true);
    } catch (e) {
      setFieldErrors(apiFieldErrors(e));
      setError(errorMessage(e, 'Reset kata sandi gagal.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthShell title="Buat kata sandi baru" subtitle="Masukkan kata sandi barumu di bawah.">
      {done ? (
        <View className="items-center">
          <View className="size-24 items-center justify-center rounded-full bg-om-success-soft">
            <Icon as={CheckCircle2} size={40} className="text-om-success" />
          </View>
          <Text className="mt-4 text-center text-[18px] font-bold">Kata sandi diperbarui</Text>
          <Text className="mt-2 text-center text-[13px] text-muted-foreground">
            Silakan masuk kembali dengan kata sandi baru.
          </Text>
          <Button className="mt-6 min-h-12 w-full rounded-sm" onPress={() => router.replace('/login')}>
            <Text className="font-bold">Masuk</Text>
          </Button>
        </View>
      ) : (
        <View>
          {error ? <ErrorBanner message={error} className="mx-0 mb-4 mt-0" /> : null}
          <Field
            label="Email"
            value={email}
            onChangeText={setEmail}
            onBlur={() => setTouched(true)}
            error={fieldErrors.email?.[0] ?? (touched ? emailError(email) : null)}
            keyboardType="email-address"
            autoCapitalize="none"
          />
          <Field
            label="Token reset"
            value={token}
            onChangeText={setToken}
            error={fieldErrors.token?.[0]}
            autoCapitalize="none"
          />
          <PasswordField
            label="Kata sandi baru"
            value={password}
            onChangeText={setPassword}
            error={fieldErrors.password?.[0]}
          />
          <PasswordChecklist value={password} />
          <PasswordField
            label="Konfirmasi kata sandi"
            value={confirm}
            onChangeText={setConfirm}
            error={
              confirm.length > 0 && password !== confirm ? 'Kata sandi belum cocok.' : null
            }
          />
          <Button loading={busy} disabled={!canSubmit} onPress={() => void submit()} className="mt-4 min-h-12 rounded-sm">
            <Text className="font-bold">Simpan kata sandi baru</Text>
          </Button>
        </View>
      )}
    </AuthShell>
  );
}
