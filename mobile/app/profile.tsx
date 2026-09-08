import { Field } from '@/components/field';
import { PillButton } from '@/components/pill-button';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';

export default function ProfileScreen() {
  const { user, refresh } = useAuth();
  const router = useRouter();
  const [firstName, setFirstName] = useState(user?.first_name ?? '');
  const [lastName, setLastName] = useState(user?.last_name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [phone, setPhone] = useState(user?.phone_number ?? '');
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (!user) {
    router.replace({ pathname: '/login', params: { redirect: '/profile' } });
    return null;
  }

  async function saveProfile() {
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await api('/auth/profile', {
        method: 'PATCH',
        body: JSON.stringify({
          first_name: firstName.trim(),
          last_name: lastName.trim(),
          email: email.trim(),
          phone_number: phone.trim() || null,
        }),
      });
      await refresh();
      setMessage('Profil disimpan.');
    } catch (e) {
      setError(errorMessage(e, 'Gagal menyimpan profil'));
    } finally {
      setBusy(false);
    }
  }

  async function savePassword() {
    if (password.length < 8 || password !== confirm) {
      setError('Kata sandi baru minimal 8 karakter dan harus sama.');
      return;
    }
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await api('/auth/password', {
        method: 'PUT',
        body: JSON.stringify({
          current_password: currentPassword,
          password,
          password_confirmation: confirm,
        }),
      });
      setCurrentPassword('');
      setPassword('');
      setConfirm('');
      setMessage('Kata sandi diperbarui.');
    } catch (e) {
      setError(errorMessage(e, 'Gagal mengubah kata sandi'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title="Profil">
      <KeyboardAvoidingView
        className="flex-1"
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerClassName="px-3 pb-10 pt-4" keyboardShouldPersistTaps="handled">
          {error ? <Text className="mb-3 text-[12px] text-destructive">{error}</Text> : null}
          {message ? <Text className="mb-3 text-[12px] text-om-navy">{message}</Text> : null}

          <Text className="text-[13px] font-bold text-om-navy">Data akun</Text>
          <View className="mt-3 gap-3">
            <View className="flex-row gap-2.5">
              <View className="flex-1">
                <Field label="Nama depan" value={firstName} onChangeText={setFirstName} />
              </View>
              <View className="flex-1">
                <Field label="Nama belakang" value={lastName} onChangeText={setLastName} />
              </View>
            </View>
            <Field
              label="Email"
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              keyboardType="email-address"
            />
            <Field label="Nomor HP" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
          </View>
          <View className="mt-4">
            <PillButton
              label={busy ? 'Menyimpan...' : 'Simpan profil'}
              disabled={busy || !firstName.trim() || !lastName.trim() || !email.trim()}
              onPress={() => void saveProfile()}
            />
          </View>

          <Text className="mt-7 text-[13px] font-bold text-om-navy">Ubah kata sandi</Text>
          <View className="mt-3 gap-3">
            <Field
              label="Kata sandi saat ini"
              value={currentPassword}
              onChangeText={setCurrentPassword}
              secureTextEntry
            />
            <Field label="Kata sandi baru" value={password} onChangeText={setPassword} secureTextEntry />
            <Field label="Ulangi kata sandi baru" value={confirm} onChangeText={setConfirm} secureTextEntry />
          </View>
          <View className="mt-4">
            <PillButton
              outline
              label="Perbarui kata sandi"
              disabled={busy || !currentPassword || !password || !confirm}
              onPress={() => void savePassword()}
            />
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </Screen>
  );
}
