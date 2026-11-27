/// <reference types="node" />
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  PIN_POINT_REGEX,
  allSelectedRatesSupportCod,
  etaLabel,
  findPlacedOrderWith,
  isCodPaymentMethod,
  matchPlacedOrder,
  paymentDisabledReason,
  paymentLimitNote,
  rateCodStatus,
  rateSupportsCod,
  resolveDestinationSearchError,
  resolveEmptyShippingHint,
} from '@/lib/checkout-helpers';
import type { OrderSummary, PaymentMethodOption, ShippingRate } from '@/lib/api';

describe('checkout-helpers: empty shipping rates & hints', () => {
  it('prioritizes shipping_rates_hint over generic fallback across failure reasons', () => {
    const quotaHint =
      'Batas cek ongkir harian toko ini sudah tercapai. Silakan coba lagi setelah 27 Nov 2026 00:00 WIB atau hubungi kami untuk menyelesaikan pesanan.';
    assert.equal(
      resolveEmptyShippingHint({
        addressPresent: true,
        searchEnabled: true,
        shippingRatesHint: quotaHint,
      }),
      quotaHint
    );

    const noAllocationHint =
      'Stok produk di keranjang tidak dapat dipenuhi oleh lokasi gudang toko saat ini.';
    assert.equal(
      resolveEmptyShippingHint({
        addressPresent: true,
        searchEnabled: false,
        shippingRatesHint: noAllocationHint,
      }),
      noAllocationHint
    );

    const noDestHint =
      'Kecamatan tujuan pengiriman belum dipilih. Silakan kembali ke langkah Alamat dan pilih kecamatan dari hasil pencarian otomatis.';
    assert.equal(
      resolveEmptyShippingHint({
        addressPresent: true,
        searchEnabled: true,
        shippingRatesHint: noDestHint,
      }),
      noDestHint
    );
  });

  it('falls back cleanly when shipping_rates_hint is absent or whitespace', () => {
    assert.equal(
      resolveEmptyShippingHint({
        addressPresent: false,
        searchEnabled: true,
        shippingRatesHint: null,
      }),
      'Isi alamat dulu untuk melihat pilihan kurir dan ongkir.'
    );
    assert.equal(
      resolveEmptyShippingHint({
        addressPresent: true,
        searchEnabled: true,
        shippingRatesHint: '   ',
      }),
      'Ongkir belum tersedia untuk alamat ini. Coba kecamatan lain.'
    );
    assert.equal(
      resolveEmptyShippingHint({
        addressPresent: true,
        searchEnabled: false,
        shippingRatesHint: null,
      }),
      'Ongkir belum tersedia untuk alamat ini. Periksa kembali alamat pengirimanmu.'
    );
  });
});

describe('checkout-helpers: COD rate indicators & multi-package intersection', () => {
  const rate = (overrides: Partial<ShippingRate>): ShippingRate => ({
    service_code: 'REG',
    service_name: 'Reguler',
    amount: 15000,
    ...overrides,
  });

  it('detects COD support status across all provider flags', () => {
    assert.equal(rateCodStatus(rate({ is_cod: true })), true);
    assert.equal(rateCodStatus(rate({ is_cod: false })), false);
    assert.equal(rateCodStatus(rate({ is_cod: true, kiriminaja_cod: false })), false);
    assert.equal(rateCodStatus(rate({ available_for_cash_on_delivery: false })), false);
    assert.equal(rateCodStatus(rate({})), null);
    assert.equal(rateSupportsCod(rate({})), true);
    assert.equal(rateSupportsCod(rate({ is_cod: false })), false);
  });

  it('evaluates COD support for single-package and split-shipment selections', () => {
    const jneReg = rate({ service_code: 'JNE-REG', is_cod: true });
    const jneYes = rate({ service_code: 'JNE-YES', is_cod: false });

    assert.equal(
      allSelectedRatesSupportCod({
        isSplitShipment: false,
        allocation: [],
        shippingRates: [jneReg, jneYes],
        selectedRate: 'JNE-REG',
        selectedRatesByShipment: {},
      }),
      true
    );
    assert.equal(
      allSelectedRatesSupportCod({
        isSplitShipment: false,
        allocation: [],
        shippingRates: [jneReg, jneYes],
        selectedRate: 'JNE-YES',
        selectedRatesByShipment: {},
      }),
      false
    );

    assert.equal(
      allSelectedRatesSupportCod({
        isSplitShipment: true,
        allocation: [{ inventory_id: 1 }, { inventory_id: 2 }],
        shippingRates: [],
        selectedRate: null,
        shippingRatesByShipment: {
          '1': [rate({ service_code: 'REG', is_cod: true })],
          '2': [rate({ service_code: 'REG', is_cod: false })],
        },
        selectedRatesByShipment: { '1': 'REG', '2': 'REG' },
      }),
      false
    );

    assert.equal(
      allSelectedRatesSupportCod({
        isSplitShipment: true,
        allocation: [{ inventory_id: 1 }, { inventory_id: 2 }],
        shippingRates: [],
        selectedRate: null,
        shippingRatesByShipment: {
          '1': [rate({ service_code: 'REG', is_cod: true })],
          '2': [rate({ service_code: 'ECO', is_cod: true })],
        },
        selectedRatesByShipment: { '1': 'REG', '2': 'ECO' },
      }),
      true
    );
  });
});

describe('checkout-helpers: payment limits & COD guard', () => {
  const codMethod: PaymentMethodOption = {
    id: 1,
    title: 'Bayar di Tempat (COD)',
    slug: 'cod',
    driver: 'manual',
    payment_type: 'cod',
  };
  const qrisMethod: PaymentMethodOption = {
    id: 2,
    title: 'QRIS',
    slug: 'qris',
    driver: 'komerce',
    payment_type: 'qris',
    min_amount: 1000,
    max_amount: 100_000_000,
  };

  it('enforces min/max bounds including QRIS 1k..100M limits', () => {
    assert.equal(paymentLimitNote(qrisMethod, 500, 'IDR'), 'Minimal Rp1.000 untuk metode ini.');
    assert.equal(
      paymentLimitNote(qrisMethod, 150_000_000, 'IDR'),
      'Maksimal Rp100.000.000 untuk metode ini.'
    );
    assert.equal(paymentLimitNote(qrisMethod, 50_000, 'IDR'), null);
  });

  it('disables COD payment method when selected rates do not support COD', () => {
    assert.equal(isCodPaymentMethod(codMethod), true);
    assert.equal(isCodPaymentMethod(qrisMethod), false);

    assert.equal(
      paymentDisabledReason(codMethod, { amount: 50_000, currency: 'IDR', ratesSupportCod: false }),
      'Kurir yang dipilih tidak mendukung Cash on Delivery (COD).'
    );
    assert.equal(
      paymentDisabledReason(codMethod, { amount: 50_000, currency: 'IDR', ratesSupportCod: true }),
      null
    );
  });
});

describe('checkout-helpers: COD timeout order recovery & misc', () => {
  it('queries /orders without ?status=unpaid so COD orders are recovered', async () => {
    const requestedPaths: string[] = [];
    const submittedAt = Date.parse('2026-11-26T08:00:00Z');

    const result = await findPlacedOrderWith(async <T>(path: string): Promise<T> => {
      requestedPaths.push(path);
      if (path === '/cart') {
        return {
          data: {
            id: 1,
            currency: 'IDR',
            lines: [],
            totals: { subtotal: 0, discount: 0, tax: 0, total: 0 },
          },
        } as T;
      }
      if (path === '/orders') {
        return {
          data: [
            {
              id: 42,
              number: 'ORD-COD-999',
              status: 'processing',
              payment_status: 'unpaid',
              amount: 120000,
              created_at: '2026-11-26T08:00:05Z',
              payment_driver: 'manual',
            },
          ],
        } as T;
      }
      throw new Error(`Unexpected path: ${path}`);
    }, submittedAt);

    assert.deepEqual(requestedPaths, ['/cart', '/orders']);
    assert.equal(requestedPaths.includes('/orders?status=unpaid'), false);
    assert.deepEqual(result, { state: 'placed', number: 'ORD-COD-999' });
  });

  it('returns none when cart still has lines and ignores cancelled/stale orders', async () => {
    const submittedAt = Date.parse('2026-11-26T08:00:00Z');
    const orders: OrderSummary[] = [
      {
        id: 1,
        number: 'ORD-CANCELLED',
        status: 'cancelled',
        payment_status: 'voided',
        amount: 50000,
        created_at: '2026-11-26T08:00:02Z',
      },
      {
        id: 2,
        number: 'ORD-STALE',
        status: 'pending',
        payment_status: 'unpaid',
        amount: 50000,
        created_at: '2026-11-26T07:55:00Z',
      },
    ];

    assert.equal(matchPlacedOrder(orders, submittedAt), null);

    const nonEmptyCartResult = await findPlacedOrderWith(async <T>(path: string): Promise<T> => {
      if (path === '/cart') {
        return {
          data: {
            id: 1,
            currency: 'IDR',
            lines: [{ id: 10, quantity: 1, unit_price: 10000, name: 'Test', purchasable_id: 5 }],
            totals: { subtotal: 10000, discount: 0, tax: 0, total: 10000 },
          },
        } as T;
      }
      throw new Error('Should not query /orders when cart still has items');
    }, submittedAt);
    assert.deepEqual(nonEmptyCartResult, { state: 'none' });

    const errorResult = await findPlacedOrderWith(async () => {
      throw new Error('Network down');
    }, submittedAt);
    assert.deepEqual(errorResult, { state: 'unknown' });
  });

  it('validates pin_point coordinates, etaLabel, and destination error helper', () => {
    assert.equal(PIN_POINT_REGEX.test('-6.200000,106.816666'), true);
    assert.equal(PIN_POINT_REGEX.test(' -6.2 , 106.8 '), true);
    assert.equal(PIN_POINT_REGEX.test('jakarta'), false);
    assert.equal(etaLabel('2-3'), 'Estimasi 2-3 hari');
    assert.equal(etaLabel('1-2 hari'), 'Estimasi 1-2 hari');
    assert.equal(etaLabel(null), 'Estimasi belum tersedia');
    assert.equal(
      resolveDestinationSearchError(new Error('boom'), (_err, fallback) => fallback ?? ''),
      'Pencarian kecamatan sedang gangguan. Coba lagi beberapa menit.'
    );
  });
});
