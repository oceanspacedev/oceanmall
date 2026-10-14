import { FieldFrame, inputA11yProps } from '@/components/field';
import { Icon } from '@/components/ui/icon';
import { Input, type InputProps } from '@/components/ui/input';
import { ripple } from '@/lib/ripple';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-react-native';
import { useId, useState } from 'react';
import { Pressable, View } from 'react-native';

/**
 * Labelled password input with a show/hide toggle (a 44pt target inside the field's right edge),
 * the same inline error and hint as Field, and a forwarded `ref` for return-key chaining.
 */
export function PasswordField({
  label,
  error,
  hint,
  className,
  ref,
  ...props
}: {
  label: string;
  error?: string | null;
  hint?: string;
} & Omit<InputProps, 'secureTextEntry' | 'invalid'>) {
  const labelId = useId();
  const [visible, setVisible] = useState(false);

  return (
    <FieldFrame label={label} labelId={labelId} error={error} hint={hint}>
      <View className="justify-center">
        <Input
          ref={ref}
          invalid={Boolean(error)}
          {...inputA11yProps(label, labelId, error || hint)}
          secureTextEntry={!visible}
          autoCapitalize="none"
          autoCorrect={false}
          className={cn('pr-12', className)}
          {...props}
        />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={visible ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'}
          onPress={() => setVisible((current) => !current)}
          android_ripple={ripple.icon}
          className="absolute right-0 top-0 h-11 w-11 items-center justify-center active:opacity-60">
          <Icon as={visible ? EyeOff : Eye} size={18} className="text-muted-foreground" />
        </Pressable>
      </View>
    </FieldFrame>
  );
}
