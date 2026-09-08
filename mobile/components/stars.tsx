import { Icon } from '@/components/ui/icon';
import { Star } from 'lucide-react-native';
import { View } from 'react-native';

export function Stars({ value, size = 12 }: { value: number; size?: number }) {
  const filled = Math.max(0, Math.min(5, Math.round(value)));

  return (
    <View className="flex-row items-center gap-0.5">
      {[1, 2, 3, 4, 5].map((level) => (
        <Icon
          key={level}
          as={Star}
          size={size}
          color={level <= filled ? '#0a2a6b' : '#d4d4d8'}
          fill={level <= filled ? '#0a2a6b' : 'transparent'}
        />
      ))}
    </View>
  );
}
