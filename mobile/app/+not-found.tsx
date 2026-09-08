import { EmptyState } from '@/components/empty-state';
import { Stack, useRouter } from 'expo-router';
import { FileQuestion } from 'lucide-react-native';

export default function NotFoundScreen() {
  const router = useRouter();

  return (
    <>
      <Stack.Screen options={{ title: 'Tidak ditemukan' }} />
      <EmptyState
        icon={FileQuestion}
        message="Halaman ini tidak ada."
        actionLabel="Kembali ke beranda"
        onAction={() => router.replace('/')}
      />
    </>
  );
}
