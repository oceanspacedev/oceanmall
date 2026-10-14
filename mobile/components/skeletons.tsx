import { Skeleton } from '@/components/ui/skeleton';
import { View } from 'react-native';

/**
 * Loading placeholders that match the real layouts closely enough that nothing jumps when the
 * data lands. They are built on Skeleton, which hides itself from screen readers; the Screen
 * wrapper (or the caller) provides the one spoken "Memuat…".
 */

/** One grid card. `compact` is the 128px rail card used on Beranda. */
export function ProductCardSkeleton({ compact = false }: { compact?: boolean }) {
  return (
    <View
      className={
        compact
          ? 'w-[128px] overflow-hidden rounded-lg border border-border bg-white'
          : 'mb-2.5 w-[48.5%] overflow-hidden rounded-lg border border-border bg-white'
      }>
      <Skeleton className="aspect-square w-full rounded-none" />
      <View className="gap-1.5 p-2">
        <Skeleton className="h-3 w-full" />
        <Skeleton className="h-3 w-2/3" />
        <Skeleton className="h-4 w-1/2" />
      </View>
    </View>
  );
}

/** Two-column grid of ProductCardSkeleton, laid out like ProductGrid. */
export function ProductGridSkeleton({ count = 4 }: { count?: number }) {
  return (
    <View className="flex-row flex-wrap justify-between">
      {Array.from({ length: count }, (_, index) => (
        <ProductCardSkeleton key={index} />
      ))}
    </View>
  );
}

/** A cart row: thumbnail, name, variant, price and the stepper pill. */
export function CartLineSkeleton() {
  return (
    <View className="flex-row gap-3 bg-white px-4 py-3.5">
      <Skeleton className="size-[84px] rounded-sm" />
      <View className="min-w-0 flex-1">
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="mt-2 h-3 w-1/3" />
        <Skeleton className="mt-2.5 h-4 w-1/4" />
        <Skeleton className="mt-2 h-9 w-24 self-end rounded-sm" />
      </View>
    </View>
  );
}

/** An order card: store line, date, first item and the total row. */
export function OrderCardSkeleton() {
  return (
    <View className="mt-2 bg-white px-4 py-3">
      <View className="flex-row items-center justify-between">
        <Skeleton className="h-3 w-32" />
        <Skeleton className="h-5 w-16" />
      </View>
      <Skeleton className="mt-2 h-3 w-40" />
      <View className="mt-3 flex-row gap-3">
        <Skeleton className="size-16 rounded-sm" />
        <View className="min-w-0 flex-1 gap-2 pt-1">
          <Skeleton className="h-3.5 w-full" />
          <Skeleton className="h-3 w-1/2" />
        </View>
      </View>
      <View className="mt-3 flex-row items-center justify-between border-t border-border pt-3">
        <View className="gap-1.5">
          <Skeleton className="h-3 w-20" />
          <Skeleton className="h-4 w-28" />
        </View>
        <Skeleton className="h-9 w-28 rounded-sm" />
      </View>
    </View>
  );
}

/** A white section card: a title bar and three text rows. */
export function SectionSkeleton() {
  return (
    <View className="mt-2 bg-white px-4 py-3.5">
      <Skeleton className="mb-3 h-4 w-32" />
      <View className="gap-2.5">
        <Skeleton className="h-3 w-full" />
        <Skeleton className="h-3 w-5/6" />
        <Skeleton className="h-3 w-2/3" />
      </View>
    </View>
  );
}

/** A notification row: round icon, title and a one-line body. */
export function NotificationRowSkeleton() {
  return (
    <View className="flex-row gap-3 border-b border-border bg-white px-4 py-3.5">
      <Skeleton className="size-10 rounded-full" />
      <View className="min-w-0 flex-1 gap-2">
        <Skeleton className="h-4 w-2/3" />
        <Skeleton className="h-3 w-full" />
        <Skeleton className="h-3 w-1/4" />
      </View>
    </View>
  );
}

/** An address card: name, street lines and the phone number. */
export function AddressCardSkeleton() {
  return (
    <View className="mt-2 bg-white px-4 py-3.5">
      <View className="flex-row items-center justify-between">
        <Skeleton className="h-4 w-1/2" />
        <Skeleton className="h-5 w-14" />
      </View>
      <View className="mt-3 gap-2">
        <Skeleton className="h-3 w-full" />
        <Skeleton className="h-3 w-3/4" />
        <Skeleton className="h-3 w-24" />
      </View>
    </View>
  );
}

/** A detail page (order, payment): a status banner and three section cards. */
export function DetailSkeleton() {
  return (
    <View>
      <Skeleton className="h-20 w-full rounded-none" />
      <SectionSkeleton />
      <SectionSkeleton />
      <SectionSkeleton />
    </View>
  );
}
