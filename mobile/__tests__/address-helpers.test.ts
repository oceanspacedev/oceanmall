/// <reference types="node" />
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  destinationSearchErrorMessage,
  requiresDestinationPicker,
  validateSavedAddressForm,
  type SavedAddressFormInput,
} from '@/lib/address-helpers';
import { ApiError, errorMessage, type ShippingCapabilities } from '@/lib/api';

const baseForm: SavedAddressFormInput = {
  firstName: 'Budi',
  lastName: 'Santoso',
  phone: '081234567890',
  street: 'Jl. Sudirman No. 10',
  city: 'Jakarta Selatan',
  postalCode: '12120',
  destinationId: null,
  pinPoint: '',
};

const keylessTier: ShippingCapabilities = {
  destination_search: false,
  requires_destination: false,
  requires_coordinates: false,
};

const starterTier: ShippingCapabilities = {
  destination_search: true,
  requires_destination: true,
  requires_coordinates: false,
};

const deliveryOnlyTier: ShippingCapabilities = {
  destination_search: true,
  requires_destination: true,
  requires_coordinates: true,
};

const komshipTier: ShippingCapabilities = {
  destination_search: true,
  requires_destination: true,
  requires_coordinates: true,
};

describe('address-helpers across all 4 shipping capability tiers', () => {
  it('defaults requiresDestinationPicker to true for legacy payloads', () => {
    assert.equal(requiresDestinationPicker(undefined), true);
    assert.equal(requiresDestinationPicker(null), true);
    assert.equal(requiresDestinationPicker(keylessTier), false);
    assert.equal(requiresDestinationPicker(starterTier), true);
  });

  it('Keyless tier allows saving without destinationId when basic fields are complete', () => {
    const result = validateSavedAddressForm(baseForm, keylessTier);
    assert.equal(result.formError, null);
    assert.deepEqual(result.fieldErrors, {});
  });

  it('Starter tier requires destinationId and rejects missing destination', () => {
    const missing = validateSavedAddressForm(baseForm, starterTier);
    assert.equal(missing.formError, 'Pilih kecamatan tujuan dari daftar pencarian.');
    assert.deepEqual(missing.fieldErrors.destination_id, [
      'Pilih kecamatan dari daftar pencarian.',
    ]);

    const valid = validateSavedAddressForm({ ...baseForm, destinationId: '17471' }, starterTier);
    assert.equal(valid.formError, null);
    assert.deepEqual(valid.fieldErrors, {});
  });

  it('does not emit city or postal_code field errors when destination is missing under requires_destination', () => {
    const missingWithBlankRegion = validateSavedAddressForm(
      { ...baseForm, destinationId: null, city: '', postalCode: '' },
      starterTier
    );
    assert.equal(missingWithBlankRegion.formError, 'Pilih kecamatan tujuan dari daftar pencarian.');
    assert.deepEqual(missingWithBlankRegion.fieldErrors.destination_id, [
      'Pilih kecamatan dari daftar pencarian.',
    ]);
    assert.equal(missingWithBlankRegion.fieldErrors.city, undefined);
    assert.equal(missingWithBlankRegion.fieldErrors.postal_code, undefined);

    const pickedWithBlankRegion = validateSavedAddressForm(
      { ...baseForm, destinationId: '17471', city: '', postalCode: '' },
      starterTier
    );
    assert.equal(
      pickedWithBlankRegion.formError,
      'Lengkapi nama, nomor HP, alamat, kota, dan kode pos.'
    );
    assert.deepEqual(pickedWithBlankRegion.fieldErrors.city, ['Isi kota atau kabupaten.']);
    assert.deepEqual(pickedWithBlankRegion.fieldErrors.postal_code, ['Isi kode pos.']);
  });

  it('Delivery-only and Komship tiers require destinationId and validate non-empty pin_point', () => {
    const missingDest = validateSavedAddressForm(baseForm, deliveryOnlyTier);
    assert.deepEqual(missingDest.fieldErrors.destination_id, [
      'Pilih kecamatan dari daftar pencarian.',
    ]);

    const badPin = validateSavedAddressForm(
      { ...baseForm, destinationId: '3171', pinPoint: 'not-coords' },
      deliveryOnlyTier
    );
    assert.ok(badPin.fieldErrors.pin_point?.[0]);
    assert.equal(badPin.formError, badPin.fieldErrors.pin_point[0]);

    const validOptionalPin = validateSavedAddressForm(
      { ...baseForm, destinationId: '3171', pinPoint: '' },
      komshipTier
    );
    assert.equal(validOptionalPin.formError, null);

    const validCoords = validateSavedAddressForm(
      { ...baseForm, destinationId: '3171', pinPoint: '-6.200000,106.816666' },
      komshipTier
    );
    assert.equal(validCoords.formError, null);
  });

  it('surfaces HTTP 429 quota WIB and 502 outage destination search errors', () => {
    const quota = new ApiError(
      'Kuota pencarian kecamatan habis. Coba lagi setelah 00:00 WIB.',
      429,
      {
        message: 'Kuota pencarian kecamatan habis. Coba lagi setelah 00:00 WIB.',
      }
    );
    assert.equal(
      destinationSearchErrorMessage(quota, errorMessage),
      'Kuota pencarian kecamatan habis. Coba lagi setelah 00:00 WIB.'
    );

    const outage = new ApiError('Pencarian destinasi sedang tidak tersedia.', 502, {
      message: 'Pencarian destinasi sedang tidak tersedia.',
    });
    assert.equal(
      destinationSearchErrorMessage(outage, errorMessage),
      'Pencarian destinasi sedang tidak tersedia.'
    );
  });
});
