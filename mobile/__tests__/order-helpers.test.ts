/// <reference types="node" />
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { statusLabel } from '@/lib/format';
import {
  canConfirmOrderReceived,
  canTrackShipment,
  expiredPaymentMessage,
  paymentNeedsRenewal,
  trackingToastMessage,
  trimmedAwb,
} from '@/lib/order-helpers';
import { orderStage, orderSteps } from '@/lib/order-status';

describe('order-helpers: expired payment messages & renewal boundary', () => {
  it('returns distinct copy when canRetry is true vs false for panel and placed variants', () => {
    const panelRetry = expiredPaymentMessage(true, 'panel');
    assert.match(panelRetry, /Buat pembayaran baru/);

    const panelNoRetry = expiredPaymentMessage(false, 'panel');
    assert.doesNotMatch(panelNoRetry, /Buat pembayaran baru/i);
    assert.match(panelNoRetry, /tidak tersedia/);

    const placedRetry = expiredPaymentMessage(true, 'placed');
    assert.match(placedRetry, /membuat pembayaran baru/);

    const placedNoRetry = expiredPaymentMessage(false, 'placed');
    assert.doesNotMatch(placedNoRetry, /membuat pembayaran baru/i);
    assert.match(placedNoRetry, /tidak tersedia/);
  });

  it('treats exact expiry boundary (expires === now) as needing renewal and handles WIB zoneless timestamps', () => {
    const deadlineIso = '2026-10-14T09:37:00+07:00';
    const deadlineMs = Date.parse(deadlineIso);

    assert.equal(paymentNeedsRenewal(null, deadlineMs), true);
    assert.equal(paymentNeedsRenewal({}, deadlineMs), true);
    assert.equal(
      paymentNeedsRenewal(
        { virtual_account_number: '12345', expiry_date: deadlineIso },
        deadlineMs - 1
      ),
      false
    );
    assert.equal(
      paymentNeedsRenewal(
        { virtual_account_number: '12345', expiry_date: deadlineIso },
        deadlineMs
      ),
      true
    );
    assert.equal(
      paymentNeedsRenewal(
        { virtual_account_number: '12345', expiry_date: deadlineIso },
        deadlineMs + 1
      ),
      true
    );
    // Zoneless API timestamps are interpreted as WIB (+07:00)
    assert.equal(
      paymentNeedsRenewal(
        { qris_string: '000201', expiry_date: '2026-10-14 09:37:00' },
        deadlineMs
      ),
      true
    );
  });
});

describe('order-helpers: shipment tracking & Indonesian status labels', () => {
  it('requires trimmed non-empty AWB and can_track !== false', () => {
    assert.equal(trimmedAwb({ awb: '   ', tracking_number: '' }), null);
    assert.equal(trimmedAwb({ awb: null, tracking_number: ' JNE123 ' }), 'JNE123');

    assert.equal(canTrackShipment({ awb: 'JNE123', can_track: true }), true);
    assert.equal(canTrackShipment({ awb: 'JNE123' }), true);
    assert.equal(canTrackShipment({ awb: 'JNE123', can_track: false }), false);
    assert.equal(canTrackShipment({ awb: '   ', can_track: true }), false);
    assert.equal(canTrackShipment({ awb: null, tracking_number: null, can_track: true }), false);
  });

  it('formats tracking toast messages cleanly', () => {
    assert.equal(trackingToastMessage(true), 'Status pengiriman diperbarui');
    assert.equal(trackingToastMessage(undefined), 'Status pengiriman diperbarui');
    assert.equal(trackingToastMessage(false), 'Status pengiriman sudah yang terbaru');
  });

  it('translates delivery_failed, proof, and RMA statuses to Indonesian', () => {
    assert.equal(statusLabel('delivery_failed'), 'Pengiriman gagal');
    assert.equal(statusLabel('approved'), 'Disetujui');
    assert.equal(statusLabel('rejected'), 'Ditolak');
    assert.equal(statusLabel('requested'), 'Diajukan');
    assert.equal(statusLabel('under_review'), 'Sedang ditinjau');
  });

  it('keeps COD shipped-before-paid order stage and steps accurate', () => {
    const codShipped = {
      status: 'processing',
      payment_status: 'unpaid',
      shipping_status: 'shipped',
      payment_driver: 'manual',
      shipments: [{ status: 'in_transit', awb: 'AWB123' }],
    };
    const stage = orderStage(codShipped);
    assert.equal(stage.key, 'shipped');
    assert.equal(stage.label, 'Dikirim');

    const steps = orderSteps(codShipped);
    const paidStep = steps.find((s) => s.key === 'paid');
    const shippedStep = steps.find((s) => s.key === 'shipped');
    assert.equal(paidStep?.done, false);
    assert.equal(shippedStep?.done, true);
  });
});

describe('order-helpers: canConfirmOrderReceived & superseded reshipped parcels', () => {
  it('allows confirmation when a superseded returned or delivery_failed parcel has an active reshipped parcel with AWB', () => {
    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'paid',
        shipping_status: 'shipped',
        is_cod: false,
        shipments: [
          { id: 1, status: 'returned', is_superseded: true, awb: 'OLD-RET-1' },
          { id: 2, status: 'in_transit', is_superseded: false, awb: 'NEW-RESHIP-1' },
        ],
      }),
      true
    );

    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'unpaid',
        shipping_status: 'shipped',
        is_cod: true,
        shipments: [
          { id: 1, status: 'delivery_failed', is_superseded: true, awb: 'OLD-FAIL-1' },
          { id: 2, status: 'labeled', is_superseded: false, tracking_number: 'NEW-RESHIP-2' },
        ],
      }),
      true
    );
  });

  it('blocks confirmation when replacement parcel lacks AWB or when a non-superseded parcel is returned/delivery_failed', () => {
    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'paid',
        shipping_status: 'unfulfilled',
        is_cod: false,
        shipments: [
          { id: 1, status: 'returned', is_superseded: true, awb: 'OLD-RET-1' },
          { id: 2, status: 'pending', is_superseded: false, awb: null, tracking_number: null },
        ],
      }),
      false
    );

    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'paid',
        shipping_status: 'partially_returned',
        is_cod: false,
        shipments: [
          { id: 1, status: 'returned', is_superseded: false, awb: 'OLD-RET-1' },
          { id: 2, status: 'in_transit', is_superseded: false, awb: 'AWB-2' },
        ],
      }),
      false
    );

    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'paid',
        shipping_status: 'shipped',
        is_cod: false,
        shipments: [
          { id: 1, status: 'delivery_failed', awb: 'OLD-FAIL-1' },
          { id: 2, status: 'in_transit', awb: 'AWB-2' },
        ],
      }),
      false
    );
  });

  it('ignores cancelled parcels while enforcing live AWB, payment, and completion guards', () => {
    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'paid',
        shipping_status: 'shipped',
        is_cod: false,
        shipments: [
          { id: 1, status: 'cancelled', awb: null },
          { id: 2, status: 'in_transit', awb: 'AWB-LIVE' },
        ],
      }),
      true
    );

    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'paid',
        shipping_status: 'partially_delivered',
        is_cod: false,
        shipments: [
          { id: 1, status: 'returned', is_superseded: true, awb: 'OLD-RET' },
          { id: 2, status: 'delivered', is_superseded: false, awb: 'NEW-DELIV' },
        ],
      }),
      false
    );

    assert.equal(
      canConfirmOrderReceived({
        status: 'processing',
        payment_status: 'unpaid',
        shipping_status: 'shipped',
        is_cod: false,
        shipments: [{ id: 1, status: 'in_transit', awb: 'AWB-LIVE' }],
      }),
      false
    );
  });

  it('ignores superseded returned parcel AWB in orderSteps when replacement is still unlabelled', () => {
    const steps = orderSteps({
      status: 'processing',
      payment_status: 'paid',
      shipping_status: 'unfulfilled',
      payment_driver: 'komerce',
      shipments: [
        { status: 'returned', is_superseded: true, awb: 'OLD-RET-1' },
        { status: 'pending', is_superseded: false, awb: null },
      ],
    });

    assert.equal(steps.find((s) => s.key === 'packed')?.done, false);
    assert.equal(steps.find((s) => s.key === 'shipped')?.done, false);

    const stage = orderStage({
      status: 'processing',
      payment_status: 'paid',
      shipping_status: 'shipped',
      payment_driver: 'komerce',
      shipments: [
        { status: 'returned', is_superseded: true, awb: 'OLD-RET-1' },
        { status: 'labeled', is_superseded: false, awb: 'NEW-RESHIP-1' },
      ],
    });
    assert.equal(stage.key, 'shipped');
    assert.match(stage.hint, /menunggu penjemputan/);
  });
});
