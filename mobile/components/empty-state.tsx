import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import type { LucideIcon } from 'lucide-react-native';
import type { ReactNode } from 'react';
import { View } from 'react-native';

export function EmptyState({
  icon,
  message,
  actionLabel,
  onAction,
  children,
}: {
  icon: LucideIcon;
  message: string;
  actionLabel?: string;
  onAction?: () => void;
  children?: ReactNode;
}) {
  return (
    <View className="flex-1 items-center justify-center bg-background px-8">
      <Icon as={icon} size={28} className="mb-3 text-muted-foreground" />
      <Text variant="muted" className="text-center text-[13px]">
        {message}
      </Text>
      {actionLabel && onAction ? (
        <Button className="mt-4 px-5" onPress={onAction}>
          <Text>{actionLabel}</Text>
        </Button>
      ) : null}
      {children}
    </View>
  );
}
