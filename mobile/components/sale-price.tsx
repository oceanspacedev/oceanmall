import { Badge } from '@/components/ui/badge';
import { Text } from '@/components/ui/text';
import { discountPercent, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { View } from 'react-native';

/**
 * Price block for cards and the product page: the price (red when discounted), the struck-through
 * original price and, on request, the "-NN%" chip. The percent chip keeps ASCII on purpose so it
 * reads the same in every font.
 */
export function SalePrice({
  price,
  compare,
  currency = 'IDR',
  size = 'card',
  showPercent = false,
}: {
  price?: number | null;
  compare?: number | null;
  currency?: string;
  size?: 'card' | 'detail';
  showPercent?: boolean;
}) {
  const unpriced = price == null || price <= 0;
  const off = unpriced ? 0 : discountPercent(price, compare);
  const detail = size === 'detail';

  return (
    <View>
      <View className="flex-row flex-wrap items-baseline gap-1.5">
        <Text
          className={cn(
            unpriced ? 'font-semibold text-muted-foreground' : 'font-extrabold',
            detail ? (unpriced ? 'text-[16px]' : 'text-[22px]') : unpriced ? 'text-[12px]' : 'text-[15px]',
            // om-sale-text clears 4.5:1 on white; the brighter om-sale is for fills.
            !unpriced && (off ? 'text-om-sale-text' : 'text-om-navy')
          )}
          numberOfLines={1}>
          {unpriced ? 'Harga belum tersedia' : formatMoney(price, currency)}
        </Text>
        {off && showPercent ? (
          <Badge variant="sale" className="px-1.5 py-0.5">
            <Text className="text-[11px] font-extrabold">-{off}%</Text>
          </Badge>
        ) : null}
      </View>
      {off ? (
        <Text
          accessibilityLabel={`Harga sebelum diskon ${formatMoney(compare, currency)}`}
          className={cn(
            'mt-0.5 text-muted-foreground line-through',
            detail ? 'text-[13px]' : 'text-[12px]'
          )}
          numberOfLines={1}>
          {formatMoney(compare, currency)}
        </Text>
      ) : null}
    </View>
  );
}
