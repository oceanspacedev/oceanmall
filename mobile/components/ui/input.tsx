import { COLORS } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import { Platform, TextInput } from 'react-native';

/** Light grey so a placeholder never reads like a filled value (3.25:1 on white). */
export const PLACEHOLDER_COLOR = COLORS.placeholder;

type InputProps = React.ComponentProps<typeof TextInput> &
  React.RefAttributes<TextInput> & {
    /** Red 2px border and `aria-invalid`, for a field whose last validation failed. */
    invalid?: boolean;
  };

function Input({ className, invalid = false, onFocus, onBlur, ...props }: InputProps) {
  const [focused, setFocused] = useState(false);
  // Focus and error use a 2px border; trim 1px of padding so the text does not shift.
  const thick = focused || invalid;

  return (
    <TextInput
      placeholderTextColor={PLACEHOLDER_COLOR}
      selectionColor={COLORS.navy}
      cursorColor={COLORS.navy}
      // TextInput does not go through <Text>, so cap the system font scale here.
      maxFontSizeMultiplier={1.3}
      aria-invalid={invalid || undefined}
      onFocus={(event) => {
        setFocused(true);
        onFocus?.(event);
      }}
      onBlur={(event) => {
        setFocused(false);
        onBlur?.(event);
      }}
      className={cn(
        'flex min-h-11 w-full min-w-0 flex-row items-center rounded-sm bg-background px-3 py-1 text-[15px] leading-5 text-foreground',
        thick
          ? cn('border-2 px-[11px]', invalid ? 'border-destructive' : 'border-ring')
          : 'border border-input',
        props.editable === false &&
          cn(
            'bg-om-surface opacity-60',
            Platform.select({ web: 'disabled:pointer-events-none disabled:cursor-not-allowed' })
          ),
        Platform.select({
          web: 'outline-none transition-[color,box-shadow] selection:bg-primary selection:text-primary-foreground md:text-sm',
        }),
        className
      )}
      {...props}
    />
  );
}

export { Input };
export type { InputProps };
