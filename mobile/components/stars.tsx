import { Icon } from '@/components/ui/icon';
import { COLORS } from '@/lib/theme';
import { Star } from 'lucide-react-native';
import { View } from 'react-native';

/** "4,5" with a decimal comma, the way ratings read in Indonesian. */
export function formatRating(value: number): string {
  return (Number.isFinite(value) ? value : 0).toFixed(1).replace('.', ',');
}

/**
 * Five stars, gold when filled and light grey when empty. The row reads as one element
 * ("Rating 4,5 dari 5") instead of five unnamed icons. `color` overrides the gold when the stars
 * sit on a surface where it would not read.
 */
export function Stars({
  value,
  size = 12,
  color = COLORS.star,
}: {
  value: number;
  size?: number;
  color?: string;
}) {
  const safe = Number.isFinite(value) ? value : 0;
  const filled = Math.max(0, Math.min(5, Math.round(safe)));

  return (
    <View
      accessible
      accessibilityRole="text"
      accessibilityLabel={`Rating ${formatRating(safe)} dari 5`}
      className="flex-row items-center gap-0.5">
      {[1, 2, 3, 4, 5].map((level) => (
        <Icon
          key={level}
          as={Star}
          size={size}
          color={level <= filled ? color : COLORS.starEmpty}
          fill={level <= filled ? color : COLORS.starEmpty}
        />
      ))}
    </View>
  );
}
