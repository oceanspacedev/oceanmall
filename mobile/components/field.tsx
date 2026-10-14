import { Icon } from '@/components/ui/icon';
import { Input, type InputProps } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { cn } from '@/lib/utils';
import { CircleAlert } from 'lucide-react-native';
import { useId, type ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, View } from 'react-native';

/**
 * Accessibility props that give a text input exactly one spoken name.
 *
 * iOS has no "labelled by", so the input carries the label itself and the visible label is hidden
 * from VoiceOver (see FieldFrame). Android links the input to the label's nativeID. The error (or
 * hint) is the accessibility hint, so it is read when the field is focused.
 */
export function inputA11yProps(label: string, labelId: string, message?: string | null) {
  const accessibilityHint = message || undefined;

  return Platform.OS === 'ios'
    ? { accessibilityLabel: label, accessibilityHint }
    : { 'aria-labelledby': labelId, accessibilityHint };
}

/**
 * The visible label above an input and the message row below it: a red `error` (alert icon and a
 * polite live region, so TalkBack reads it when it appears) or, when there is none, a muted
 * `hint`. The live region stays mounted while empty, which is what makes the first error spoken.
 */
export function FieldFrame({
  label,
  labelId,
  error,
  hint,
  children,
}: {
  label: string;
  labelId: string;
  error?: string | null;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <View>
      <Text
        nativeID={labelId}
        accessibilityElementsHidden={Platform.OS === 'ios'}
        className="mb-2 text-[13px] font-semibold text-foreground">
        {label}
      </Text>
      {children}
      <View accessibilityLiveRegion="polite">
        {error ? (
          <View className="mt-1.5 flex-row items-start gap-1.5">
            <Icon as={CircleAlert} size={14} className="mt-0.5 text-om-sale-text" />
            <Text className="flex-1 text-[12px] leading-4 text-om-sale-text">{error}</Text>
          </View>
        ) : hint ? (
          <Text className="mt-1.5 text-[12px] leading-4 text-muted-foreground">{hint}</Text>
        ) : null}
      </View>
    </View>
  );
}

export type FieldProps = {
  label: string;
  /** Red border plus the message under the input. Wins over `hint`. */
  error?: string | null;
  /** Muted helper line under the input while there is no error. */
  hint?: string;
  /** A three-line text area instead of a single line. */
  multiline?: boolean;
} & Omit<InputProps, 'invalid'>;

/** Labelled text input with inline error and hint. `ref` reaches the TextInput (for chaining). */
export function Field({
  label,
  error,
  hint,
  multiline = false,
  className,
  ref,
  ...props
}: FieldProps) {
  const labelId = useId();

  return (
    <FieldFrame label={label} labelId={labelId} error={error} hint={hint}>
      <Input
        ref={ref}
        invalid={Boolean(error)}
        {...inputA11yProps(label, labelId, error || hint)}
        {...(multiline ? { multiline: true, numberOfLines: 3, textAlignVertical: 'top' } : null)}
        className={cn(multiline && 'h-auto min-h-[88px] py-2.5', className)}
        {...props}
      />
    </FieldFrame>
  );
}

/**
 * Scrolling body for a form inside <Screen>: taps reach buttons while the keyboard is open,
 * dragging dismisses it, and the content makes room for it. One mechanism per platform, never
 * both: iOS insets the scroll content itself, Android shrinks with a KeyboardAvoidingView (the
 * same split as AuthShell).
 */
export function FormScroll({
  children,
  contentContainerClassName = 'pb-8',
}: {
  children: ReactNode;
  contentContainerClassName?: string;
}) {
  const scroll = (
    <ScrollView
      className="flex-1"
      contentContainerClassName={contentContainerClassName}
      keyboardShouldPersistTaps="handled"
      keyboardDismissMode="on-drag"
      automaticallyAdjustKeyboardInsets>
      {children}
    </ScrollView>
  );

  return Platform.OS === 'android' ? (
    <KeyboardAvoidingView behavior="height" style={{ flex: 1 }}>
      {scroll}
    </KeyboardAvoidingView>
  ) : (
    scroll
  );
}
