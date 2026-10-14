import { ErrorBanner } from '@/components/error-banner';
import { Field, FormScroll } from '@/components/field';
import { Screen } from '@/components/screen';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { api, apiFieldErrors, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { toast } from '@/lib/toast';
import { emailError, isEmail, phoneError, required } from '@/lib/validate';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Keyboard, TextInput, View } from 'react-native';

type EditField = 'name' | 'email' | 'phone';
type InputKey = 'firstName' | 'lastName' | 'email' | 'phone';

/** Server (Laravel) field names to the inputs on this screen. */
const SERVER_FIELD: Record<string, InputKey> = {
  first_name: 'firstName',
  last_name: 'lastName',
  email: 'email',
  phone_number: 'phone',
};

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
  const lastNameRef = useRef<TextInput>(null);
  const [firstName, setFirstName] = useState(user?.first_name ?? '');
  const [lastName, setLastName] = useState(user?.last_name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [phone, setPhone] = useState(user?.phone_number ?? '');
  const [touched, setTouched] = useState<Partial<Record<InputKey, boolean>>>({});
  const [serverErrors, setServerErrors] = useState<Partial<Record<InputKey, string | null>>>({});
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
        ? isEmail(email)
        : phoneError(phone) === null;

  const clientErrors: Record<InputKey, string | null> = {
    firstName: required(firstName),
    lastName: required(lastName),
    email: emailError(email),
    phone: phoneError(phone),
  };
  const fieldError = (key: InputKey): string | null =>
    serverErrors[key] ?? (touched[key] ? clientErrors[key] : null);
  const touch = (key: InputKey) => setTouched((current) => ({ ...current, [key]: true }));
  const edit = (key: InputKey, set: (value: string) => void) => (value: string) => {
    set(value);
    setServerErrors((current) => (current[key] ? { ...current, [key]: null } : current));
  };

  async function save() {
    if (!user || busy || !changed || !valid) {
      return;
    }
    setBusy(true);
    setError(null);
    setServerErrors({});
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
      // The toast host sits above the navigator, so it outlives the pop.
      toast.show({ tone: 'success', message: 'Profil diperbarui' });
      router.back();
    } catch (e) {
      setBusy(false);
      Keyboard.dismiss();
      const mapped: Partial<Record<InputKey, string | null>> = {};
      for (const [key, messages] of Object.entries(apiFieldErrors(e))) {
        const input = SERVER_FIELD[key];
        if (input) {
          mapped[input] = messages.join(' ');
        }
      }
      setServerErrors(mapped);
      setError(
        Object.keys(mapped).length > 0 ? null : errorMessage(e, 'Gagal menyimpan perubahan')
      );
    }
  }

  return (
    <Screen title={COPY[field].title} surface>
      <FormScroll>
        <View className="mt-2 gap-3 bg-white px-4 py-5">
          {field === 'name' ? (
            <>
              <Field
                label="Nama depan"
                value={firstName}
                onChangeText={edit('firstName', setFirstName)}
                onBlur={() => touch('firstName')}
                error={fieldError('firstName')}
                autoFocus
                autoCapitalize="words"
                autoComplete="given-name"
                placeholder="Contoh: Budi"
                returnKeyType="next"
                submitBehavior="submit"
                onSubmitEditing={() => lastNameRef.current?.focus()}
              />
              <Field
                ref={lastNameRef}
                label="Nama belakang"
                value={lastName}
                onChangeText={edit('lastName', setLastName)}
                onBlur={() => touch('lastName')}
                error={fieldError('lastName')}
                autoCapitalize="words"
                autoComplete="family-name"
                placeholder="Contoh: Santoso"
                returnKeyType="done"
                onSubmitEditing={() => void save()}
              />
            </>
          ) : field === 'email' ? (
            <Field
              label="Email"
              value={email}
              onChangeText={edit('email', setEmail)}
              onBlur={() => touch('email')}
              error={fieldError('email')}
              autoFocus
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
              autoComplete="email"
              placeholder="contoh@email.com"
              returnKeyType="done"
              onSubmitEditing={() => void save()}
            />
          ) : (
            <Field
              label="Nomor HP"
              value={phone}
              onChangeText={edit('phone', setPhone)}
              onBlur={() => touch('phone')}
              error={fieldError('phone')}
              autoFocus
              keyboardType="phone-pad"
              autoComplete="tel"
              placeholder="0812 3456 7890"
            />
          )}
          <Text className="text-[12px] leading-5 text-muted-foreground">{COPY[field].hint}</Text>
          <View className="mt-2">
            {error ? <ErrorBanner message={error} className="mx-0 mb-2.5 mt-0" /> : null}
            <Button
              disabled={!changed || !valid}
              loading={busy}
              onPress={() => void save()}
              className="min-h-11 rounded-sm">
              <Text className="font-bold">Simpan</Text>
            </Button>
          </View>
        </View>
      </FormScroll>
    </Screen>
  );
}
