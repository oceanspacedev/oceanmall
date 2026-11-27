import type { ShippingCapabilities } from '@/lib/api';
import { PIN_POINT_REGEX } from '@/lib/checkout-helpers';

export function requiresDestinationPicker(capabilities?: ShippingCapabilities | null): boolean {
  return capabilities?.requires_destination ?? true;
}

export type SavedAddressFormInput = {
  firstName: string;
  lastName: string;
  phone: string;
  street: string;
  city: string;
  postalCode: string;
  destinationId: string | null;
  pinPoint: string;
};

export function validateSavedAddressForm(
  input: SavedAddressFormInput,
  capabilities: ShippingCapabilities
): { formError: string | null; fieldErrors: Record<string, string[]> } {
  const fieldErrors: Record<string, string[]> = {};

  if (!input.firstName.trim()) {
    fieldErrors.first_name = ['Isi nama depan.'];
  }
  if (!input.lastName.trim()) {
    fieldErrors.last_name = ['Isi nama belakang.'];
  }
  if (!input.phone.trim()) {
    fieldErrors.phone_number = ['Isi nomor HP.'];
  }
  if (!input.street.trim()) {
    fieldErrors.street_address = ['Isi alamat lengkap.'];
  }

  const missingDestination = capabilities.requires_destination && !input.destinationId?.trim();
  if (missingDestination) {
    fieldErrors.destination_id = ['Pilih kecamatan dari daftar pencarian.'];
  }

  if (!missingDestination) {
    if (!input.city.trim()) {
      fieldErrors.city = ['Isi kota atau kabupaten.'];
    }
    if (!input.postalCode.trim()) {
      fieldErrors.postal_code = ['Isi kode pos.'];
    }
  }

  const trimmedPin = input.pinPoint.trim();
  if (capabilities.requires_coordinates && trimmedPin !== '' && !PIN_POINT_REGEX.test(trimmedPin)) {
    fieldErrors.pin_point = [
      'Format koordinat harus lintang,bujur (contoh: -6.200000,106.816666).',
    ];
  }

  const hasBasicMissing =
    !input.firstName.trim() ||
    !input.lastName.trim() ||
    !input.phone.trim() ||
    !input.street.trim() ||
    (!missingDestination && (!input.city.trim() || !input.postalCode.trim()));

  let formError: string | null = null;
  if (hasBasicMissing) {
    formError = 'Lengkapi nama, nomor HP, alamat, kota, dan kode pos.';
  } else if (missingDestination) {
    formError = 'Pilih kecamatan tujuan dari daftar pencarian.';
  } else if (fieldErrors.pin_point) {
    formError = fieldErrors.pin_point[0];
  }

  return { formError, fieldErrors };
}

export function destinationSearchErrorMessage(
  error: unknown,
  errorMessageFn: (error: unknown, fallback?: string) => string
): string {
  return errorMessageFn(error, 'Pencarian kecamatan sedang gangguan. Coba lagi beberapa menit.');
}
