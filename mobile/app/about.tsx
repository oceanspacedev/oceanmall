import { BrandLogo } from '@/components/brand-logo';
import { Screen } from '@/components/screen';
import { MenuRow, Section } from '@/components/shop';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { APP_VERSION_LABEL } from '@/lib/legal-content';
import { openSupportChannel, supportChannels } from '@/lib/support';
import { ripple } from '@/lib/ripple';
import { cn } from '@/lib/utils';
import { useRouter, type Href } from 'expo-router';
import {
  ChevronDown,
  CircleQuestionMark,
  FileText,
  Mail,
  MessageCircle,
  ShieldCheck,
} from 'lucide-react-native';
import { useState } from 'react';
import { Platform, Pressable, ScrollView, View } from 'react-native';

/** The one tagline the app uses (the intro's last slide says the same). */
const TAGLINE = 'Belanja Lebih Luas, Pilihan Tanpa Batas';

type Faq = { key: string; question: string; answer: string };

/*
 * Only things the app and the backend really do. No claims about returns, warranty or delivery
 * times until the owner supplies them (see TODO in lib/legal-content.ts).
 */
const FAQS: Faq[] = [
  {
    key: 'bayar',
    question: 'Bagaimana cara membayar pesanan?',
    answer:
      'Pilih metode pembayaran saat checkout: virtual account bank atau QRIS. Setelah pesanan dibuat, nomor virtual account atau kode QR muncul di detail pesanan. Bayar sebelum batas waktu yang tertera di sana; pesanan yang belum dibayar sampai batas waktu itu dibatalkan otomatis. Status pesanan ikut berubah setelah pembayaran diterima. Kalau belum berubah, tarik layar detail pesanan ke bawah untuk memuat ulang.',
  },
  {
    key: 'kirim',
    question: 'Bagaimana cara kerja pengiriman?',
    answer:
      'Saat checkout kamu memilih kurir dan layanan. Ongkos kirim dihitung dari alamat tujuan dan berat barang. Setelah pesanan dikemas, nomor resi muncul di detail pesanan dan kamu bisa melacak paketnya dari sana.',
  },
  {
    key: 'akun',
    question: 'Apakah harus punya akun untuk belanja?',
    answer:
      'Kamu bisa melihat produk dan mengisi keranjang tanpa akun. Akun dibutuhkan saat checkout, supaya pesanan dan alamatmu tersimpan.',
  },
  {
    key: 'sandi',
    question: 'Bagaimana kalau lupa kata sandi?',
    answer:
      'Di layar Masuk, ketuk Lupa kata sandi, isi emailmu, lalu buka tautan yang kami kirim ke email itu untuk membuat kata sandi baru. Setelah itu masuk lagi di aplikasi.',
  },
  {
    key: 'perangkat',
    question: 'Bisakah akunku dipakai di dua HP sekaligus?',
    answer:
      'Tidak. Satu akun hanya aktif di satu perangkat. Kalau kamu masuk di perangkat lain, perangkat yang sebelumnya otomatis keluar. Mengubah kata sandi mengeluarkan semua perangkat lain.',
  },
];

const CONTACT_FAQ: Faq = {
  key: 'kontak',
  question: 'Bagaimana cara menghubungi OceanMall?',
  answer:
    'Hubungi kami lewat kontak di halaman ini. Sebutkan nomor pesananmu kalau menanyakan pesanan, supaya kami bisa menelusurinya.',
};

export default function AboutScreen() {
  const router = useRouter();
  const [openKey, setOpenKey] = useState<string | null>(null);
  const channels = supportChannels();
  // Without a configured contact there is nothing to point to, so the question is left out.
  const faqs = channels.length > 0 ? [...FAQS, CONTACT_FAQ] : FAQS;

  const open = (path: Href) => router.push(path);

  return (
    <Screen title="Tentang OceanMall" surface>
      <ScrollView className="flex-1" contentContainerClassName="pb-10">
        <View className="items-center bg-white px-6 pb-6 pt-7">
          <BrandLogo variant="dark" size={48} />
          <Text className="mt-4 text-center text-[14px] font-semibold text-foreground">
            {TAGLINE}
          </Text>
          <Text className="mt-1 text-[12px] text-muted-foreground">{APP_VERSION_LABEL}</Text>
        </View>

        <Section title="Pertanyaan umum" icon={CircleQuestionMark} flush>
          {faqs.map((faq, index) => (
            <FaqRow
              key={faq.key}
              faq={faq}
              expanded={openKey === faq.key}
              last={index === faqs.length - 1}
              onToggle={() => setOpenKey((current) => (current === faq.key ? null : faq.key))}
            />
          ))}
        </Section>

        {channels.length > 0 ? (
          <Section title="Hubungi kami" flush>
            {channels.map((channel, index) => (
              <MenuRow
                key={channel.key}
                icon={channel.key === 'whatsapp' ? MessageCircle : Mail}
                label={channel.label}
                hint={channel.address}
                onPress={() => void openSupportChannel(channel)}
                last={index === channels.length - 1}
              />
            ))}
          </Section>
        ) : null}

        <View className="mt-2 bg-white">
          <MenuRow
            icon={FileText}
            label="Syarat & ketentuan"
            onPress={() => open('/legal/syarat-ketentuan')}
          />
          <MenuRow
            icon={ShieldCheck}
            label="Kebijakan privasi"
            onPress={() => open('/legal/kebijakan-privasi')}
            last
          />
        </View>
      </ScrollView>
    </Screen>
  );
}

/** One expandable question. The whole row is the button; the answer opens under it. */
function FaqRow({
  faq,
  expanded,
  last,
  onToggle,
}: {
  faq: Faq;
  expanded: boolean;
  last: boolean;
  onToggle: () => void;
}) {
  return (
    <View className={cn('px-4', !last && 'border-b border-border')}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={faq.question}
        accessibilityState={{ expanded }}
        onPress={onToggle}
        android_ripple={ripple.row}
        className={cn(
          '-mx-4 min-h-11 flex-row items-center gap-3 px-4 py-3.5',
          Platform.OS === 'ios' && 'active:bg-om-surface'
        )}>
        <Text className="flex-1 text-[14px] font-medium text-foreground">{faq.question}</Text>
        <View style={{ transform: [{ rotate: expanded ? '180deg' : '0deg' }] }}>
          <Icon as={ChevronDown} size={18} className="text-muted-foreground" />
        </View>
      </Pressable>
      {expanded ? (
        <Text className="pb-4 text-[13px] leading-5 text-muted-foreground">{faq.answer}</Text>
      ) : null}
    </View>
  );
}
