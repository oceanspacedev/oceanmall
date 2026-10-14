import { EmptyState } from '@/components/empty-state';
import { Screen } from '@/components/screen';
import { Text } from '@/components/ui/text';
import { LEGAL_DOCS, LEGAL_IS_DRAFT, isLegalSlug } from '@/lib/legal-content';
import { formatDate } from '@/lib/format';
import { FileQuestionMark } from 'lucide-react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { ScrollView, View } from 'react-native';

/**
 * Terms and privacy policy, in the app. Reachable without an account (the consent line on the
 * sign-up form and the Akun tab both open it), and the in-app privacy link the stores expect.
 */
export default function LegalScreen() {
  const router = useRouter();
  const params = useLocalSearchParams<{ slug?: string | string[] }>();
  const slug = Array.isArray(params.slug) ? params.slug[0] : params.slug;

  if (!isLegalSlug(slug)) {
    return (
      <Screen title="Halaman tidak ditemukan">
        <EmptyState
          icon={FileQuestionMark}
          title="Halaman tidak ditemukan"
          message="Halaman yang kamu cari tidak tersedia."
          actionLabel="Kembali"
          onAction={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)'))}
        />
      </Screen>
    );
  }

  const doc = LEGAL_DOCS[slug];

  return (
    <Screen title={doc.title}>
      <ScrollView className="flex-1" contentContainerClassName="px-4 pb-10 pt-4">
        {LEGAL_IS_DRAFT ? (
          <View className="mb-4 rounded-sm bg-om-warning-soft px-3 py-2.5">
            <Text className="text-[12px] leading-5 text-om-warning">
              Draf: teks ini belum ditinjau tim hukum OceanMall dan bisa berubah.
            </Text>
          </View>
        ) : null}

        <Text className="text-[12px] text-muted-foreground">
          Terakhir diperbarui {formatDate(doc.updated)}
        </Text>

        {doc.sections.map((section) => (
          <View key={section.heading} className="mt-6">
            <Text role="heading" aria-level={2} className="text-[15px] font-bold text-foreground">
              {section.heading}
            </Text>
            {section.body.split('\n\n').map((paragraph) => (
              <Text key={paragraph} className="mt-2 text-[13px] leading-5 text-foreground">
                {paragraph}
              </Text>
            ))}
          </View>
        ))}
      </ScrollView>
    </Screen>
  );
}
