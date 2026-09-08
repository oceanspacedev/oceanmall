import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';

export function PillButton({
  label,
  onPress,
  disabled,
  outline = false,
}: {
  label: string;
  onPress: () => void;
  disabled?: boolean;
  outline?: boolean;
}) {
  return (
    <Button variant={outline ? 'outline' : 'default'} disabled={disabled} onPress={onPress}>
      <Text>{label}</Text>
    </Button>
  );
}
