import { AuthShell } from '@/components/auth-shell';
import { ErrorBanner } from '@/components/error-banner';
import { Field } from '@/components/field';
import { PasswordChecklist } from '@/components/password-checklist';
import { PasswordField } from '@/components/password-field';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { announce } from '@/lib/a11y';
import { apiFieldErrors, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { PASSWORD_RULES_STRING, passwordMeetsRules } from '@/lib/password-rules';
import { DEFAULT_AUTH_REDIRECT, authContext, leaveAuth, safeInternalPath } from '@/lib/redirect';
import {
  PASSWORD_MISMATCH_ERROR,
  REQUIRED_ERROR,
  emailError,
  isEmail,
  phoneError,
  required,
} from '@/lib/validate';
import { setWelcomePending } from '@/lib/welcome';
import { useLocalSearchParams, useNavigation, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import {
  Keyboard,
  Platform,
  Pressable,
  ScrollView,
  TextInput,
  View,
  type HostInstance,
} from 'react-native';

type FieldKey = 'firstName' | 'lastName' | 'email' | 'phone' | 'password' | 'confirm';

/** Top to bottom, so the first invalid field is the first one that has an error here. */
const FIELD_ORDER: FieldKey[] = ['firstName', 'lastName', 'email', 'phone', 'password', 'confirm'];

/** Server field names (Laravel validation keys) to the form's fields. */
const SERVER_FIELD: Record<string, FieldKey> = {
  first_name: 'firstName',
  last_name: 'lastName',
  email: 'email',
  phone_number: 'phone',
  password: 'password',
};

type FieldMap = Partial<Record<FieldKey, string | null>>;

const LINK_SLOP = { top: 14, bottom: 14, left: 12, right: 12 };

export default function RegisterScreen() {
  const { register, user } = useAuth();
  const router = useRouter();
  const navigation = useNavigation();
  const params = useLocalSearchParams<{ redirect?: string }>();
  const next = safeInternalPath(params.redirect, DEFAULT_AUTH_REDIRECT);
  const context = authContext(params.redirect, 'register');

  const scrollRef = useRef<ScrollView>(null);
  const refs: Record<FieldKey, React.RefObject<TextInput | null>> = {
    firstName: useRef<TextInput>(null),
    lastName: useRef<TextInput>(null),
    email: useRef<TextInput>(null),
    phone: useRef<TextInput>(null),
    password: useRef<TextInput>(null),
    confirm: useRef<TextInput>(null),
  };

  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [touched, setTouched] = useState<Partial<Record<FieldKey, boolean>>>({});
  const [serverErrors, setServerErrors] = useState<FieldMap>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // Set before the request goes out, so the navigation effect below knows this user just signed up.
  const justRegistered = useRef(false);
  const left = useRef(false);

  // The one place that leaves this screen (see login.tsx). A fresh account on its way to the
  // Akun tab gets the welcome banner there; one on its way to checkout does not.
  useEffect(() => {
    if (!user || !navigation.isFocused() || left.current) {
      return;
    }
    left.current = true;
    if (justRegistered.current && next === DEFAULT_AUTH_REDIRECT) {
      setWelcomePending(true);
    }
    leaveAuth(router, next);
  }, [navigation, next, router, user]);

  const clientErrors: FieldMap = {
    firstName: required(firstName),
    lastName: required(lastName),
    email: emailError(email),
    phone: phoneError(phone),
    password:
      password === ''
        ? REQUIRED_ERROR
        : passwordMeetsRules(password)
          ? null
          : 'Kata sandi belum memenuhi syarat',
    confirm:
      confirm === '' ? REQUIRED_ERROR : confirm !== password ? PASSWORD_MISMATCH_ERROR : null,
  };

  // The confirm field also reports a mismatch while typing, once it is as long as the password.
  const confirmEager = confirm.length > 0 && confirm.length >= password.length;
  const visible = (key: FieldKey): boolean =>
    Boolean(touched[key]) || (key === 'confirm' && confirmEager);
  const fieldError = (key: FieldKey): string | null =>
    serverErrors[key] ?? (visible(key) ? (clientErrors[key] ?? null) : null);

  // Not gated on `busy`: a busy button shows its spinner at full strength instead of dimmed.
  const valid =
    firstName.trim() !== '' &&
    lastName.trim() !== '' &&
    isEmail(email) &&
    phoneError(phone) === null &&
    passwordMeetsRules(password) &&
    password === confirm;

  const touch = (key: FieldKey) => setTouched((current) => ({ ...current, [key]: true }));

  /** Edits clear the server's complaint about that field, so the border does not stay red. */
  const edit = (key: FieldKey, set: (value: string) => void) => (value: string) => {
    set(value);
    setServerErrors((current) => (current[key] ? { ...current, [key]: null } : current));
  };

  function scrollToInput(input: TextInput | null) {
    const scroll = scrollRef.current as unknown as {
      getInnerViewRef?: () => HostInstance | null;
    } | null;
    const inner = scroll?.getInnerViewRef?.();
    if (!input || !inner) {
      return;
    }
    // Wait a beat: the error rows and the closing keyboard both move the layout.
    setTimeout(() => {
      input.measureLayout(
        inner,
        (_x, y) => scrollRef.current?.scrollTo({ y: Math.max(0, y - 96), animated: true }),
        () => {
          // Not measurable (unmounted): leave the scroll position alone.
        }
      );
    }, 80);
  }

  function showFirstInvalid(errors: FieldMap) {
    const first = FIELD_ORDER.find((key) => errors[key]);
    if (first) {
      scrollToInput(refs[first].current);
      if (Platform.OS === 'ios') {
        // Android reads the field's live region; iOS needs the explicit announcement.
        announce(errors[first] ?? '');
      }
    }
  }

  async function submit() {
    if (busy) {
      return;
    }
    if (!valid) {
      // The return key on the last field can get here with something still wrong.
      setTouched({
        firstName: true,
        lastName: true,
        email: true,
        phone: true,
        password: true,
        confirm: true,
      });
      Keyboard.dismiss();
      showFirstInvalid(clientErrors);
      return;
    }

    setBusy(true);
    setError(null);
    setServerErrors({});
    justRegistered.current = true;
    try {
      await register({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        email: email.trim(),
        phone_number: phone.trim() || undefined,
        password,
        password_confirmation: confirm,
      });
      // Stay busy: the effect above navigates as soon as the user is set.
    } catch (e) {
      justRegistered.current = false;
      setBusy(false);
      Keyboard.dismiss();

      const mapped: FieldMap = {};
      const unmapped: string[] = [];
      for (const [key, messages] of Object.entries(apiFieldErrors(e))) {
        const field = SERVER_FIELD[key];
        if (field) {
          mapped[field] = messages.join(' ');
        } else {
          unmapped.push(...messages);
        }
      }
      setServerErrors(mapped);

      if (Object.keys(mapped).length === 0 && unmapped.length === 0) {
        // Offline, a throttle, a server error: no field to blame.
        setError(errorMessage(e, 'Gagal mendaftar'));
        scrollRef.current?.scrollToEnd({ animated: true });
      } else {
        setError(unmapped.length > 0 ? unmapped.join(' ') : null);
        showFirstInvalid(mapped);
      }
    }
  }

  const toLogin = () =>
    router.replace({
      pathname: '/login',
      params: params.redirect ? { redirect: params.redirect } : {},
    });

  const openLegal = (slug: 'syarat-ketentuan' | 'kebijakan-privasi') =>
    // push, not replace: the typed form is still here when the customer comes back.
    router.push(`/legal/${slug}`);

  return (
    <AuthShell title={context.title} subtitle={context.subtitle} scrollRef={scrollRef}>
      <View className="gap-4">
        <View className="flex-row items-start gap-3">
          <View className="flex-1">
            <Field
              ref={refs.firstName}
              label="Nama depan"
              value={firstName}
              onChangeText={edit('firstName', setFirstName)}
              onBlur={() => touch('firstName')}
              error={fieldError('firstName')}
              autoCapitalize="words"
              autoComplete="given-name"
              placeholder="Contoh: Budi"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => refs.lastName.current?.focus()}
            />
          </View>
          <View className="flex-1">
            <Field
              ref={refs.lastName}
              label="Nama belakang"
              value={lastName}
              onChangeText={edit('lastName', setLastName)}
              onBlur={() => touch('lastName')}
              error={fieldError('lastName')}
              autoCapitalize="words"
              autoComplete="family-name"
              placeholder="Contoh: Santoso"
              returnKeyType="next"
              submitBehavior="submit"
              onSubmitEditing={() => refs.email.current?.focus()}
            />
          </View>
        </View>
        <Field
          ref={refs.email}
          label="Email"
          value={email}
          onChangeText={edit('email', setEmail)}
          onBlur={() => touch('email')}
          error={fieldError('email')}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          autoComplete="email"
          placeholder="contoh@email.com"
          returnKeyType="next"
          submitBehavior="submit"
          onSubmitEditing={() => refs.phone.current?.focus()}
        />
        <Field
          ref={refs.phone}
          label="Nomor HP"
          value={phone}
          onChangeText={edit('phone', setPhone)}
          onBlur={() => touch('phone')}
          error={fieldError('phone')}
          hint="Opsional. Dipakai kurir saat pengiriman. Bisa dilengkapi nanti di Profil."
          keyboardType="phone-pad"
          autoComplete="tel"
          placeholder="0812 3456 7890"
          // The iOS phone pad has no return key, so only Android chains onward from here.
          {...(Platform.OS === 'android'
            ? {
                returnKeyType: 'next' as const,
                submitBehavior: 'submit' as const,
                onSubmitEditing: () => refs.password.current?.focus(),
              }
            : null)}
        />
        <View className="gap-3">
          <PasswordField
            ref={refs.password}
            label="Kata sandi"
            value={password}
            onChangeText={edit('password', setPassword)}
            onBlur={() => touch('password')}
            error={fieldError('password')}
            autoComplete="new-password"
            passwordRules={PASSWORD_RULES_STRING}
            placeholder="Buat kata sandi yang kuat"
            returnKeyType="next"
            submitBehavior="submit"
            onSubmitEditing={() => refs.confirm.current?.focus()}
          />
          <PasswordChecklist value={password} />
        </View>
        <PasswordField
          ref={refs.confirm}
          label="Ulangi kata sandi"
          value={confirm}
          onChangeText={edit('confirm', setConfirm)}
          onBlur={() => touch('confirm')}
          error={fieldError('confirm')}
          autoComplete="new-password"
          placeholder="Ketik ulang kata sandi"
          returnKeyType="done"
          onSubmitEditing={() => void submit()}
        />
      </View>

      {error ? <ErrorBanner message={error} className="mx-0 mb-0 mt-5" /> : null}

      <Text
        accessibilityActions={[
          { name: 'terms', label: 'Buka Syarat & Ketentuan' },
          { name: 'privacy', label: 'Buka Kebijakan Privasi' },
        ]}
        onAccessibilityAction={(event) =>
          openLegal(
            event.nativeEvent.actionName === 'terms' ? 'syarat-ketentuan' : 'kebijakan-privasi'
          )
        }
        className="mt-6 text-center text-[12px] leading-5 text-muted-foreground">
        Dengan mendaftar, kamu menyetujui{' '}
        <Text
          accessibilityRole="link"
          className="text-[12px] font-bold leading-5 text-om-navy"
          onPress={() => openLegal('syarat-ketentuan')}>
          Syarat & Ketentuan
        </Text>{' '}
        dan{' '}
        <Text
          accessibilityRole="link"
          className="text-[12px] font-bold leading-5 text-om-navy"
          onPress={() => openLegal('kebijakan-privasi')}>
          Kebijakan Privasi
        </Text>{' '}
        OceanMall.
      </Text>
      <Button
        className="mt-3 min-h-12 rounded-sm"
        disabled={!valid}
        loading={busy}
        onPress={() => void submit()}>
        <Text className="font-bold">Daftar</Text>
      </Button>

      <Pressable
        accessibilityRole="link"
        accessibilityLabel="Sudah punya akun? Masuk"
        onPress={toLogin}
        hitSlop={LINK_SLOP}
        className="mt-5 min-h-11 flex-row items-center justify-center gap-1 active:opacity-60">
        <Text className="text-[13px] text-muted-foreground">Sudah punya akun?</Text>
        <Text className="text-[13px] font-bold text-om-navy">Masuk</Text>
      </Pressable>
    </AuthShell>
  );
}
