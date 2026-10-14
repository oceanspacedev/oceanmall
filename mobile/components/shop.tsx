import { Icon } from '@/components/ui/icon';
import { Spinner } from '@/components/ui/spinner';
import { Text } from '@/components/ui/text';
import { announce } from '@/lib/a11y';
import { ripple } from '@/lib/ripple';
import { COLORS, SHADOW } from '@/lib/theme';
import { cn } from '@/lib/utils';
import * as Clipboard from 'expo-clipboard';
import { Image } from 'expo-image';
import { Check, ChevronRight, Copy, Minus, Plus, type LucideIcon } from 'lucide-react-native';
import { isValidElement, useEffect, useState, type ReactNode } from 'react';
import { Platform, Pressable, View, type AccessibilityActionEvent } from 'react-native';
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
          <Text
            role="heading"
            aria-level={2}
            className="flex-1 text-[14px] font-bold text-foreground">
            {title}
          </Text>
          {action && onAction ? (
            <Pressable
              accessibilityRole="button"
              onPress={onAction}
              hitSlop={{ top: 14, bottom: 14, left: 12, right: 12 }}
              className="active:opacity-60">
              <Text className="text-[13px] font-semibold text-om-navy">{action}</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}
      {children}
    </View>
  );
}

/**
 * Bottom action bar. It pads for the home indicator by default. Inside the tab navigator the tab
 * bar already includes that inset, so a bar that sits on top of it (the cart) passes
 * `safeBottom={false}` and sits flush instead of floating over an extra white band.
 */
export function StickyBar({
  children,
  safeBottom = true,
}: {
  children: ReactNode;
  safeBottom?: boolean;
}) {
  const insets = useSafeAreaInsets();
  const paddingBottom = safeBottom ? Math.max(insets.bottom, 12) : 12;

  return (
    <View
      className="border-t border-border bg-white px-4 pt-2.5"
      style={[SHADOW.bar, { paddingBottom }]}>
      {children}
    </View>
  );
}

/** Plain text of a node, so a `trailing` price can be part of the spoken label. */
function textOf(node: ReactNode): string {
  if (node === null || node === undefined || typeof node === 'boolean') {
    return '';
  }
  if (typeof node === 'string' || typeof node === 'number') {
    return String(node);
  }
  if (Array.isArray(node)) {
    return node.map(textOf).join('');
  }
  if (isValidElement<{ children?: ReactNode }>(node)) {
    return textOf(node.props.children);
  }
  return '';
}

/**
 * One choice in a radio group (courier, payment method, saved address).
 *
 * `loading` swaps this card's radio for a spinner while its choice is being applied; the other
 * cards stay put. Cards that are `disabled` are no longer dimmed by default (a busy list should
 * not flash), so pass `disabledDim` for a card that is unavailable for good (a payment method
 * over its limit) and should look it.
 * The spoken label is title, subtitle, note and the text of `trailing`; pass `trailingLabel`
 * when `trailing` is a component whose text cannot be read from here.
 */
export function OptionCard({
  selected,
  disabled,
  onPress,
  icon,
  title,
  subtitle,
  trailing,
  trailingLabel,
  note,
  loading = false,
  disabledDim = false,
}: {
  selected: boolean;
  disabled?: boolean;
  onPress: () => void;
  icon?: ReactNode;
  title: string;
  subtitle?: string | null;
  trailing?: ReactNode;
  trailingLabel?: string;
  note?: string | null;
  loading?: boolean;
  disabledDim?: boolean;
}) {
  const label = [title, subtitle, note, trailingLabel ?? textOf(trailing)]
    .filter((part) => Boolean(part))
    .join(', ');

  return (
    <Pressable
      disabled={disabled}
      onPress={onPress}
      accessibilityRole="radio"
      accessibilityLabel={label}
      accessibilityState={{ checked: selected, disabled: Boolean(disabled), busy: loading }}
      android_ripple={ripple.row}
      className={cn(
        'mb-2 flex-row items-center gap-3 overflow-hidden rounded-sm border p-3 last:mb-0 active:opacity-80',
        selected ? 'border-om-navy bg-om-navy-soft' : 'border-border bg-white',
        disabled && disabledDim && 'opacity-50'
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
      {loading ? (
        <View className="size-5 items-center justify-center">
          <Spinner size="small" />
        </View>
      ) : (
        <View
          className={cn(
            'size-5 items-center justify-center rounded-full border-2',
            selected ? 'border-om-navy bg-om-navy' : 'border-om-disabled'
          )}>
          {selected ? <Icon as={Check} size={12} color={COLORS.white} /> : null}
        </View>
      )}
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
    <View className={cn('self-start rounded-md px-2.5 py-0.5', bg)}>
      <Text className={cn('text-[12px] font-bold', fg)}>{label}</Text>
    </View>
  );
}

/**
 * Quantity control. The whole stepper is one adjustable node for screen readers ("Jumlah <item>",
 * swipe up or down to change it); the +/- buttons stay tappable with the finger and are hidden from
 * the accessibility tree. Targets are 44pt (md) and 36pt plus a 6pt hit slop on each side, so 48pt
 * (sm). There is no overflow clipping on the wrapper, or the slop would be cut off.
 */
export function QtyStepper({
  value,
  min = 1,
  max,
  busy = false,
  onChange,
  size = 'md',
  itemLabel,
}: {
  value: number;
  min?: number;
  max: number;
  busy?: boolean;
  onChange: (value: number) => void;
  size?: 'sm' | 'md';
  /** Product name, so the spoken label is "Jumlah <itemLabel>" instead of just "Jumlah". */
  itemLabel?: string;
}) {
  const box = size === 'sm' ? 'h-9 w-9' : 'h-11 w-11';
  const slop = size === 'sm' ? 6 : 0;
  const canDec = value > min && !busy;
  const canInc = value < max && !busy;

  function onAccessibilityAction(event: AccessibilityActionEvent) {
    if (event.nativeEvent.actionName === 'increment' && canInc) {
      onChange(value + 1);
    } else if (event.nativeEvent.actionName === 'decrement' && canDec) {
      onChange(value - 1);
    }
  }

  return (
    <View
      accessible
      accessibilityRole="adjustable"
      accessibilityLabel={itemLabel ? `Jumlah ${itemLabel}` : 'Jumlah'}
      accessibilityValue={{ min, max, now: value, text: `${value}` }}
      accessibilityState={{ disabled: busy, busy }}
      accessibilityActions={[{ name: 'increment' }, { name: 'decrement' }]}
      onAccessibilityAction={onAccessibilityAction}
      className="flex-row items-center rounded-sm border border-border bg-white">
      <Pressable
        accessibilityLabel="Kurangi jumlah"
        importantForAccessibility="no-hide-descendants"
        disabled={!canDec}
        hitSlop={slop}
        android_ripple={ripple.icon}
        onPress={() => onChange(value - 1)}
        className={cn(box, 'items-center justify-center active:opacity-70')}>
        <Icon as={Minus} size={14} className={canDec ? 'text-om-navy' : 'text-om-disabled'} />
      </Pressable>
      <Text
        maxFontSizeMultiplier={1.2}
        className="min-w-[28px] text-center text-[14px] font-semibold">
        {value}
      </Text>
      <Pressable
        accessibilityLabel="Tambah jumlah"
        accessibilityHint={value >= max ? `Maksimal ${max} per pesanan` : undefined}
        importantForAccessibility="no-hide-descendants"
        disabled={!canInc}
        hitSlop={slop}
        android_ripple={ripple.icon}
        onPress={() => onChange(value + 1)}
        className={cn(box, 'items-center justify-center active:opacity-70')}>
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
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={[label, hint, badge > 0 ? `${badge} belum dibaca` : null]
        .filter((part) => Boolean(part))
        .join(', ')}
      onPress={onPress}
      android_ripple={ripple.row}
      // The ripple covers Android; a pressed background does it on iOS (stacking both doubles up).
      className={cn(
        'flex-row items-center gap-3 px-4',
        Platform.OS === 'ios' && 'active:bg-om-surface'
      )}>
      <Icon as={icon} size={19} className={danger ? 'text-destructive' : 'text-om-navy'} />
      <View
        className={cn('flex-1 flex-row items-center py-3.5', !last && 'border-b border-border')}>
        <View className="flex-1">
          <Text className={cn('text-[14px]', danger && 'text-destructive')}>{label}</Text>
          {hint ? <Text className="text-[12px] text-muted-foreground">{hint}</Text> : null}
        </View>
        {badge > 0 ? (
          <View className="mr-2 min-w-[20px] items-center rounded-full bg-om-sale px-1.5 py-0.5">
            <Text className="text-[10px] font-bold text-white" maxFontSizeMultiplier={1.2}>
              {badge > 99 ? '99+' : badge}
            </Text>
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
      <Text
        numberOfLines={2}
        className={cn('flex-1 pr-3 text-[13px]', strong ? 'font-bold' : 'text-muted-foreground')}>
        {label}
      </Text>
      <Text
        className={cn(
          'shrink text-right text-[13px]',
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
      accessibilityRole="button"
      hitSlop={10}
      android_ripple={ripple.row}
      onPress={() => {
        void Clipboard.setStringAsync(value);
        setCopied(true);
        announce('Tersalin');
      }}
      className="flex-row items-center gap-1 overflow-hidden rounded-sm bg-om-navy-soft px-2 py-1.5 active:opacity-70">
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
      <View accessibilityRole="radiogroup" className="flex-row gap-2">
        {SORTS.map(([key, text]) => (
          <Pressable
            key={key}
            accessibilityRole="button"
            accessibilityState={{ selected: value === key }}
            hitSlop={{ top: 6, bottom: 6 }}
            onPress={() => onChange(key)}
            className={cn(
              'rounded-sm border px-3.5 py-2 active:opacity-70',
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
