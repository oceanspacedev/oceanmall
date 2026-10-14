import { COLORS } from '@/lib/theme';
import { ActivityIndicator, type ActivityIndicatorProps } from 'react-native';

/**
 * Branded activity indicator. Android spinners otherwise render in the AppCompat
 * accent colour (teal), so always go through this instead of a bare ActivityIndicator.
 */
function Spinner({
  size = 'large',
  color,
  ...props
}: Omit<ActivityIndicatorProps, 'color'> & { color?: string }) {
  return (
    <ActivityIndicator
      color={color ?? COLORS.navy}
      size={size}
      accessibilityLabel="Memuat"
      accessibilityRole="progressbar"
      {...props}
    />
  );
}

export { Spinner };
