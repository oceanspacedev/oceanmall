import { Screen } from '@/components/screen';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Text } from '@/components/ui/text';
import { api, errorMessage } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useRouter } from 'expo-router';
import { Check, CircleAlert, Eye, EyeOff } from 'lucide-react-native';
import { useState } from 'react';
import { Alert as RNAlert, Pressable, View } from 'react-native';

export default function ChangePasswordScreen() {
  const router = useRouter();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const longEnough = password.length >= 8;
  const matches = confirm.length > 0 && password === confirm;
  const differs = password.length > 0 && password !== current;
  const canSave = current.length > 0 && longEnough && matches && differs && !busy;

  async function save() {
    setBusy(true);
    setError(null);
    try {
      await api('/auth/password', {
        method: 'PUT',
        body: JSON.stringify({
          current_password: current,
          password,
          password_confirmation: confirm,
        }),
      });
      RNAlert.alert(
        'Kata sandi diperbarui',
        'Perangkat lain yang masuk ke akunmu sudah dikeluarkan.',
        [{ text: 'OK', onPress: () => router.back() }]
      );
    } catch (e) {
      setError(errorMessage(e, 'Gagal mengubah kata sandi'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title="Ubah kata sandi" surface>
      <View className="flex-1">
        <View className="mt-2 gap-4 bg-white px-4 py-5">
          <PasswordField
            label="Kata sandi saat ini"
            value={current}
            onChangeText={setCurrent}
            autoFocus
          />
          <PasswordField label="Kata sandi baru" value={password} onChangeText={setPassword} />
          <PasswordField label="Ulangi kata sandi baru" value={confirm} onChangeText={setConfirm} />
          <View className="gap-1.5">
            <Rule ok={longEnough} text="Minimal 8 karakter" />
            <Rule ok={differs} text="Berbeda dari kata sandi saat ini" />
            <Rule ok={matches} text="Kedua kata sandi baru sama" />
          </View>
          <View className="mt-2">
            {error ? (
              <View className="mb-2.5 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2">
                <Icon as={CircleAlert} size={15} className="text-om-sale" />
                <Text className="flex-1 text-[12px] text-om-sale">{error}</Text>
              </View>
            ) : null}
            <Button disabled={!canSave} onPress={() => void save()} className="h-11 rounded-sm">
              <Text className="font-bold">{busy ? 'Menyimpan...' : 'Simpan kata sandi'}</Text>
            </Button>
          </View>
        </View>
      </View>
    </Screen>
  );
}

function PasswordField({
  label,
  value,
  onChangeText,
  autoFocus,
}: {
  label: string;
  value: string;
  onChangeText: (value: string) => void;
  autoFocus?: boolean;
}) {
  const [visible, setVisible] = useState(false);

  return (
    <View className="gap-1.5">
      <Label className="text-xs text-muted-foreground">{label}</Label>
      <View className="justify-center">
        <Input
          value={value}
          onChangeText={onChangeText}
          secureTextEntry={!visible}
          autoCapitalize="none"
          autoCorrect={false}
          autoFocus={autoFocus}
          className="pr-11"
        />
        <Pressable
          accessibilityLabel={visible ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
          onPress={() => setVisible((current) => !current)}
          hitSlop={8}
          className="absolute right-3">
          <Icon as={visible ? EyeOff : Eye} size={18} className="text-muted-foreground" />
        </Pressable>
      </View>
    </View>
  );
}

function Rule({ ok, text }: { ok: boolean; text: string }) {
  return (
    <View className="flex-row items-center gap-2">
      <View
        className={cn(
          'size-4 items-center justify-center rounded-full',
          ok ? 'bg-om-success' : 'bg-om-disabled'
        )}>
        <Icon as={Check} size={10} color="#fff" />
      </View>
      <Text className={cn('text-[12px]', ok ? 'text-om-success' : 'text-muted-foreground')}>
        {text}
      </Text>
    </View>
  );
}
