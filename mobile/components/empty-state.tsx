import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import { announce } from '@/lib/a11y';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react-native';
import { useEffect, type ReactNode } from 'react';
import { Platform, Pressable, View } from 'react-native';

/**
 * The one empty / error / sign-in-required screen body: a soft circle with an icon, an optional
 * headline, a short message and up to two actions.
 *
 * tone="error" turns the circle red-soft and is spoken to screen readers when it appears, so a
 * load failure that replaces the content is not silent. Use it for "could not load" states and
 * keep the default tone for "nothing here yet".
 *
 * `compact` drops the full-height layout and the page background so the state can sit inside a
 * section card or under a header. `illustration` replaces the icon inside the circle. `className`
 * overrides the root (for example `bg-om-surface` on a gray page).
 */
export function EmptyState({
  icon,
  title,
  message,
  actionLabel,
  onAction,
  secondaryLabel,
  onSecondary,
  tone = 'default',
  compact = false,
  illustration,
  className,
  children,
}: {
  icon?: LucideIcon;
  title?: string;
  message: string;
  actionLabel?: string;
  onAction?: () => void;
  secondaryLabel?: string;
  onSecondary?: () => void;
  tone?: 'default' | 'error';
  compact?: boolean;
  illustration?: ReactNode;
  className?: string;
  children?: ReactNode;
}) {
  const isError = tone === 'error';
  const spoken = title ? `${title}. ${message}` : message;
  const Glyph = icon;
  const hasGlyph = Boolean(illustration) || Boolean(Glyph);

  useEffect(() => {
    // Android reads the live region below; iOS needs an explicit announcement.
    if (isError && Platform.OS === 'ios') {
      announce(spoken);
    }
  }, [isError, spoken]);

  return (
    <View
      accessibilityLiveRegion={isError ? 'polite' : undefined}
      className={cn(
        'items-center justify-center px-8',
        compact ? 'py-10' : 'flex-1 bg-background',
        className
      )}>
      {hasGlyph ? (
        <View
          className={cn(
            'items-center justify-center rounded-full',
            compact ? 'size-[72px]' : 'size-24',
            isError ? 'bg-om-sale-soft' : 'bg-om-navy-soft'
          )}>
          {illustration ? (
            illustration
          ) : Glyph ? (
            <Icon
              as={Glyph}
              size={compact ? 30 : 40}
              className={isError ? 'text-om-sale' : 'text-om-navy'}
            />
          ) : null}
        </View>
      ) : null}

      {title ? (
        <Text
          role="heading"
          aria-level={2}
          className="mt-4 text-center text-[16px] font-bold text-foreground">
          {title}
        </Text>
      ) : null}
      <Text
        className={cn(
          'text-center text-[13px] leading-5 text-muted-foreground',
          title ? 'mt-1' : hasGlyph && 'mt-4'
        )}>
        {message}
      </Text>

      {actionLabel && onAction ? (
        <Button className="mt-5 min-h-11 rounded-sm px-8" onPress={onAction}>
          <Text className="font-bold">{actionLabel}</Text>
        </Button>
      ) : null}
      {secondaryLabel && onSecondary ? (
        <Pressable
          accessibilityRole="button"
          onPress={onSecondary}
          className="mt-2 min-h-11 items-center justify-center px-4 active:opacity-60">
          <Text className="text-[13px] font-bold text-om-navy">{secondaryLabel}</Text>
        </Pressable>
      ) : null}
      {children}
    </View>
  );
}
