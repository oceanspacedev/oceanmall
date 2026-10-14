import { TextClassContext } from '@/components/ui/text';
import { COLORS } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';
import { ActivityIndicator, Platform, Pressable, StyleSheet, View } from 'react-native';

const buttonVariants = cva(
  cn(
    'group shrink-0 flex-row items-center justify-center gap-2 overflow-hidden rounded-sm',
    Platform.select({
      web: "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive whitespace-nowrap outline-none transition-all focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:pointer-events-none [&_svg]:shrink-0",
    })
  ),
  {
    variants: {
      variant: {
        default: cn(
          'bg-primary active:bg-primary/90',
          Platform.select({ web: 'hover:bg-primary/90' })
        ),
        destructive: cn(
          'bg-destructive active:bg-destructive/90',
          Platform.select({
            web: 'hover:bg-destructive/90 focus-visible:ring-destructive/20',
          })
        ),
        outline: cn(
          'border border-border bg-background active:bg-accent',
          Platform.select({
            web: 'hover:bg-accent',
          })
        ),
        secondary: cn(
          'bg-secondary active:bg-secondary/80',
          Platform.select({ web: 'hover:bg-secondary/80' })
        ),
        ghost: cn('active:bg-accent', Platform.select({ web: 'hover:bg-accent' })),
        link: '',
      },
      // Minimum heights, so large system text grows the button instead of clipping its label.
      size: {
        default: cn('min-h-10 px-4 py-2', Platform.select({ web: 'has-[>svg]:px-3' })),
        sm: cn('min-h-9 gap-1.5 rounded-sm px-3', Platform.select({ web: 'has-[>svg]:px-2.5' })),
        lg: cn('min-h-11 rounded-sm px-6', Platform.select({ web: 'has-[>svg]:px-4' })),
        icon: 'h-10 w-10',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
);

const buttonTextVariants = cva(
  cn(
    'text-sm font-medium text-foreground',
    Platform.select({ web: 'pointer-events-none transition-colors' })
  ),
  {
    variants: {
      variant: {
        default: 'text-primary-foreground',
        destructive: 'text-white',
        outline: cn(
          'group-active:text-accent-foreground',
          Platform.select({ web: 'group-hover:text-accent-foreground' })
        ),
        secondary: 'text-secondary-foreground',
        ghost: 'group-active:text-accent-foreground',
        link: cn(
          'text-primary group-active:underline',
          Platform.select({ web: 'underline-offset-4 hover:underline group-hover:underline' })
        ),
      },
      size: {
        default: '',
        sm: '',
        lg: '',
        icon: '',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
);

/** Press ripple on Android (iOS ignores `android_ripple`); light on solid fills, navy on the rest. */
const RIPPLE_ON_SOLID = { color: 'rgba(255,255,255,0.18)' };
const RIPPLE_ON_LIGHT = { color: 'rgba(10,42,107,0.10)' };

type ButtonProps = Omit<React.ComponentProps<typeof Pressable>, 'children'> &
  React.RefAttributes<typeof Pressable> &
  VariantProps<typeof buttonVariants> & {
    children?: React.ReactNode;
    /**
     * Shows a spinner in place of the label and blocks presses. The label stays
     * mounted (invisible), so the button keeps its width.
     */
    loading?: boolean;
  };

function Button({
  className,
  variant,
  size,
  loading = false,
  disabled,
  children,
  android_ripple,
  accessibilityState,
  ...props
}: ButtonProps) {
  const resolvedVariant = variant ?? 'default';
  const isDisabled = Boolean(disabled) || loading;
  const onSolid = resolvedVariant === 'default' || resolvedVariant === 'destructive';
  // A disabled primary button reads as clearly inactive (light fill, muted but legible
  // label) rather than a washed-out brand colour. Other variants just fade.
  const solidDisabled = Boolean(disabled) && !loading && resolvedVariant === 'default';

  return (
    <TextClassContext.Provider
      value={cn(buttonTextVariants({ variant, size }), solidDisabled && 'text-muted-foreground')}>
      <Pressable
        className={cn(
          Boolean(disabled) && !solidDisabled && 'opacity-50',
          buttonVariants({ variant, size }),
          className,
          solidDisabled && 'bg-om-disabled'
        )}
        role="button"
        android_ripple={
          android_ripple === undefined
            ? onSolid
              ? RIPPLE_ON_SOLID
              : RIPPLE_ON_LIGHT
            : android_ripple
        }
        disabled={isDisabled}
        accessibilityState={{
          ...accessibilityState,
          disabled: isDisabled,
          busy: loading || accessibilityState?.busy,
        }}
        {...props}>
        {loading ? (
          <>
            <View className="flex-row items-center justify-center gap-2 opacity-0">{children}</View>
            <View
              pointerEvents="none"
              style={StyleSheet.absoluteFill}
              className="items-center justify-center">
              <ActivityIndicator size="small" color={onSolid ? COLORS.white : COLORS.navy} />
            </View>
          </>
        ) : (
          children
        )}
      </Pressable>
    </TextClassContext.Provider>
  );
}

export { Button, buttonTextVariants, buttonVariants };
export type { ButtonProps };
