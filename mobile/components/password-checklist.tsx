import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { PASSWORD_RULES } from '@/lib/password-rules';
import { COLORS } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { Check, Minus } from 'lucide-react-native';
import { View } from 'react-native';

export type ChecklistRow = { key: string; label: string; met: boolean };

/**
 * Live checklist of the password policy under a "new password" field. Each row says whether it is
 * met in words as well as colour (a check or a dash, and "terpenuhi" / "belum terpenuhi" for
 * screen readers). `extra` adds screen-specific rows, such as "different from the current one".
 */
export function PasswordChecklist({ value, extra }: { value: string; extra?: ChecklistRow[] }) {
  const rows: ChecklistRow[] = [
    ...PASSWORD_RULES.map((rule) => ({
      key: rule.key,
      label: rule.label,
      met: rule.test(value),
    })),
    ...(extra ?? []),
  ];

  return (
    <View className="gap-1.5">
      {rows.map((row) => (
        <View
          key={row.key}
          accessible
          accessibilityLabel={`${row.label}: ${row.met ? 'terpenuhi' : 'belum terpenuhi'}`}
          className="flex-row items-center gap-2">
          <View
            className={cn(
              'size-4 items-center justify-center rounded-full',
              row.met ? 'bg-om-success' : 'border border-om-disabled bg-white'
            )}>
            <Icon
              as={row.met ? Check : Minus}
              size={10}
              color={row.met ? COLORS.white : COLORS.mutedForeground}
            />
          </View>
          <Text
            accessible={false}
            className={cn('text-[12px]', row.met ? 'text-om-success' : 'text-muted-foreground')}>
            {row.label}
          </Text>
        </View>
      ))}
    </View>
  );
}
