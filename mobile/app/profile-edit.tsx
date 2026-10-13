import { Field } from '@/components/field';
import { Screen } from '@/components/screen';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { CircleAlert } from 'lucide-react-native';
import { useState } from 'react';
import { View } from 'react-native';

type EditField = 'name' | 'email' | 'phone';

const COPY: Record<EditField, { title: string; hint: string }> = {
  name: {
    title: 'Ubah nama',
    hint: 'Nama ini dipakai sebagai penerima di alamat baru dan ulasanmu.',
  },
  email: {
    title: 'Ubah email',
    hint: 'Email dipakai untuk masuk dan menerima tautan reset kata sandi.',
  },
  phone: {
    title: 'Ubah nomor HP',
    hint: 'Nomor HP membantu kurir menghubungimu saat mengantar paket.',
  },
};

/** Edits one profile attribute at a time, marketplace-style. */
export default function ProfileEditScreen() {
  const { user, refresh } = useAuth();
  const router = useRouter();
  const params = useLocalSearchParams<{ field?: string }>();
  const field: EditField =
    params.field === 'email' || params.field === 'phone' ? params.field : 'name';
  const [firstName, setFirstName] = useState(user?.first_name ?? '');
  const [lastName, setLastName] = useState(user?.last_name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [phone, setPhone] = useState(user?.phone_number ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!user) {
    return <Screen title={COPY[field].title} />;
  }

  const changed =
    field === 'name'
      ? firstName.trim() !== user.first_name || lastName.trim() !== user.last_name
      : field === 'email'
        ? email.trim().toLowerCase() !== user.email
        : phone.trim() !== (user.phone_number ?? '');
  const valid =
    field === 'name'
      ? firstName.trim() !== '' && lastName.trim() !== ''
      : field === 'email'
        ? /^\S+@\S+\.\S+$/.test(email.trim())
        : phone.trim() === '' || /^[0-9+ ]{8,20}$/.test(phone.trim());

  async function save() {
    if (!user) {
      return;
    }
    setBusy(true);
    setError(null);
    try {
      // The API updates the whole profile; send the untouched values as they are.
      await api('/auth/profile', {
        method: 'PATCH',
        body: JSON.stringify({
          first_name: field === 'name' ? firstName.trim() : user.first_name,
          last_name: field === 'name' ? lastName.trim() : user.last_name,
          email: field === 'email' ? email.trim() : user.email,
          phone_number: field === 'phone' ? phone.trim() || null : (user.phone_number ?? null),
        }),
      });
      await refresh();
      router.back();
    } catch (e) {
      setError(errorMessage(e, 'Gagal menyimpan perubahan'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title={COPY[field].title} surface>
      <View className="flex-1">
        <View className="mt-2 gap-3 bg-white px-4 py-5">
          {field === 'name' ? (
            <>
              <Field
                label="Nama depan"
                value={firstName}
                onChangeText={setFirstName}
                autoFocus
                autoComplete="given-name"
              />
              <Field
                label="Nama belakang"
                value={lastName}
                onChangeText={setLastName}
                autoComplete="family-name"
              />
            </>
          ) : field === 'email' ? (
            <Field
              label="Email"
              value={email}
              onChangeText={setEmail}
              autoFocus
              autoCapitalize="none"
              keyboardType="email-address"
              autoComplete="email"
            />
          ) : (
            <Field
              label="Nomor HP"
              value={phone}
              onChangeText={setPhone}
              autoFocus
              keyboardType="phone-pad"
              autoComplete="tel"
              placeholder="08…"
            />
          )}
          <Text className="text-[12px] leading-5 text-muted-foreground">{COPY[field].hint}</Text>
          <View className="mt-2">
            {error ? (
              <View className="mb-2.5 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2">
                <Icon as={CircleAlert} size={15} className="text-om-sale" />
                <Text className="flex-1 text-[12px] text-om-sale">{error}</Text>
              </View>
            ) : null}
            <Button
              disabled={busy || !changed || !valid}
              onPress={() => void save()}
              className="h-11 rounded-sm">
              <Text className="font-bold">{busy ? 'Menyimpan...' : 'Simpan'}</Text>
            </Button>
          </View>
        </View>
      </View>
    </Screen>
  );
}
