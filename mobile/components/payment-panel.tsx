import { CopyButton } from '@/components/shop';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import type { PaymentInstructions } from '@/lib/api';
import { formatDateTime, formatIdr, paymentTypeLabel } from '@/lib/format';
import { Clock, ExternalLink } from 'lucide-react-native';
import { Linking, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';

export function PaymentPanel({ payment }: { payment?: PaymentInstructions | null }) {
  if (!payment) {
    return (
      <Text className="text-[13px] text-muted-foreground">
        Instruksi pembayaran belum tersedia. Tekan &quot;Buat pembayaran baru&quot;.
      </Text>
    );
  }

  const qris = payment.qris_string?.trim() ?? '';
  const va = payment.virtual_account_number?.trim() ?? '';

  return (
    <View>
      {payment.expiry_date ? (
        isExpired(payment.expiry_date) ? (
          <View className="mb-3 flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2">
            <Icon as={Clock} size={14} className="text-om-sale" />
            <Text className="flex-1 text-[12px] text-om-sale">
              Batas pembayaran sudah lewat. Buat pembayaran baru untuk melanjutkan.
            </Text>
          </View>
        ) : (
          <View className="mb-3 flex-row items-center gap-2 rounded-sm bg-om-warning-soft px-3 py-2">
            <Icon as={Clock} size={14} className="text-om-warning" />
            <Text className="flex-1 text-[12px] text-om-warning">
              Bayar sebelum{' '}
              <Text className="text-[12px] font-bold text-om-warning">
                {formatDateTime(payment.expiry_date)}
              </Text>
            </Text>
          </View>
        )
      ) : null}

      <Text className="text-[12px] text-muted-foreground">
        {payment.bank_code ? `Bank ${payment.bank_code} · ` : ''}
        {paymentTypeLabel(payment.payment_type)}
      </Text>

      {va !== '' ? (
        <View className="mt-2 rounded-sm border border-border bg-om-surface p-3">
          <Text className="text-[11px] text-muted-foreground">Nomor virtual account</Text>
          <View className="mt-1 flex-row items-center justify-between gap-2">
            <Text
              selectable
              className="flex-1 text-[20px] font-extrabold tracking-wide text-foreground">
              {va}
            </Text>
            <CopyButton value={va} />
          </View>
        </View>
      ) : null}

      {payment.amount ? (
        <View className="mt-2 flex-row items-center justify-between rounded-sm border border-border bg-om-surface p-3">
          <View>
            <Text className="text-[11px] text-muted-foreground">Total pembayaran</Text>
            <Text className="mt-0.5 text-[18px] font-extrabold text-om-navy">
              {formatIdr(payment.amount)}
            </Text>
          </View>
          <CopyButton value={String(payment.amount)} />
        </View>
      ) : null}

      {qris !== '' ? (
        <View className="mt-3 items-center rounded-sm border border-border bg-white p-4">
          <QRCode value={qris} size={190} />
          <Text className="mt-3 text-center text-[12px] text-muted-foreground">
            Scan QRIS dari aplikasi m-banking atau e-wallet (GoPay, OVO, DANA, ShopeePay).
          </Text>
        </View>
      ) : null}

      {va !== '' ? (
        <View className="mt-3 gap-1">
          <Text className="text-[12px] font-semibold">Cara bayar</Text>
          <Text className="text-[12px] leading-5 text-muted-foreground">
            1. Buka m-banking / ATM {payment.bank_code ?? ''}, pilih menu Transfer → Virtual
            Account.{'\n'}
            2. Masukkan nomor virtual account di atas.{'\n'}
            3. Pastikan nominal sesuai, lalu konfirmasi. Status terbarui otomatis.
          </Text>
        </View>
      ) : null}

      {payment.payment_url ? (
        <Button
          variant="outline"
          className="mt-3 rounded-sm border-om-navy"
          onPress={() => void Linking.openURL(payment.payment_url as string)}>
          <Icon as={ExternalLink} size={14} className="text-om-navy" />
          <Text className="font-bold text-om-navy">Buka halaman bayar</Text>
        </Button>
      ) : null}
    </View>
  );
}

function isExpired(value: string): boolean {
  const time = new Date(value).getTime();
  return !Number.isNaN(time) && time < Date.now();
}
