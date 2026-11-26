<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrderStatusBadge from '@/components/account/order-status-badge.vue';
import PaymentActionPanel from '@/components/shop/payment-action-panel.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { formatMoney } from '@/lib/format';
import { dashboard } from '@/routes';
import { orders as accountOrders } from '@/routes/account';
import * as shop from '@/routes/shop';
import type { PaymentAction } from '@/types/shop';

type OrderShipping = {
    price?: number | null;
    carrier?: { name?: string | null } | null;
};

type OrderItem = {
    id: number;
    name: string;
    sku: string | null;
    quantity: number;
    unit_price_amount: number;
    product?: {
        slug?: string;
        thumbnail?: string | null;
        images?: Array<{ url: string }>;
        product?: {
            slug?: string;
            thumbnail?: string | null;
            images?: Array<{ url: string }>;
        } | null;
    } | null;
};

type Address = {
    full_name?: string | null;
    first_name?: string | null;
    last_name?: string | null;
    street_address?: string | null;
    street_address_plus?: string | null;
    city: string;
    postal_code: string;
    country?: { name?: string } | null;
    country_name?: string | null;
};

type Order = {
    id: number;
    number: string;
    created_at: string;
    status: string;
    payment_status: string;
    shipping_status: string;
    price_amount: number;
    tax_amount: number | null;
    currency_code: string;
    items: OrderItem[];
    shipping_address: Address | null;
    shipping_option?: OrderShipping | null;
};

type TrackingEvent = {
    description: string;
    datetime: string | null;
    location: string | null;
};

type Shipment = {
    id: number;
    inventory_name: string | null;
    status: string;
    awb: string | null;
    tracking_number: string | null;
    carrier: string | null;
    service: string | null;
    carrier_logo?: string | null;
    cost: number;
    currency: string;
    can_track?: boolean;
    tracking_history: TrackingEvent[];
};

type PaymentProofItem = {
    id: number;
    status: string;
    original_name: string;
    sender_name?: string | null;
    sender_bank?: string | null;
    transfer_amount?: number | null;
    verification_notes?: string | null;
    created_at?: string | null;
    file_url: string;
};

type ReturnRequestItem = {
    id: number;
    status: string;
    reason: string;
    description?: string | null;
    items: Array<{ order_item_id: number; name?: string; qty: number }>;
    resolution_notes?: string | null;
    created_at?: string | null;
};

const props = defineProps<{
    order: Order;
    shipments: Shipment[];
    paymentAction?: PaymentAction | null;
    canRetryPayment?: boolean;
    canCancel?: boolean;
    canRequestReturn?: boolean;
    canUploadPaymentProof?: boolean;
    isCod?: boolean;
    paymentProofs?: PaymentProofItem[];
    returnRequests?: ReturnRequestItem[];
    refundedTotal?: number;
    summary?: {
        subtotal: number;
        discount: number;
        tax: number;
        tax_inclusive: boolean;
        shipping: number;
        total: number;
    };
}>();

const page = usePage();
const paymentError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.payment,
);
const retryingPayment = ref(false);
// Online payment step is pending (manual / COD orders show an offline info card instead).
const pendingPayment = computed<PaymentAction | null>(() =>
    props.paymentAction && props.paymentAction.kind !== 'none'
        ? props.paymentAction
        : null,
);
const offlinePayment = computed<PaymentAction | null>(() =>
    props.order.status !== 'cancelled' &&
    props.order.payment_status !== 'paid' &&
    props.paymentAction?.kind === 'none'
        ? props.paymentAction
        : null,
);
const offlinePaymentTitle = computed<string | null>(() => {
    const title = offlinePayment.value?.data?.title;

    return typeof title === 'string' && title.trim() !== ''
        ? title.trim()
        : null;
});
// Instructions / QR are confirmed by the provider: offer a status check.
const canCheckStatus = computed(
    () =>
        props.paymentAction?.kind === 'instructions' ||
        props.paymentAction?.kind === 'qr' ||
        props.paymentAction?.kind === 'redirect',
);
const checkingPayment = ref(false);
const shippingPrice = computed(() =>
    props.summary?.shipping ??
    (props.shipments.length > 0
        ? props.shipments.reduce((sum, s) => sum + s.cost, 0)
        : (props.order.shipping_option?.price ?? 0)),
);
const itemsTotal = computed(
    () =>
        props.summary?.subtotal ??
        props.order.price_amount -
            (props.order.tax_amount ?? 0) -
            shippingPrice.value,
);
const cancellingOrder = ref(false);
const cancelReason = ref('');
const showCancelForm = ref(false);
const cancelError = ref<string | null>(null);

const proofFile = ref<File | null>(null);
const proofSenderName = ref('');
const proofSenderBank = ref('');
const proofAmount = ref<number | ''>(props.order.price_amount);
const uploadingProof = ref(false);
const proofError = ref<string | null>(null);

const showReturnForm = ref(false);
const returnReason = ref('Barang rusak / cacat');
const returnDescription = ref('');
const returnSubmitting = ref(false);
const returnError = ref<string | null>(null);
const returnQuantities = ref<Record<number, number>>(
    Object.fromEntries(props.order.items.map((item) => [item.id, item.quantity])),
);

const flashError = computed(() => {
    const flash = page.props.flash as
        { error?: string; info?: string; success?: string } | undefined;

    return flash?.error ?? null;
});
const flashInfo = computed(() => {
    const flash = page.props.flash as
        { error?: string; info?: string; success?: string } | undefined;

    return flash?.info ?? null;
});
const flashSuccess = computed(() => {
    const flash = page.props.flash as
        { error?: string; info?: string; success?: string } | undefined;

    return flash?.success ?? null;
});

function thumbnail(item: OrderItem): string | null {
    return (
        item.product?.thumbnail ??
        item.product?.images?.[0]?.url ??
        item.product?.product?.thumbnail ??
        item.product?.product?.images?.[0]?.url ??
        null
    );
}

function itemProductSlug(item: OrderItem): string | null {
    return item.product?.slug ?? item.product?.product?.slug ?? null;
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatShipmentStatus(value: string): string {
    return value
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function shipmentCarrierService(shipment: Shipment): string | null {
    return (
        [shipment.carrier, shipment.service].filter(Boolean).join(' / ') || null
    );
}

const trackingShipmentId = ref<number | null>(null);
const trackingError = ref<{ id: number; message: string } | null>(null);
const confirmingReceived = ref(false);
const receivedError = ref<string | null>(null);

const canConfirmReceived = computed(
    () =>
        props.order.status !== 'cancelled' &&
        props.order.status !== 'completed' &&
        (props.order.payment_status === 'paid' || Boolean(props.isCod)) &&
        // Every parcel that still travels must have shipped (a cancelled one
        // is not waited for), and none is returned/failed.
        props.shipments.some((shipment) => shipment.status !== 'cancelled') &&
        props.shipments
            .filter((shipment) => shipment.status !== 'cancelled')
            .every(
                (shipment) =>
                    shipment.status !== 'returned' &&
                    shipment.status !== 'delivery_failed' &&
                    Boolean(shipment.awb || shipment.tracking_number),
            ),
);

function trackShipment(shipment: Shipment): void {
    trackingShipmentId.value = shipment.id;
    trackingError.value = null;
    router.post(
        `/account/orders/${props.order.id}/shipments/${shipment.id}/track`,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                trackingError.value = {
                    id: shipment.id,
                    message:
                        errors.tracking ??
                        'Pelacakan belum bisa diperbarui saat ini.',
                };
            },
            onFinish: () => {
                trackingShipmentId.value = null;
            },
        },
    );
}

function confirmReceived(): void {
    confirmingReceived.value = true;
    receivedError.value = null;
    router.post(
        `/account/orders/${props.order.id}/confirm-received`,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                receivedError.value =
                    errors.received ??
                    'Konfirmasi penerimaan belum bisa diproses saat ini.';
            },
            onFinish: () => {
                confirmingReceived.value = false;
            },
        },
    );
}

function retryPayment(): void {
    retryingPayment.value = true;
    router.post(
        `/account/orders/${props.order.id}/retry-payment`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                retryingPayment.value = false;
            },
        },
    );
}

function syncPayment(): void {
    checkingPayment.value = true;
    router.post(
        `/account/orders/${props.order.id}/sync-payment`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                checkingPayment.value = false;
            },
        },
    );
}

function cancelOrder(): void {
    cancellingOrder.value = true;
    cancelError.value = null;
    router.post(
        `/account/orders/${props.order.id}/cancel`,
        { reason: cancelReason.value || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                showCancelForm.value = false;
            },
            onError: (errors) => {
                cancelError.value = errors.cancel ?? 'Gagal membatalkan pesanan.';
            },
            onFinish: () => {
                cancellingOrder.value = false;
            },
        },
    );
}

function submitPaymentProof(): void {
    if (!proofFile.value) {
        proofError.value = 'Pilih file bukti transfer terlebih dahulu.';
        return;
    }
    uploadingProof.value = true;
    proofError.value = null;
    router.post(
        `/account/orders/${props.order.id}/payment-proof`,
        {
            proof: proofFile.value,
            sender_name: proofSenderName.value || null,
            sender_bank: proofSenderBank.value || null,
            transfer_amount: proofAmount.value || null,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                proofFile.value = null;
            },
            onError: (errors) => {
                proofError.value = errors.proof ?? 'Gagal mengunggah bukti transfer.';
            },
            onFinish: () => {
                uploadingProof.value = false;
            },
        },
    );
}

function submitReturn(): void {
    const items = props.order.items
        .map((item) => ({
            order_item_id: item.id,
            qty: Number(returnQuantities.value[item.id] ?? 0),
        }))
        .filter((row) => row.qty > 0);
    if (!items.length) {
        returnError.value = 'Pilih minimal satu item untuk diretur.';
        return;
    }
    returnSubmitting.value = true;
    returnError.value = null;
    router.post(
        `/account/orders/${props.order.id}/returns`,
        {
            reason: returnReason.value,
            description: returnDescription.value || null,
            items,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showReturnForm.value = false;
                returnDescription.value = '';
            },
            onError: (errors) => {
                returnError.value = errors.return ?? errors.items ?? 'Gagal mengajukan retur.';
            },
            onFinish: () => {
                returnSubmitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Pesanan ${order.number}`" />

    <Breadcrumb>
        <BreadcrumbList>
            <BreadcrumbItem>
                <BreadcrumbLink as-child>
                    <Link :href="dashboard.url()">Akun</Link>
                </BreadcrumbLink>
            </BreadcrumbItem>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
                <BreadcrumbLink as-child>
                    <Link :href="accountOrders.url()">Pesanan</Link>
                </BreadcrumbLink>
            </BreadcrumbItem>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
                <BreadcrumbPage>Detail pesanan</BreadcrumbPage>
            </BreadcrumbItem>
        </BreadcrumbList>
    </Breadcrumb>

    <div class="mt-6">
        <h1 class="text-lg font-semibold tracking-tight text-foreground">
            Detail pesanan
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Dipesan {{ formatDate(order.created_at) }}
            <span class="mx-2">|</span>
            Pesanan #{{ order.number }}
        </p>
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            <template v-if="order.status === 'cancelled'">
                <OrderStatusBadge :status="order.status" type="order" />
            </template>
            <template v-else>
                <OrderStatusBadge :status="order.payment_status" type="payment" />
                <OrderStatusBadge :status="order.shipping_status" type="shipping" />
            </template>
        </div>
        <Button variant="outline" size="sm" as-child>
            <a
                :href="`/account/orders/${order.id}/invoice`"
                target="_blank"
                rel="noopener"
            >
                Cetak Invoice
            </a>
        </Button>
    </div>

    <Alert
        v-if="flashError && !paymentError"
        variant="destructive"
        class="mt-5"
    >
        <AlertDescription>{{ flashError }}</AlertDescription>
    </Alert>

    <Alert
        v-if="flashSuccess && !(pendingPayment || canRetryPayment)"
        variant="success"
        class="mt-5"
    >
        <AlertDescription>{{ flashSuccess }}</AlertDescription>
    </Alert>

    <Card
        v-if="pendingPayment || canRetryPayment"
        class="mt-5 gap-0 overflow-hidden py-0 shadow-none"
    >
        <div
            class="border-b border-[var(--om-warning)]/20 bg-[var(--om-warning-soft)] px-4 py-3"
        >
            <h3 class="text-[13px] font-semibold text-[var(--om-warning)]">
                Menunggu pembayaran
            </h3>
            <p class="mt-0.5 text-[11px] text-[var(--om-warning)]/80">
                Bayar dulu supaya pesanan bisa diproses & dikirim.
            </p>
        </div>
        <CardContent class="flex flex-col gap-3 p-3.5">
            <Alert
                v-if="paymentError"
                variant="destructive"
                class="py-2 text-[12px]"
            >
                <AlertDescription>{{ paymentError }}</AlertDescription>
            </Alert>
            <Alert
                v-else-if="flashInfo"
                variant="info"
                class="py-2 text-[12px]"
            >
                <AlertDescription>{{ flashInfo }}</AlertDescription>
            </Alert>
            <Alert
                v-else-if="flashSuccess"
                variant="success"
                class="py-2 text-[12px]"
            >
                <AlertDescription>{{ flashSuccess }}</AlertDescription>
            </Alert>
            <PaymentActionPanel
                v-if="pendingPayment"
                :action="pendingPayment"
            />
            <p
                v-else-if="canRetryPayment"
                class="text-[13px] text-muted-foreground"
            >
                Instruksi pembayaran belum siap. Ketuk tombol di bawah untuk
                membuatnya.
            </p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Button
                    v-if="canCheckStatus"
                    type="button"
                    size="xl"
                    class="flex-1"
                    :disabled="checkingPayment"
                    @click="syncPayment"
                >
                    {{
                        checkingPayment
                            ? 'Mengecek…'
                            : 'Sudah bayar? Cek status'
                    }}
                </Button>
                <Button
                    v-if="canRetryPayment"
                    type="button"
                    variant="outline"
                    size="xl"
                    class="flex-1"
                    :disabled="retryingPayment"
                    @click="retryPayment"
                >
                    {{
                        retryingPayment
                            ? 'Memproses…'
                            : pendingPayment
                              ? 'Buat ulang pembayaran'
                              : 'Bayar sekarang'
                    }}
                </Button>
            </div>
        </CardContent>
    </Card>

    <Card
        v-else-if="offlinePayment"
        class="mt-5 gap-0 overflow-hidden py-0 shadow-none"
    >
        <div class="border-b border-border bg-muted/40 px-4 py-3">
            <h3 class="text-[13px] font-semibold text-foreground">
                Informasi pembayaran
            </h3>
            <p
                v-if="offlinePaymentTitle"
                class="mt-0.5 text-[12px] text-muted-foreground"
            >
                {{ offlinePaymentTitle }}
            </p>
        </div>
        <CardContent class="p-3.5">
            <PaymentActionPanel :action="offlinePayment" />
        </CardContent>
    </Card>

    <div v-if="canConfirmReceived || canCancel || canRequestReturn || order.payment_status === 'paid'" class="mt-4 flex flex-wrap items-center gap-3">
        <Button
            v-if="canConfirmReceived"
            type="button"
            size="xl"
            :disabled="confirmingReceived"
            @click="confirmReceived"
        >
            {{ confirmingReceived ? 'Memproses…' : 'Tandai sudah diterima' }}
        </Button>
        <Button
            v-if="order.payment_status === 'paid' || order.payment_status === 'partially_refunded'"
            as-child
            variant="outline"
            size="default"
        >
            <a :href="`/account/orders/${order.id}/invoice`" target="_blank">Cetak Invoice</a>
        </Button>
        <Button
            v-if="canCancel"
            type="button"
            variant="outline"
            size="default"
            @click="showCancelForm = !showCancelForm"
        >
            Batalkan pesanan
        </Button>
        <Button
            v-if="canRequestReturn"
            type="button"
            variant="outline"
            size="default"
            @click="showReturnForm = !showReturnForm"
        >
            Ajukan retur
        </Button>
    </div>
    <p v-if="receivedError" class="mt-2 text-sm text-destructive">
        {{ receivedError }}
    </p>

    <Card v-if="showCancelForm && canCancel" class="mt-4 gap-0 py-0 shadow-none">
        <CardContent class="space-y-3 p-4">
            <p class="text-sm font-medium text-foreground">Konfirmasi pembatalan pesanan</p>
            <input
                v-model="cancelReason"
                type="text"
                placeholder="Alasan pembatalan (opsional)"
                class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            />
            <p v-if="cancelError" class="text-sm text-destructive">{{ cancelError }}</p>
            <div class="flex gap-2">
                <Button type="button" variant="destructive" size="sm" :disabled="cancellingOrder" @click="cancelOrder">
                    {{ cancellingOrder ? 'Membatalkan…' : 'Ya, batalkan pesanan' }}
                </Button>
                <Button type="button" variant="outline" size="sm" @click="showCancelForm = false">Tutup</Button>
            </div>
        </CardContent>
    </Card>

    <Card v-if="canUploadPaymentProof || (paymentProofs?.length ?? 0) > 0" class="mt-5 gap-0 py-0 shadow-none">
        <CardHeader class="p-4 pb-2">
            <CardTitle class="text-sm">Bukti Transfer Bank Manual</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4 p-4 pt-2">
            <div v-if="paymentProofs?.length" class="space-y-2">
                <div
                    v-for="proof in paymentProofs"
                    :key="proof.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border px-3 py-2 text-xs"
                >
                    <div>
                        <p class="font-medium">{{ proof.original_name }} · Status: {{ proof.status }}</p>
                        <p v-if="proof.verification_notes" class="text-muted-foreground">{{ proof.verification_notes }}</p>
                    </div>
                    <a :href="proof.file_url" target="_blank" class="underline text-primary">Lihat bukti</a>
                </div>
            </div>
            <form v-if="canUploadPaymentProof" class="grid gap-3 sm:grid-cols-2" @submit.prevent="submitPaymentProof">
                <input v-model="proofSenderBank" type="text" placeholder="Bank pengirim (mis. BCA)" class="rounded-md border border-border bg-background px-3 py-2 text-sm" />
                <input v-model="proofSenderName" type="text" placeholder="Nama pemilik rekening" class="rounded-md border border-border bg-background px-3 py-2 text-sm" />
                <input v-model.number="proofAmount" type="number" placeholder="Nominal transfer" class="rounded-md border border-border bg-background px-3 py-2 text-sm" />
                <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="text-sm" @change="(e) => proofFile = (e.target as HTMLInputElement).files?.[0] ?? null" />
                <p v-if="proofError" class="text-sm text-destructive sm:col-span-2">{{ proofError }}</p>
                <div class="sm:col-span-2">
                    <Button type="submit" size="sm" :disabled="uploadingProof">
                        {{ uploadingProof ? 'Mengunggah…' : 'Unggah bukti transfer' }}
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>

    <Card v-if="showReturnForm || (returnRequests?.length ?? 0) > 0" class="mt-5 gap-0 py-0 shadow-none">
        <CardHeader class="p-4 pb-2">
            <CardTitle class="text-sm">Retur / Garansi (RMA)</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4 p-4 pt-2">
            <div v-if="returnRequests?.length" class="space-y-2">
                <div v-for="ret in returnRequests" :key="ret.id" class="rounded-md border border-border px-3 py-2 text-xs">
                    <p class="font-medium">RMA #{{ ret.id }} · {{ ret.reason }} ({{ ret.status }})</p>
                    <p v-if="ret.resolution_notes" class="text-muted-foreground">{{ ret.resolution_notes }}</p>
                </div>
            </div>
            <div v-if="showReturnForm && canRequestReturn" class="space-y-3 border-t border-border pt-3">
                <input v-model="returnReason" type="text" placeholder="Alasan retur" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" />
                <textarea v-model="returnDescription" rows="2" placeholder="Jelaskan kondisi barang" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" />
                <div v-for="item in order.items" :key="item.id" class="flex items-center justify-between gap-2 text-xs">
                    <span class="truncate">{{ item.name }} (Maks {{ item.quantity }})</span>
                    <input v-model.number="returnQuantities[item.id]" type="number" min="0" :max="item.quantity" class="w-20 rounded border border-border px-2 py-1 text-right" />
                </div>
                <p v-if="returnError" class="text-sm text-destructive">{{ returnError }}</p>
                <Button type="button" size="sm" :disabled="returnSubmitting" @click="submitReturn">
                    {{ returnSubmitting ? 'Mengirim…' : 'Kirim pengajuan retur' }}
                </Button>
            </div>
        </CardContent>
    </Card>

    <p
        v-if="order.status === 'completed'"
        class="mt-4 text-sm text-muted-foreground"
    >
        Pesanan selesai — terima kasih.
    </p>

    <Separator class="mt-8" />

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <Card v-if="order.shipping_address" class="gap-0 py-0 shadow-none">
            <CardHeader class="p-4 pb-0">
                <CardTitle class="text-sm"> Alamat pengiriman </CardTitle>
            </CardHeader>
            <CardContent class="p-4 pt-3">
                <address class="text-sm text-muted-foreground not-italic">
                    <p class="font-medium text-[var(--om-navy)]">
                        {{
                            order.shipping_address.full_name ??
                            `${order.shipping_address.first_name ?? ''} ${order.shipping_address.last_name ?? ''}`.trim()
                        }}
                    </p>
                    <p>{{ order.shipping_address.street_address }}</p>
                    <p v-if="order.shipping_address.street_address_plus">
                        {{ order.shipping_address.street_address_plus }}
                    </p>
                    <p>
                        {{ order.shipping_address.city }}
                        {{ order.shipping_address.postal_code }}
                    </p>
                    <p
                        v-if="
                            order.shipping_address.country?.name ||
                            order.shipping_address.country_name
                        "
                    >
                        {{
                            order.shipping_address.country?.name ??
                            order.shipping_address.country_name
                        }}
                    </p>
                </address>
            </CardContent>
        </Card>

        <Card class="gap-0 py-0 shadow-none lg:col-span-2">
            <CardHeader class="p-4 pb-0">
                <CardTitle class="text-sm"> Ringkasan pesanan </CardTitle>
            </CardHeader>
            <CardContent class="p-4 pt-3">
                <dl class="flex flex-col gap-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Produk</dt>
                        <dd class="text-[var(--om-navy)]">
                            {{ formatMoney(itemsTotal, order.currency_code) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">
                            Ongkir
                            <span
                                v-if="order.shipping_option?.carrier?.name"
                                class="text-muted-foreground/70"
                                >({{
                                    order.shipping_option.carrier.name
                                }})</span
                            >
                        </dt>
                        <dd class="text-[var(--om-navy)]">
                            {{
                                shippingPrice > 0
                                    ? formatMoney(
                                          shippingPrice,
                                          order.currency_code,
                                      )
                                    : 'Gratis'
                            }}
                        </dd>
                    </div>
                    <div
                        v-if="(summary?.discount ?? 0) > 0"
                        class="flex justify-between"
                    >
                        <dt class="text-muted-foreground">Diskon kupon</dt>
                        <dd class="text-emerald-600">
                            -{{ formatMoney(summary!.discount, order.currency_code) }}
                        </dd>
                    </div>
                    <div
                        v-if="(order.tax_amount ?? 0) > 0"
                        class="flex justify-between"
                    >
                        <dt class="text-muted-foreground">
                            {{ summary?.tax_inclusive ? 'Pajak (termasuk PPN)' : 'Pajak' }}
                        </dt>
                        <dd class="text-[var(--om-navy)]">
                            {{
                                formatMoney(
                                    order.tax_amount!,
                                    order.currency_code,
                                )
                            }}
                        </dd>
                    </div>
                    <div
                        v-if="(refundedTotal ?? 0) > 0"
                        class="flex justify-between"
                    >
                        <dt class="text-muted-foreground">Sudah direfund</dt>
                        <dd class="text-[var(--om-navy)]">
                            -{{ formatMoney(refundedTotal!, order.currency_code) }}
                        </dd>
                    </div>
                    <Separator />
                    <div class="flex justify-between pt-2">
                        <dt class="font-semibold text-[var(--om-navy)]">
                            Total
                        </dt>
                        <dd class="font-semibold text-[var(--om-navy)]">
                            {{
                                formatMoney(
                                    order.price_amount,
                                    order.currency_code,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>
    </div>

    <Separator v-if="shipments.length" class="mt-8" />

    <Card
        v-if="shipments.length"
        class="mt-8 gap-0 overflow-hidden py-0 shadow-none"
    >
        <CardHeader class="border-b border-border px-5 py-4">
            <CardTitle class="text-sm"> Pengiriman / Paket </CardTitle>
            <CardDescription>
                Lacak setiap paket dalam pesanan ini.
            </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-0 p-0">
            <div
                v-for="(shipment, index) in shipments"
                :key="shipment.id"
                class="border-b border-border px-5 py-4 last:border-b-0"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <p class="text-sm font-medium text-[var(--om-navy)]">
                            Paket {{ index + 1 }}
                            <span v-if="shipment.inventory_name">
                                · {{ shipment.inventory_name }}
                            </span>
                        </p>
                        <div class="mt-1 flex items-center gap-2">
                            <img
                                v-if="shipment.carrier_logo"
                                :src="shipment.carrier_logo"
                                :alt="shipment.carrier ?? undefined"
                                class="h-5 w-auto object-contain"
                            />
                            <p class="text-sm text-muted-foreground">
                                {{
                                    shipmentCarrierService(shipment) ??
                                    'Kurir menunggu'
                                }}
                            </p>
                        </div>
                    </div>
                    <p class="text-sm font-medium text-[var(--om-navy)]">
                        {{ formatShipmentStatus(shipment.status) }}
                    </p>
                </div>

                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">AWB</dt>
                        <dd class="mt-1 text-[var(--om-navy)]">
                            {{ shipment.awb ?? 'Label menunggu' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Nomor resi</dt>
                        <dd class="mt-1 text-[var(--om-navy)]">
                            {{
                                shipment.tracking_number ??
                                shipment.awb ??
                                'Label menunggu'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Biaya ongkir</dt>
                        <dd class="mt-1 text-[var(--om-navy)]">
                            {{ formatMoney(shipment.cost, shipment.currency) }}
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="
                        ((shipment.awb || shipment.tracking_number) && shipment.can_track !== false) ||
                        shipment.tracking_history.length
                    "
                    class="mt-4"
                >
                    <Button
                        v-if="(shipment.awb || shipment.tracking_number) && shipment.can_track !== false"
                        type="button"
                        :disabled="trackingShipmentId === shipment.id"
                        variant="outline"
                        size="default"
                        class="px-3 text-sm"
                        @click="trackShipment(shipment)"
                    >
                        {{
                            trackingShipmentId === shipment.id
                                ? 'Melacak…'
                                : 'Lacak paket'
                        }}
                    </Button>

                    <p
                        v-if="trackingError?.id === shipment.id"
                        class="mt-2 text-sm text-destructive"
                    >
                        {{ trackingError.message }}
                    </p>

                    <ol
                        v-if="shipment.tracking_history.length"
                        class="mt-4 flex flex-col gap-3 border-l border-border pl-4"
                    >
                        <li
                            v-for="(
                                event, eventIndex
                            ) in shipment.tracking_history"
                            :key="eventIndex"
                            class="relative"
                        >
                            <span
                                class="absolute top-1 -left-[21px] size-2 rounded-full bg-muted-foreground/50"
                            />
                            <p class="text-sm text-[var(--om-navy)]">
                                {{ event.description }}
                            </p>
                            <p
                                v-if="event.datetime || event.location"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                <span v-if="event.datetime">{{
                                    event.datetime
                                }}</span>
                                <span v-if="event.datetime && event.location">
                                    ·
                                </span>
                                <span v-if="event.location">{{
                                    event.location
                                }}</span>
                            </p>
                        </li>
                    </ol>
                </div>
            </div>
        </CardContent>
    </Card>

    <Separator class="mt-8" />

    <Card class="mt-8 gap-0 overflow-hidden py-0 shadow-none">
        <CardContent class="flex flex-col gap-0 p-0">
            <div
                v-for="item in order.items"
                :key="item.id"
                class="flex gap-4 border-b border-border px-5 py-4 last:border-b-0"
            >
                <div
                    class="size-24 shrink-0 overflow-hidden rounded-md bg-muted ring-1 ring-border"
                >
                    <img
                        v-if="thumbnail(item)"
                        :src="thumbnail(item)!"
                        :alt="item.name"
                        loading="lazy"
                        class="size-full object-cover object-center"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <Link
                        v-if="itemProductSlug(item)"
                        :href="shop.product.url({ product: itemProductSlug(item)! })"
                        class="line-clamp-2 text-sm font-medium text-[var(--om-navy)] hover:underline"
                    >
                        {{ item.name }}
                    </Link>
                    <p
                        v-else
                        class="line-clamp-2 text-sm font-medium text-[var(--om-navy)]"
                    >
                        {{ item.name }}
                    </p>
                    <p
                        v-if="item.sku"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        SKU: {{ item.sku }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Jml: {{ item.quantity }} ·
                        {{
                            formatMoney(
                                item.unit_price_amount,
                                order.currency_code,
                            )
                        }}
                    </p>
                </div>
                <p class="shrink-0 text-sm font-medium text-[var(--om-navy)]">
                    {{
                        formatMoney(
                            item.unit_price_amount * item.quantity,
                            order.currency_code,
                        )
                    }}
                </p>
            </div>
        </CardContent>
    </Card>
</template>
