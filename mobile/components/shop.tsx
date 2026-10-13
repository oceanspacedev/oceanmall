import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { cn } from '@/lib/utils';
import * as Clipboard from 'expo-clipboard';
import { Image } from 'expo-image';
import { Check, ChevronRight, Copy, Minus, Plus, type LucideIcon } from 'lucide-react-native';
import { useEffect, useState, type ReactNode } from 'react';
import { Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

/**
 * Marketplace building blocks shared by the non-home screens: white section
 * cards on a light-gray page, a sticky bottom action bar, radio option cards,
 * status pills and a compact quantity stepper.
 */

export function Section({
  title,
  icon,
  action,
  onAction,
  children,
  className,
  flush = false,
}: {
  title?: string;
  icon?: LucideIcon;
  action?: string;
  onAction?: () => void;
  children?: ReactNode;
  className?: string;
  /** No inner horizontal padding (for full-bleed rows). */
  flush?: boolean;
}) {
  return (
    <View className={cn('mt-2 bg-white py-3.5', !flush && 'px-4', className)}>
      {title ? (
        <View className={cn('mb-3 flex-row items-center gap-2', flush && 'px-4')}>
          {icon ? <Icon as={icon} size={17} className="text-om-navy" /> : null}
          <Text className="flex-1 text-[14px] font-bold text-foreground">{title}</Text>
          {action && onAction ? (
            <Pressable onPress={onAction} hitSlop={8}>
              <Text className="text-[13px] font-semibold text-om-navy">{action}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}
      {children}
    </View>
  );
}

export function StickyBar({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();

  return (
    <View
      className="border-t border-border bg-white px-4 pt-2.5"
      style={{
        paddingBottom: Math.max(insets.bottom, 12),
        shadowColor: '#0f172a',
        shadowOpacity: 0.06,
        shadowRadius: 8,
        shadowOffset: { width: 0, height: -2 },
        elevation: 8,
      }}>
      {children}
    </View>
  );
}

export function OptionCard({
  selected,
  disabled,
  onPress,
  icon,
  title,
  subtitle,
  trailing,
  note,
}: {
  selected: boolean;
  disabled?: boolean;
  onPress: () => void;
  icon?: ReactNode;
  title: string;
  subtitle?: string | null;
  trailing?: ReactNode;
  note?: string | null;
}) {
  return (
    <Pressable
      disabled={disabled}
      onPress={onPress}
      className={cn(
        'mb-2 flex-row items-center gap-3 rounded-sm border p-3 last:mb-0',
        selected ? 'border-om-navy bg-om-navy-soft' : 'border-border bg-white',
        disabled && 'opacity-50'
      )}>
      {icon ? (
        <View className="size-10 items-center justify-center rounded-sm bg-om-surface">{icon}</View>
      ) : null}
      <View className="min-w-0 flex-1">
        <Text className="text-[14px] font-semibold" numberOfLines={1}>
          {title}
        </Text>
        {subtitle ? (
          <Text className="mt-0.5 text-[12px] text-muted-foreground" numberOfLines={2}>
            {subtitle}
          </Text>
        ) : null}
        {note ? <Text className="mt-0.5 text-[12px] text-destructive">{note}</Text> : null}
      </View>
      {trailing}
      <View
        className={cn(
          'size-5 items-center justify-center rounded-full border-2',
          selected ? 'border-om-navy bg-om-navy' : 'border-om-disabled'
        )}>
        {selected ? <Icon as={Check} size={12} color="#fff" /> : null}
      </View>
    </Pressable>
  );
}

const PILL_TONES = {
  navy: 'bg-om-navy-soft text-om-navy',
  sale: 'bg-om-sale-soft text-om-sale',
  success: 'bg-om-success-soft text-om-success',
  warning: 'bg-om-warning-soft text-om-warning',
  muted: 'bg-om-surface text-muted-foreground',
} as const;

export type PillTone = keyof typeof PILL_TONES;

export function StatusPill({ label, tone = 'navy' }: { label: string; tone?: PillTone }) {
  const [bg, fg] = PILL_TONES[tone].split(' ');
  return (
    <View className={cn('self-start rounded-md px-2 py-0.5', bg)}>
      <Text className={cn('text-[11px] font-bold', fg)}>{label}</Text>
    </View>
  );
}

export function QtyStepper({
  value,
  min = 1,
  max,
  busy = false,
  onChange,
  size = 'md',
}: {
  value: number;
  min?: number;
  max: number;
  busy?: boolean;
  onChange: (value: number) => void;
  size?: 'sm' | 'md';
}) {
  const h = size === 'sm' ? 'h-8 w-8' : 'h-10 w-10';
  const canDec = value > min && !busy;
  const canInc = value < max && !busy;

  return (
    <View className="flex-row items-center overflow-hidden rounded-sm border border-border bg-white">
      <Pressable
        accessibilityLabel="Kurangi"
        disabled={!canDec}
        onPress={() => onChange(value - 1)}
        className={cn(h, 'items-center justify-center')}>
        <Icon as={Minus} size={14} className={canDec ? 'text-om-navy' : 'text-om-disabled'} />
      </Pressable>
      <Text className="min-w-[26px] text-center text-[14px] font-semibold">{value}</Text>
      <Pressable
        accessibilityLabel="Tambah"
        disabled={!canInc}
        onPress={() => onChange(value + 1)}
        className={cn(h, 'items-center justify-center')}>
        <Icon as={Plus} size={14} className={canInc ? 'text-om-navy' : 'text-om-disabled'} />
      </Pressable>
    </View>
  );
}

export function MenuRow({
  icon,
  label,
  hint,
  badge = 0,
  onPress,
  last = false,
  danger = false,
}: {
  icon: LucideIcon;
  label: string;
  hint?: string;
  badge?: number;
  onPress: () => void;
  last?: boolean;
  danger?: boolean;
}) {
  return (
    <Pressable onPress={onPress} className="flex-row items-center gap-3 px-4 active:bg-om-surface">
      <Icon as={icon} size={19} className={danger ? 'text-destructive' : 'text-om-navy'} />
      <View
        className={cn('flex-1 flex-row items-center py-3.5', !last && 'border-b border-border')}>
        <View className="flex-1">
          <Text className={cn('text-[14px]', danger && 'text-destructive')}>{label}</Text>
          {hint ? <Text className="text-[12px] text-muted-foreground">{hint}</Text> : null}
        </View>
        {badge > 0 ? (
          <View className="mr-2 min-w-[20px] items-center rounded-full bg-om-sale px-1.5 py-0.5">
            <Text className="text-[10px] font-bold text-white">{badge > 99 ? '99+' : badge}</Text>
          </View>
        ) : null}
        {danger ? null : <Icon as={ChevronRight} size={16} className="text-muted-foreground" />}
      </View>
    </Pressable>
  );
}

export function SummaryRow({
  label,
  value,
  strong = false,
  valueClassName,
}: {
  label: string;
  value: string;
  strong?: boolean;
  valueClassName?: string;
}) {
  return (
    <View className="flex-row items-center justify-between py-1">
      <Text className={cn('text-[13px]', strong ? 'font-bold' : 'text-muted-foreground')}>
        {label}
      </Text>
      <Text
        className={cn(
          'text-[13px]',
          strong ? 'text-[15px] font-extrabold text-om-navy' : 'font-medium',
          valueClassName
        )}>
        {value}
      </Text>
    </View>
  );
}

export function CopyButton({ value, label = 'Salin' }: { value: string; label?: string }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) {
      return;
    }
    const t = setTimeout(() => setCopied(false), 1800);
    return () => clearTimeout(t);
  }, [copied]);

  return (
    <Pressable
      hitSlop={8}
      onPress={() => {
        void Clipboard.setStringAsync(value);
        setCopied(true);
      }}
      className="flex-row items-center gap-1 rounded-md bg-om-navy-soft px-2 py-1">
      <Icon as={copied ? Check : Copy} size={13} className="text-om-navy" />
      <Text className="text-[12px] font-semibold text-om-navy">{copied ? 'Tersalin' : label}</Text>
    </Pressable>
  );
}

export type SortKey = 'latest' | 'price_asc' | 'price_desc';

const SORTS: [SortKey, string][] = [
  ['latest', 'Terbaru'],
  ['price_asc', 'Termurah'],
  ['price_desc', 'Termahal'],
];

/** "N produk" counter with sort chips, used on top of product lists. */
export function SortBar({
  total,
  value,
  onChange,
  label,
}: {
  total?: number | null;
  value: SortKey;
  onChange: (value: SortKey) => void;
  label?: ReactNode;
}) {
  return (
    <View className="border-b border-border bg-white px-4 pb-3 pt-3">
      <Text className="mb-2.5 text-[12px] text-muted-foreground">
        {label ?? `${total ?? 0} produk`}
      </Text>
      <View className="flex-row gap-2">
        {SORTS.map(([key, text]) => (
          <Pressable
            key={key}
            onPress={() => onChange(key)}
            className={cn(
              'rounded-sm border px-3.5 py-1.5',
              value === key ? 'border-om-navy bg-om-navy-soft' : 'border-border bg-white'
            )}>
            <Text
              className={cn(
                'text-[12px]',
                value === key ? 'font-bold text-om-navy' : 'text-foreground'
              )}>
              {text}
            </Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

/** Courier / bank logo that falls back to an icon when the image is missing or fails. */
export function LogoImage({ uri, fallback }: { uri?: string | null; fallback: LucideIcon }) {
  const [failed, setFailed] = useState(false);

  if (!uri || failed) {
    return <Icon as={fallback} size={18} className="text-om-navy" />;
  }

  return (
    <Image
      source={{ uri }}
      style={{ width: 32, height: 24 }}
      contentFit="contain"
      onError={() => setFailed(true)}
    />
  );
}
