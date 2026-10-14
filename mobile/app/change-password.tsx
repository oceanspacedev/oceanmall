import { ErrorBanner } from '@/components/error-banner';
import { FormScroll } from '@/components/field';
import { PasswordChecklist } from '@/components/password-checklist';
import { PasswordField } from '@/components/password-field';
import { Screen } from '@/components/screen';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { api, apiFieldErrors, errorMessage } from '@/lib/api';
import { PASSWORD_RULES_STRING, passwordMeetsRules } from '@/lib/password-rules';
import { toast } from '@/lib/toast';
import { PASSWORD_MISMATCH_ERROR } from '@/lib/validate';
import { useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Keyboard, TextInput, View } from 'react-native';

export default function ChangePasswordScreen() {
  const router = useRouter();
  const passwordRef = useRef<TextInput>(null);
  const confirmRef = useRef<TextInput>(null);
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [confirmTouched, setConfirmTouched] = useState(false);
  const [currentError, setCurrentError] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const matches = confirm.length > 0 && password === confirm;
  const differs = password.length > 0 && password !== current;
  // Not gated on `busy`: a busy button shows its spinner at full strength instead of dimmed.
  const canSave = current.length > 0 && passwordMeetsRules(password) && matches && differs;

  const showMismatch =
    confirm.length > 0 && !matches && (confirmTouched || confirm.length >= password.length);

  async function save() {
    if (busy || !canSave) {
      return;
    }
    setBusy(true);
    setError(null);
    setCurrentError(null);
    setPasswordError(null);
    try {
      await api('/auth/password', {
        method: 'PUT',
        body: JSON.stringify({
          current_password: current,
          password,
          password_confirmation: confirm,
        }),
      });
      // The toast host sits above the navigator, so it survives the pop.
      router.back();
      toast.show({ tone: 'success', message: 'Kata sandi diperbarui' });
    } catch (e) {
      setBusy(false);
      Keyboard.dismiss();
      const fields = apiFieldErrors(e);
      const currentMessage = fields.current_password?.join(' ') ?? null;
      const passwordMessage = fields.password?.join(' ') ?? null;
      setCurrentError(currentMessage);
      setPasswordError(passwordMessage);
      // Anything that is not about one of the two fields (offline, a throttle) goes in the banner.
      setError(
        currentMessage || passwordMessage ? null : errorMessage(e, 'Gagal mengubah kata sandi')
      );
    }
  }

  return (
    <Screen title="Ubah kata sandi" surface>
      <FormScroll>
        <View className="mt-2 gap-4 bg-white px-4 py-5">
          <PasswordField
            label="Kata sandi saat ini"
            value={current}
            onChangeText={(value) => {
              setCurrent(value);
              setCurrentError(null);
            }}
            error={currentError}
            autoFocus
            autoComplete="current-password"
            placeholder="Kata sandi lama"
            returnKeyType="next"
            submitBehavior="submit"
            onSubmitEditing={() => passwordRef.current?.focus()}
          />
          <PasswordField
            ref={passwordRef}
            label="Kata sandi baru"
            value={password}
            onChangeText={(value) => {
              setPassword(value);
              setPasswordError(null);
            }}
            error={passwordError}
            autoComplete="new-password"
            passwordRules={PASSWORD_RULES_STRING}
            placeholder="Buat kata sandi yang kuat"
            returnKeyType="next"
            submitBehavior="submit"
            onSubmitEditing={() => confirmRef.current?.focus()}
          />
          <PasswordField
            ref={confirmRef}
            label="Ulangi kata sandi baru"
            value={confirm}
            onChangeText={setConfirm}
            onBlur={() => setConfirmTouched(true)}
            error={showMismatch ? PASSWORD_MISMATCH_ERROR : null}
            autoComplete="new-password"
            placeholder="Ketik ulang kata sandi baru"
            returnKeyType="done"
            onSubmitEditing={() => void save()}
          />
          <PasswordChecklist
            value={password}
            extra={[
              { key: 'differs', label: 'Berbeda dari kata sandi saat ini', met: differs },
              { key: 'matches', label: 'Kedua kata sandi baru sama', met: matches },
            ]}
          />
          <View className="mt-2">
            {error ? <ErrorBanner message={error} className="mx-0 mb-2.5 mt-0" /> : null}
            <Button
              disabled={!canSave}
              loading={busy}
              onPress={() => void save()}
              className="min-h-11 rounded-sm">
              <Text className="font-bold">Simpan kata sandi</Text>
            </Button>
          </View>
        </View>
      </FormScroll>
    </Screen>
  );
}
