import { Icon } from '@/components/ui/icon';
import { Text } from '@/components/ui/text';
import type { OrderStep } from '@/lib/order-status';
import { COLORS } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { Check, X } from 'lucide-react-native';
import { View } from 'react-native';

/**
 * The order's progress as six equal columns: a circle per step (green with a check when done, an
 * outline otherwise), joined by a 2px line that is green between two finished steps. The latest
 * finished step is bold. A cancelled order is a single red "Dibatalkan" step.
 *
 * It reads as one element to screen readers ("Progres pesanan, tahap 3 dari 6: Dikemas"), because
 * the six separate circles would only be noise.
 */
export function OrderStepper({ steps }: { steps: OrderStep[] }) {
  if (steps.length === 1 && steps[0].key === 'cancelled') {
    return (
      <View
        accessible
        accessibilityLabel="Pesanan dibatalkan"
        className="flex-row items-center gap-3">
        <View className="size-5 items-center justify-center rounded-full bg-om-sale">
          <Icon as={X} size={12} color={COLORS.white} strokeWidth={3} />
        </View>
        <Text className="text-[13px] font-bold text-om-sale-text">Dibatalkan</Text>
      </View>
    );
  }

  const doneCount = steps.filter((step) => step.done).length;
  const latest = doneCount > 0 ? doneCount - 1 : -1;

  return (
    <View
      accessible
      accessibilityLabel={`Progres pesanan, tahap ${Math.max(doneCount, 1)} dari ${steps.length}: ${steps[Math.max(latest, 0)].label}`}
      className="flex-row">
      {steps.map((step, index) => {
        const previousDone = index > 0 && steps[index - 1].done;
        const nextDone = index < steps.length - 1 && steps[index + 1].done;

        return (
          <View key={step.key} className="flex-1 items-center">
            <View className="w-full flex-row items-center">
              <View
                className={cn(
                  'h-0.5 flex-1',
                  index === 0
                    ? 'bg-transparent'
                    : previousDone && step.done
                      ? 'bg-om-success'
                      : 'bg-om-disabled'
                )}
              />
              <View
                className={cn(
                  'size-5 items-center justify-center rounded-full',
                  step.done ? 'bg-om-success' : 'border-2 border-om-disabled bg-white'
                )}>
                {step.done ? (
                  <Icon as={Check} size={12} color={COLORS.white} strokeWidth={3} />
                ) : null}
              </View>
              <View
                className={cn(
                  'h-0.5 flex-1',
                  index === steps.length - 1
                    ? 'bg-transparent'
                    : step.done && nextDone
                      ? 'bg-om-success'
                      : 'bg-om-disabled'
                )}
              />
            </View>
            <Text
              numberOfLines={1}
              maxFontSizeMultiplier={1.15}
              className={cn(
                'mt-1.5 text-[10px]',
                index === latest ? 'font-bold text-foreground' : 'text-muted-foreground'
              )}>
              {step.label}
            </Text>
          </View>
        );
      })}
    </View>
  );
}
