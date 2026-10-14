import { Countdown } from '@/components/countdown';
import { CopyButton } from '@/components/shop';
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import type { PaymentInstructions } from '@/lib/api';
import { openPaymentPage } from '@/lib/browser';
import { useCountdown } from '@/lib/countdown';
import { formatDateTime, formatIdr, paymentTypeLabel } from '@/lib/format';
import { guideFor, qrisGuide, type PaymentGuide } from '@/lib/payment-guides';
import { toast } from '@/lib/toast';
import { Clock, ExternalLink } from 'lucide-react-native';
import { useState } from 'react';
import { View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';

/**
 * Where and how to pay: the live countdown to the deadline, the virtual account number or QR
 * code with copy buttons, per-bank "cara bayar" steps and the gateway page.
 *
 * - `onExpire` runs once when the deadline passes while the panel is on screen. The panel itself
 *   flips to the expired strip (and stops showing a number or QR that no longer works) without a
 *   reload.
 * - `onClosed` runs when the payment sheet was dismissed (iOS reports it; on Android the screen
 *   refreshes when the app returns to the foreground instead).
 * - `compact` is for the order-placed screen, which shows the deadline and the amount in its own
 *   header: it hides the expiry row, the amount row and the how-to steps.
 */
export function PaymentPanel({
  payment,
  onExpire,
  onClosed,
  compact = false,
}: {
  payment?: PaymentInstructions | null;
  onExpire?: () => void;
  onClosed?: () => void;
  compact?: boolean;
}) {
  // Hooks first: the early return below must not change how many run.
  const countdown = useCountdown(compact ? null : payment?.expiry_date, onExpire);
  const [opening, setOpening] = useState(false);

  if (!payment) {
    return (
      <Text className="text-[13px] text-muted-foreground">
        Instruksi pembayaran belum tersedia. Tekan &quot;Buat pembayaran baru&quot;.
      </Text>
    );
  }

  const qris = payment.qris_string?.trim() ?? '';
  const va = payment.virtual_account_number?.trim() ?? '';
  const paymentUrl = payment.payment_url?.trim() ?? '';
  const expired = countdown?.expired ?? false;
  const guides: PaymentGuide[] =
    qris !== '' ? [qrisGuide()] : va !== '' ? guideFor(payment.bank_code, va) : [];

  async function openPage() {
    if (opening) {
      return;
    }
    setOpening(true);
    try {
      const result = await openPaymentPage(paymentUrl);
      if (result === 'closed') {
        onClosed?.();
      }
    } catch {
      toast.show({ tone: 'error', message: 'Halaman bayar belum bisa dibuka. Coba lagi, ya.' });
    } finally {
      setOpening(false);
    }
  }

  if (expired) {
    return (
      <View
        accessibilityRole="alert"
        className="flex-row items-center gap-2 rounded-sm bg-om-sale-soft px-3 py-2.5">
        <Icon as={Clock} size={15} className="text-om-sale" />
        <Text className="flex-1 text-[12px] leading-4 text-om-sale-text">
          Batas pembayaran sudah lewat. Buat pembayaran baru untuk melanjutkan.
        </Text>
      </View>
    );
  }

  return (
    <View>
      {countdown && !compact ? (
        <View className="mb-3 rounded-sm bg-om-warning-soft px-3 py-2.5">
          <View className="flex-row items-center gap-2">
            <Icon as={Clock} size={15} className="text-om-warning" />
            <Text className="text-[12px] font-semibold text-om-warning">Bayar dalam</Text>
            <Countdown state={countdown} />
          </View>
          {payment.expiry_date ? (
            <Text className="mt-1.5 text-[12px] text-om-warning">
              Batas bayar {formatDateTime(payment.expiry_date)}
            </Text>
          ) : null}
        </View>
      ) : null}

      <Text className="text-[12px] text-muted-foreground">
        {payment.bank_code ? `Bank ${payment.bank_code} · ` : ''}
        {paymentTypeLabel(payment.payment_type)}
      </Text>

      {va !== '' ? (
        <View className="mt-2 rounded-sm border border-border bg-om-surface p-3">
          <Text className="text-[12px] text-muted-foreground">Nomor virtual account</Text>
          <View className="mt-1 flex-row items-center justify-between gap-2">
            <Text
              selectable
              className="flex-1 text-[20px] font-extrabold tracking-wide text-foreground">
              {va}
            </Text>
            <CopyButton value={va} label="Salin nomor" />
          </View>
        </View>
      ) : null}

      {payment.amount && !compact ? (
        <View className="mt-2 flex-row items-center justify-between rounded-sm border border-border bg-om-surface p-3">
          <View>
            <Text className="text-[12px] text-muted-foreground">Total pembayaran</Text>
            <Text className="mt-0.5 text-[18px] font-extrabold text-om-navy">
              {formatIdr(payment.amount)}
            </Text>
          </View>
          <CopyButton value={String(payment.amount)} label="Salin nominal" />
        </View>
      ) : null}

      {qris !== '' ? (
        <View className="mt-3 items-center rounded-sm border border-border bg-white p-4">
          <View accessible accessibilityRole="image" accessibilityLabel="Kode QRIS">
            <QRCode value={qris} size={190} />
          </View>
          <Text className="mt-3 text-center text-[12px] leading-4 text-muted-foreground">
            Scan QRIS dari aplikasi m-banking atau e-wallet kamu.
          </Text>
        </View>
      ) : null}

      {!compact && guides.length > 0 ? (
        <View className="mt-4">
          <Text role="heading" aria-level={3} className="text-[13px] font-bold">
            Cara bayar
          </Text>
          <Accordion type="single" collapsible defaultValue={guides[0].title} className="mt-1">
            {guides.map((guide) => (
              <AccordionItem key={guide.title} value={guide.title}>
                <AccordionTrigger>
                  <Text className="flex-1 text-[13px] font-semibold">{guide.title}</Text>
                </AccordionTrigger>
                <AccordionContent>
                  <View className="gap-2">
                    {guide.steps.map((step, index) => (
                      <View key={index} className="flex-row gap-2">
                        <Text className="w-5 text-[12px] leading-5 text-muted-foreground">
                          {index + 1}.
                        </Text>
                        <Text className="flex-1 text-[13px] leading-5 text-foreground">{step}</Text>
                      </View>
                    ))}
                  </View>
                </AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
          <Text className="mt-2 text-[12px] leading-4 text-muted-foreground">
            Status pesanan diperbarui otomatis setelah kamu membayar.
          </Text>
        </View>
      ) : null}

      {paymentUrl !== '' ? (
        <Button
          variant="outline"
          loading={opening}
          className="mt-3 min-h-11 rounded-sm border-om-navy"
          onPress={() => void openPage()}>
          <Icon as={ExternalLink} size={14} className="text-om-navy" />
          <Text className="font-bold text-om-navy">Buka halaman bayar</Text>
        </Button>
      ) : null}
    </View>
  );
}
