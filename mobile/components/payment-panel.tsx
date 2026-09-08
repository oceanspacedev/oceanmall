import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import type { PaymentInstructions } from '@/lib/api';
import { formatIdr } from '@/lib/format';
import { Linking, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';

export function PaymentPanel({ payment }: { payment?: PaymentInstructions | null }) {
  if (!payment) {
    return null;
  }

  const qris = payment.qris_string?.trim() ?? '';

  return (
    <View className="gap-1.5">
      <Text className="text-[13px] font-bold text-om-navy">Instruksi pembayaran</Text>
      {payment.payment_type ? (
        <Text className="text-[12px] text-muted-foreground">{payment.payment_type.toUpperCase()}</Text>
      ) : null}
      {payment.amount ? (
        <Text className="text-[14px] font-extrabold text-om-navy">{formatIdr(payment.amount)}</Text>
      ) : null}
      {payment.bank_code ? <Text className="text-[13px]">Bank {payment.bank_code}</Text> : null}
      {payment.virtual_account_number ? (
        <Text selectable className="text-[16px] font-bold">
          VA {payment.virtual_account_number}
        </Text>
      ) : null}
      {qris !== '' ? (
        <View className="mt-2 items-center rounded-lg border border-border bg-white p-3">
          <QRCode value={qris} size={180} />
          <Text variant="muted" className="mt-2 text-center text-[11px]">
            Scan QRIS dari aplikasi bank / e-wallet.
          </Text>
        </View>
      ) : null}
      {payment.expiry_date ? (
        <Text className="text-[12px] text-muted-foreground">Berlaku sampai {payment.expiry_date}</Text>
      ) : null}
      {payment.payment_url ? (
        <Button className="mt-2" onPress={() => void Linking.openURL(payment.payment_url as string)}>
          <Text>Buka halaman bayar</Text>
        </Button>
      ) : null}
    </View>
  );
}
