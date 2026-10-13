import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { View } from 'react-native';

export function LoadMore({
  visible,
  loading,
  onPress,
}: {
  visible: boolean;
  loading: boolean;
  onPress: () => void;
}) {
  if (!visible) {
    return null;
  }

  return (
    <View className="mt-2 items-center">
      <Button variant="outline" disabled={loading} onPress={onPress}>
        <Text>{loading ? 'Memuat...' : 'Muat lebih banyak'}</Text>
      </Button>
    </View>
  );
}
