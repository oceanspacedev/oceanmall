import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { cn } from '@/lib/utils';
import { useEffect, useState, type ReactNode } from 'react';
import { Alert, Modal, Platform, Pressable, View, type AlertOptions } from 'react-native';

export type ConfirmButton = {
  text?: string;
  style?: 'default' | 'cancel' | 'destructive';
  onPress?: () => void;
};

type DialogRequest = {
  title: string;
  message?: string;
  buttons: ConfirmButton[];
  cancelable: boolean;
};

type Listener = (req: DialogRequest | null) => void;

const listeners = new Set<Listener>();

function emit(req: DialogRequest | null) {
  listeners.forEach((listener) => listener(req));
}

/**
 * Cross-platform alert/confirmation dialog. Uses native `Alert.alert` on iOS/Android
 * and renders an accessible React Native Modal on Expo Web (where `Alert.alert` is a no-op).
 */
export function confirmAlert(
  title: string,
  message?: string,
  buttons: ConfirmButton[] = [{ text: 'OK' }],
  options?: AlertOptions
): void {
  if (Platform.OS !== 'web') {
    Alert.alert(title, message, buttons, options);
    return;
  }

  const list = buttons.length > 0 ? buttons : [{ text: 'OK' }];
  emit({
    title,
    message,
    buttons: list,
    cancelable: options?.cancelable ?? true,
  });
}

export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [request, setRequest] = useState<DialogRequest | null>(null);

  useEffect(() => {
    listeners.add(setRequest);
    return () => {
      listeners.delete(setRequest);
    };
  }, []);

  const close = () => setRequest(null);

  const handlePress = (btn: ConfirmButton) => {
    close();
    btn.onPress?.();
  };

  return (
    <>
      {children}
      <Modal
        visible={Boolean(request)}
        transparent
        animationType="fade"
        onRequestClose={() => {
          if (request?.cancelable) {
            close();
          }
        }}>
        <View className="flex-1 items-center justify-center bg-black/50 p-4">
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Tutup dialog"
            onPress={() => {
              if (request?.cancelable) {
                close();
              }
            }}
            className="absolute inset-0"
          />
          {request ? (
            <View className="w-full max-w-sm rounded-md bg-white p-5">
              <Text role="heading" aria-level={2} className="text-[16px] font-bold text-foreground">
                {request.title}
              </Text>
              {request.message ? (
                <Text className="mt-1.5 text-[13px] leading-5 text-muted-foreground">
                  {request.message}
                </Text>
              ) : null}
              <View
                className={cn(
                  'mt-4 gap-2',
                  request.buttons.length === 2 ? 'flex-row justify-end' : 'flex-col'
                )}>
                {request.buttons.map((button, index) => {
                  const isCancel = button.style === 'cancel';
                  const isDestructive = button.style === 'destructive';
                  return (
                    <Button
                      key={`${button.text ?? 'btn'}-${index}`}
                      variant={isCancel ? 'outline' : isDestructive ? 'destructive' : 'default'}
                      size="sm"
                      onPress={() => handlePress(button)}
                      className={cn(
                        'min-h-10 rounded-sm px-4',
                        request.buttons.length > 2 && 'w-full justify-center'
                      )}>
                      <Text
                        className={cn(
                          'text-[13px] font-bold',
                          isCancel ? 'text-foreground' : 'text-white'
                        )}>
                        {button.text ?? 'OK'}
                      </Text>
                    </Button>
                  );
                })}
              </View>
            </View>
          ) : null}
        </View>
      </Modal>
    </>
  );
}
