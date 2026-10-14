import { Icon } from '@/components/ui/icon';
import { COLORS, IMAGE_PLACEHOLDER } from '@/lib/theme';
import { Image, type ImageContentFit } from 'expo-image';
import { ShoppingBag, type LucideIcon } from 'lucide-react-native';
import { useState, type ReactNode } from 'react';
import {
  StyleSheet,
  View,
  type DimensionValue,
  type LayoutChangeEvent,
  type StyleProp,
  type ViewStyle,
} from 'react-native';

/**
 * The one picture component for products, banners, brand and category tiles. It sits on a light
 * placeholder, cross-dissolves the image in once it has loaded, and shows a quiet glyph instead of
 * a blank box when the picture is missing or fails to load. Lists recycle rows, so give it
 * `recyclingKey` (defaults to the uri) to keep a reused cell from flashing the previous picture.
 *
 * The wrapper is decorative for screen readers unless `alt` is given: cards describe themselves
 * in their own accessibilityLabel.
 */
export function ProductImage({
  uri,
  width,
  height,
  radius,
  contentFit = 'cover',
  recyclingKey,
  alt,
  style,
  priority,
  plain = false,
  fallback,
  fallbackIcon = ShoppingBag,
}: {
  uri?: string | null;
  width?: DimensionValue;
  height?: DimensionValue;
  /** Corner radius in px. The wrapper clips, so the picture follows it. */
  radius?: number;
  contentFit?: ImageContentFit;
  recyclingKey?: string;
  alt?: string;
  /** Wrapper style: size it here (`{ width: '100%', aspectRatio: 1 }`) or with width/height. */
  style?: StyleProp<ViewStyle>;
  /** 'high' for the first picture on a screen (a banner, the top row). */
  priority?: 'low' | 'normal' | 'high';
  /** No placeholder background, for logos and icons that sit on a tinted tile. */
  plain?: boolean;
  /** Replaces the default glyph when there is no picture (initials, for example). */
  fallback?: ReactNode;
  fallbackIcon?: LucideIcon;
}) {
  // Remember WHICH uri failed, so a recycled cell that gets a new uri starts clean.
  const [failedUri, setFailedUri] = useState<string | null>(null);
  const missing = !uri || failedUri === uri;

  return (
    <View
      accessible={Boolean(alt)}
      accessibilityLabel={alt}
      accessibilityRole={alt ? 'image' : undefined}
      style={[
        plain ? null : IMAGE_PLACEHOLDER,
        { width, height, borderRadius: radius, overflow: 'hidden' },
        style,
      ]}>
      {missing ? (
        <Fallback width={width} height={height} icon={fallbackIcon}>
          {fallback}
        </Fallback>
      ) : (
        <Image
          source={{ uri }}
          style={StyleSheet.absoluteFill}
          contentFit={contentFit}
          transition={{ duration: 200, effect: 'cross-dissolve' }}
          cachePolicy="memory-disk"
          recyclingKey={recyclingKey ?? uri}
          priority={priority}
          accessible={false}
          onError={() => setFailedUri(uri)}
        />
      )}
    </View>
  );
}

/** Glyph at 40% of the box. The box is measured, so it also works for `aspectRatio` sizing. */
function Fallback({
  width,
  height,
  icon,
  children,
}: {
  width?: DimensionValue;
  height?: DimensionValue;
  icon: LucideIcon;
  children?: ReactNode;
}) {
  const [size, setSize] = useState(() =>
    typeof width === 'number' && typeof height === 'number' ? glyphSize(width, height) : 0
  );

  function onLayout(event: LayoutChangeEvent) {
    const { width: w, height: h } = event.nativeEvent.layout;
    const next = glyphSize(w, h);
    if (next !== size) {
      setSize(next);
    }
  }

  return (
    <View
      pointerEvents="none"
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      onLayout={onLayout}
      style={[StyleSheet.absoluteFill, styles.center]}>
      {children ??
        (size > 0 ? <Icon as={icon} size={size} color={COLORS.slate} strokeWidth={1.5} /> : null)}
    </View>
  );
}

function glyphSize(width: number, height: number): number {
  const size = Math.round(Math.min(width, height) * 0.4);
  // Too small to read as a glyph below 14, and a banner-sized box should not grow a huge one.
  return size < 14 ? 0 : Math.min(size, 56);
}

const styles = StyleSheet.create({
  center: { alignItems: 'center', justifyContent: 'center' },
});
