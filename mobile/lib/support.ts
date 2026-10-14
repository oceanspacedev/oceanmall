import { SUPPORT_EMAIL, SUPPORT_WHATSAPP } from '@/lib/config';
import { toast } from '@/lib/toast';
import * as Clipboard from 'expo-clipboard';
import { Alert, Linking } from 'react-native';

/*
 * Ways to reach support. Both come from EXPO_PUBLIC_SUPPORT_* (lib/config.ts) and are empty when
 * unset, so every entry point here hides or no-ops rather than offering a dead link.
 */

export type SupportChannel = {
  key: 'whatsapp' | 'email';
  label: string;
  /** What is shown under the label, and what a copy puts on the clipboard. */
  address: string;
  url: string;
};

/** True when at least one way of reaching support (WhatsApp or e-mail) is configured. */
export const HAS_SUPPORT_CHANNEL = Boolean(SUPPORT_WHATSAPP || SUPPORT_EMAIL);

/** The support channels that are really configured, never a dead one. */
export function supportChannels(): SupportChannel[] {
  const channels: SupportChannel[] = [];
  if (SUPPORT_WHATSAPP) {
    channels.push({
      key: 'whatsapp',
      label: 'Chat WhatsApp',
      address: `+${SUPPORT_WHATSAPP}`,
      url: `https://wa.me/${SUPPORT_WHATSAPP}`,
    });
  }
  if (SUPPORT_EMAIL) {
    channels.push({
      key: 'email',
      label: 'Kirim email',
      address: SUPPORT_EMAIL,
      url: `mailto:${SUPPORT_EMAIL}`,
    });
  }

  return channels;
}

/**
 * Opens a channel in WhatsApp, the browser or the mail app. When the phone has nothing to open it
 * with (no mail account set up, say) the address is shown and can be copied instead.
 */
export async function openSupportChannel(channel: SupportChannel): Promise<void> {
  try {
    await Linking.openURL(channel.url);
  } catch {
    Alert.alert(channel.label, channel.address, [
      {
        text: 'Salin',
        onPress: () => {
          void Clipboard.setStringAsync(channel.address).then(() =>
            toast.show({ message: 'Tersalin', tone: 'success' })
          );
        },
      },
      { text: 'Tutup', style: 'cancel' },
    ]);
  }
}

/** One tap from the "Hubungi kami" row: straight to the only channel, or a choice of both. */
export function contactSupport(): void {
  const channels = supportChannels();
  if (channels.length === 1) {
    void openSupportChannel(channels[0]);
  } else if (channels.length > 1) {
    Alert.alert('Hubungi kami', 'Pilih cara menghubungi kami.', [
      ...channels.map((channel) => ({
        text: channel.label,
        onPress: () => void openSupportChannel(channel),
      })),
      { text: 'Batal', style: 'cancel' as const },
    ]);
  }
}

/**
 * Opens WhatsApp (or, if that cannot open, the mail app) with `message` already typed, so
 * support gets the order number without the customer having to copy it. Returns false when
 * nothing could be opened, or when no channel is configured. These are links to other apps, so
 * they stay on Linking: the in-app browser is only for the payment page.
 */
export async function sendSupportMessage(message: string, subject: string): Promise<boolean> {
  if (SUPPORT_WHATSAPP) {
    try {
      await Linking.openURL(
        `https://wa.me/${SUPPORT_WHATSAPP}?text=${encodeURIComponent(message)}`
      );

      return true;
    } catch {
      // Fall back to e-mail below.
    }
  }

  if (SUPPORT_EMAIL) {
    try {
      await Linking.openURL(
        `mailto:${SUPPORT_EMAIL}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(message)}`
      );

      return true;
    } catch {
      // Nothing left to try.
    }
  }

  return false;
}
