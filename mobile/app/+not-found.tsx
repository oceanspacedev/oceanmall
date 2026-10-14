import { EmptyState } from '@/components/empty-state';
import { Screen } from '@/components/screen';
import { useRouter } from 'expo-router';
import { FileQuestion } from 'lucide-react-native';

export default function NotFoundScreen() {
  const router = useRouter();

  return (
    <Screen title="Halaman tidak ditemukan">
      <EmptyState
        icon={FileQuestion}
        message="Halaman yang kamu cari tidak ada atau sudah dipindahkan."
        actionLabel="Ke beranda"
        onAction={() =>
          router.canGoBack() ? router.dismissTo('/(tabs)') : router.replace('/(tabs)')
        }
      />
    </Screen>
  );
}
