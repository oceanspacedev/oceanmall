import { Badge } from '@/components/ui/badge';
import { Text } from '@/components/ui/text';
import { discountPercent, formatIdr } from '@/lib/format';
import { cn } from '@/lib/utils';
import { View } from 'react-native';

export function SalePrice({
  price,
  compare,
  size = 'card',
  showPercent = false,
}: {
  price?: number | null;
  compare?: number | null;
  size?: 'card' | 'detail';
  showPercent?: boolean;
}) {
  const off = discountPercent(price, compare);
  const detail = size === 'detail';

  return (
    <View>
      <View className="flex-row flex-wrap items-baseline gap-1.5">
        <Text
          className={cn(
            'font-extrabold',
            detail ? 'text-[22px]' : 'text-[15px]',
            off ? 'text-om-sale' : 'text-om-navy'
          )}
          numberOfLines={1}>
          {formatIdr(price)}
        </Text>
        {off && showPercent ? (
          <Badge variant="sale" className="px-1.5 py-0.5">
            <Text className="text-[11px] font-extrabold">-{off}%</Text>
          </Badge>
        ) : null}
      </View>
      {off ? (
        <Text
          className={cn('mt-0.5 text-muted-foreground line-through', detail ? 'text-[13px]' : 'text-[11px]')}
          numberOfLines={1}>
          {formatIdr(compare)}
        </Text>
      ) : null}
    </View>
  );
}
