<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, ChevronRight, Lock, ShoppingBag, Truck } from 'lucide-vue-next';
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue';
import type { Component } from 'vue';
import AuthTextField from '@/components/auth/auth-text-field.vue';
import AppPageHeader from '@/components/shop/app-page-header.vue';
import Container from '@/components/shop/container.vue';
import CouponField from '@/components/shop/coupon-field.vue';
import SelectableCard from '@/components/shop/selectable-card.vue';
import ShipmentRatePicker from '@/components/shop/shipment-rate-picker.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup } from '@/components/ui/radio-group';
import { Separator } from '@/components/ui/separator';
import { useShop } from '@/composables/useShop';
import { formatMoney } from '@/lib/format';
import { cart as cartRoute } from '@/routes/shop';
import * as checkout from '@/routes/shop/checkout';
import type {
    Address,
    AddressDestinationFields,
    Cart,
    CartContext,
    DeliveryOption,
    PaymentAction,
    ShippingCapabilities,
} from '@/types/shop';

// Embedded payment forms for paymentAction kind 'client_secret', by driver.
// A gateway that confirms in the page registers its form here.
const inPagePaymentForms: Record<string, Component> = {
    stripe: defineAsyncComponent(
        () => import('@/components/shop/stripe-payment-form.vue'),
    ),
};

type ClientSecretData = {
    client_secret: string;
    publishable_key?: string;
    return_url?: string;
};

type ShipmentPackage = {
    inventory_id: number;
    inventory_name: string;
    lines: Array<{
        purchasable_type: string;
        purchasable_id: number;
        qty: number;
    }>;
};

type ShippingAddressForm = {
    first_name: string;
    last_name: string;
    street_address: string;
    street_address_plus: string;
    postal_code: string;
    city: string;
    state: string;
    phone_number: string;
    destination_id: string;
    destination_label: string;
    pin_point: string;
};

type SavedCheckoutAddress = Address & AddressDestinationFields;

// Neutral destination search row (ResolvesDestinations): region keys are null
// when the shipping provider does not know them.
type DestinationResult = {
    id: string;
    label: string;
    postal_code: string | null;
    city: string | null;
    state: string | null;
    district?: string | null;
};

type RegionField = 'state' | 'city' | 'postal_code';

const props = defineProps<{
    cart: Cart;
    cartContext: CartContext;
    savedAddresses: SavedCheckoutAddress[];
    shippingAddress: ShippingAddressForm | null;
    deliveryOptions: DeliveryOption[];
    selectedDeliveryOption: string | number | null;
    allocation: ShipmentPackage[] | null;
    deliveryOptionsByShipment: Record<number | string, DeliveryOption[]>;
    selectedRatesByShipment: Record<number | string, string>;
    paymentOptions: Array<{
        id: number;
        title: string;
        slug?: string | null;
        driver: string;
        description?: string | null;
        logo?: string | null;
        channel_code?: string | null;
        payment_type?: string | null;
        min_amount?: number | null;
        max_amount?: number | null;
        confirm_in_page?: boolean;
    }>;
    selectedPaymentMethod: number | null;
    step: 1 | 2 | 3;
    paymentAction: PaymentAction | null;
    shippingCapabilities: ShippingCapabilities;
    shippingRatesHint?: string | null;
    couponCode?: string | null;
}>();

const { currency, taxLabel } = useShop();

const step = computed<1 | 2 | 3>(() => props.step);

const maxStep = computed<1 | 2 | 3>(() => {
    if (props.selectedDeliveryOption !== null) {
        return 3;
    }

    if (props.shippingAddress) {
        return 2;
    }

    return 1;
});

const selectedAddressId = ref<number | null>(null);

const selectedSavedAddressValue = computed<string>({
    get: () =>
        selectedAddressId.value != null ? String(selectedAddressId.value) : '',
    set: (value) => {
        if (!value) {
            clearAddress();

            return;
        }

        const address = props.savedAddresses.find(
            (item) => String(item.id) === value,
        );

        if (address) {
            selectAddress(address);
        }
    },
});

const addressForm = useForm<ShippingAddressForm>({
    first_name: props.shippingAddress?.first_name ?? '',
    last_name: props.shippingAddress?.last_name ?? '',
    street_address: props.shippingAddress?.street_address ?? '',
    street_address_plus: props.shippingAddress?.street_address_plus ?? '',
    postal_code: props.shippingAddress?.postal_code ?? '',
    city: props.shippingAddress?.city ?? '',
    state: props.shippingAddress?.state ?? '',
    phone_number: props.shippingAddress?.phone_number ?? '',
    destination_id: props.shippingAddress?.destination_id ?? '',
    destination_label: props.shippingAddress?.destination_label ?? '',
    pin_point: props.shippingAddress?.pin_point ?? '',
});

// A shipping driver that quotes by coordinates (shippingCapabilities
// .requiresCoordinates) gets the customer's own location when they share
// it; otherwise the driver resolves the pin from the address itself.
const locating = ref<boolean>(false);
const locationError = ref<string | null>(null);
const canUseLocation =
    typeof navigator !== 'undefined' && 'geolocation' in navigator;

function useCurrentLocation(): void {
    if (!canUseLocation) {
        locationError.value = 'Browser ini tidak bisa membagikan lokasi.';

        return;
    }

    locating.value = true;
    locationError.value = null;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            addressForm.pin_point = `${position.coords.latitude.toFixed(6)},${position.coords.longitude.toFixed(6)}`;
            locating.value = false;
        },
        () => {
            locationError.value =
                'Lokasi tidak bisa diambil. Alamat tetap bisa dipakai tanpa titik lokasi.';
            locating.value = false;
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
    );
}

function clearLocation(): void {
    addressForm.pin_point = '';
    locationError.value = null;
}

const destinationQuery = ref(props.shippingAddress?.destination_label ?? '');
const destinationResults = ref<DestinationResult[]>([]);
const destinationSearching = ref(false);
const destinationSearchError = ref<string | null>(null);
let destinationSearchTimer: ReturnType<typeof setTimeout> | null = null;

// Province / city / postal code come from the picked destination only when the
// configured shipping needs a destination id; otherwise they are typed freely
// (Shopper's own carriers and zone options need nothing more). A field the
// picked destination does not provide stays editable, so a provider whose
// search rows lack e.g. a postal code never leaves an empty read-only field.
const regionFromDestination = computed<boolean>(
    () => props.shippingCapabilities.requiresDestination,
);

const providedByDestination = reactive<Record<RegionField, boolean>>({
    state: Boolean(
        props.shippingAddress?.destination_id && props.shippingAddress?.state,
    ),
    city: Boolean(
        props.shippingAddress?.destination_id && props.shippingAddress?.city,
    ),
    postal_code: Boolean(
        props.shippingAddress?.destination_id &&
        props.shippingAddress?.postal_code,
    ),
});

function regionLocked(field: RegionField): boolean {
    if (!regionFromDestination.value) {
        return false;
    }

    // Before a destination is picked the region waits for it.
    return !addressForm.destination_id || providedByDestination[field];
}

function regionPlaceholder(field: RegionField, example: string): string {
    if (regionLocked(field)) {
        return 'Pilih dari kecamatan';
    }

    return example;
}

watch(destinationQuery, (value) => {
    if (destinationSearchTimer) {
        clearTimeout(destinationSearchTimer);
    }

    if (
        !props.shippingCapabilities.destinationSearch ||
        value.trim().length < 2
    ) {
        destinationResults.value = [];
        destinationSearchError.value = null;

        return;
    }

    // Keep selected label when it matches the selected id's label.
    if (addressForm.destination_id && value === addressForm.destination_label) {
        return;
    }

    destinationSearchTimer = setTimeout(() => {
        void searchDestinations(value.trim());
    }, 300);
});

async function searchDestinations(query: string): Promise<void> {
    destinationSearching.value = true;
    destinationSearchError.value = null;

    try {
        const response = await fetch(
            `/checkout/destinations?q=${encodeURIComponent(query)}&limit=10`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );

        const payload = (await response.json().catch(() => ({}))) as {
            data?: DestinationResult[];
            message?: string;
        };

        if (!response.ok) {
            destinationResults.value = [];
            destinationSearchError.value =
                typeof payload.message === 'string' &&
                payload.message.trim() !== ''
                    ? payload.message
                    : response.status === 429
                      ? 'Batas kuota pencarian destinasi harian sudah tercapai. Silakan coba lagi nanti.'
                      : 'Tidak dapat mencari tujuan saat ini.';

            return;
        }

        destinationResults.value = Array.isArray(payload.data)
            ? payload.data
            : [];
    } catch {
        destinationResults.value = [];
        destinationSearchError.value = 'Tidak dapat mencari tujuan saat ini.';
    } finally {
        destinationSearching.value = false;
    }
}

function rateSupportsCod(option: DeliveryOption | null | undefined): boolean {
    if (!option) {
        return true;
    }

    return (
        option.is_cod !== false &&
        option.kiriminaja_cod !== false &&
        option.available_for_cash_on_delivery !== false
    );
}

function rateCodStatus(option: DeliveryOption): boolean | null {
    if (
        option.is_cod === false ||
        option.kiriminaja_cod === false ||
        option.available_for_cash_on_delivery === false
    ) {
        return false;
    }

    if (
        option.is_cod === true ||
        option.kiriminaja_cod === true ||
        option.available_for_cash_on_delivery === true
    ) {
        return true;
    }

    return null;
}

function formatCourierTitle(option: DeliveryOption): string {
    const carrier =
        option.carrier_name ||
        (option.carrier_code ? option.carrier_code.toUpperCase() : '');

    if (!carrier) {
        return option.service_name;
    }

    if (option.service_name.toLowerCase().includes(carrier.toLowerCase())) {
        return option.service_name;
    }

    return `${carrier} - ${option.service_name}`;
}

function selectDestination(result: DestinationResult): void {
    addressForm.destination_id = String(result.id);
    addressForm.destination_label = result.label;
    destinationQuery.value = result.label;
    destinationResults.value = [];
    addressForm.clearErrors('destination_id', 'postal_code', 'city', 'state');

    // Always sync the region from the picked destination so typed placeholders
    // don't leave the form looking filled while Inertia still posts blanks.
    // Fields the destination does not provide stay editable.
    const region: Record<RegionField, string | null | undefined> = {
        state: result.state,
        city: result.city,
        postal_code: result.postal_code,
    };

    for (const field of Object.keys(region) as RegionField[]) {
        const value = (region[field] ?? '').trim();
        providedByDestination[field] = value !== '';

        if (value !== '') {
            addressForm[field] = value;
        } else if (regionFromDestination.value) {
            addressForm[field] = '';
        }
    }
}

function clearDestination(): void {
    addressForm.destination_id = '';
    addressForm.destination_label = '';
    destinationQuery.value = '';
    destinationResults.value = [];
    addressForm.state = '';
    addressForm.city = '';
    addressForm.postal_code = '';
    providedByDestination.state = false;
    providedByDestination.city = false;
    providedByDestination.postal_code = false;
}

const shippingForm = useForm<{ service_code: string }>({
    service_code:
        props.selectedDeliveryOption !== null
            ? String(props.selectedDeliveryOption)
            : '',
});

const selectedShippingServiceValue = computed<string>({
    get: () => shippingForm.service_code,
    set: (value) => {
        shippingForm.service_code = value;
    },
});

const isMultiPackage = computed<boolean>(
    () => (props.allocation?.length ?? 0) > 1,
);

const ratesByShipment = ref<Record<number | string, string>>({
    ...props.selectedRatesByShipment,
});

const multiShippingTotal = computed<number>(() => {
    let sum = 0;

    for (const pkg of props.allocation ?? []) {
        const code = ratesByShipment.value[pkg.inventory_id];

        if (!code) {
            continue;
        }

        const options = props.deliveryOptionsByShipment[pkg.inventory_id] ?? [];
        const opt = options.find(
            (o) => String(o.service_code) === String(code),
        );

        if (opt) {
            sum += opt.amount;
        }
    }

    return sum;
});

const multiShippingCurrency = computed<string>(() => {
    for (const pkg of props.allocation ?? []) {
        const code = ratesByShipment.value[pkg.inventory_id];

        if (!code) {
            continue;
        }

        const options = props.deliveryOptionsByShipment[pkg.inventory_id] ?? [];
        const opt = options.find(
            (o) => String(o.service_code) === String(code),
        );

        if (opt?.currency) {
            return opt.currency;
        }
    }

    return 'IDR';
});

const allPackagesSelected = computed<boolean>(() =>
    (props.allocation ?? []).every((pkg) =>
        Boolean(ratesByShipment.value[pkg.inventory_id]),
    ),
);

function submitMultiShipping(): void {
    router.post(
        checkout.shippingOption.url(),
        { rates: ratesByShipment.value },
        { preserveScroll: true },
    );
}

const paymentForm = useForm<{ payment_method_id: number | null }>({
    payment_method_id: props.selectedPaymentMethod ?? null,
});

// Server-side refusals that belong to no single field (sold out meanwhile,
// a package without a delivery option, payment setup) arrive as page errors.
const page = usePage();
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const checkoutErrors = computed(() =>
    [pageErrors.value.order, pageErrors.value.payment].filter(
        (message): message is string => !!message,
    ),
);

const selectedPaymentMethodValue = computed<string>({
    get: () =>
        paymentForm.payment_method_id === null
            ? ''
            : String(paymentForm.payment_method_id),
    set: (value) => {
        paymentForm.payment_method_id = value ? Number(value) : null;
    },
});

const selectedDelivery = computed<DeliveryOption | null>(
    () =>
        props.deliveryOptions.find(
            (o) =>
                props.selectedDeliveryOption !== null &&
                String(o.service_code) === String(props.selectedDeliveryOption),
        ) ?? null,
);

const currentPaymentMethod = computed(
    () =>
        props.paymentOptions.find(
            (m) => m.id === paymentForm.payment_method_id,
        ) ?? null,
);

// The method is paid in an embedded form before the order exists.
const isInPageSelected = computed<boolean>(() =>
    Boolean(currentPaymentMethod.value?.confirm_in_page),
);
// The prepared client-secret action for the selected method, if any.
const inPageAction = computed<{
    form: Component;
    data: ClientSecretData;
} | null>(() => {
    const action = props.paymentAction;
    const form = action ? inPagePaymentForms[action.driver] : undefined;

    if (
        !action ||
        action.kind !== 'client_secret' ||
        !form ||
        action.driver !== currentPaymentMethod.value?.driver ||
        typeof action.data.client_secret !== 'string'
    ) {
        return null;
    }

    return { form, data: action.data as ClientSecretData };
});
const total = computed<number>(() => {
    const sub = props.cartContext?.total ?? 0;
    const delivery = isMultiPackage.value
        ? multiShippingTotal.value
        : (selectedDelivery.value?.amount ?? 0);

    return sub + delivery;
});

type CheckoutPaymentOption = (typeof props.paymentOptions)[number];

const selectedRatesSupportCod = computed<boolean>(() => {
    if (isMultiPackage.value) {
        return (props.allocation ?? []).every((pkg) => {
            const code =
                ratesByShipment.value[pkg.inventory_id] ||
                props.selectedRatesByShipment[pkg.inventory_id];

            if (!code) {
                return true;
            }

            const opt = (
                props.deliveryOptionsByShipment[pkg.inventory_id] ?? []
            ).find((o) => String(o.service_code) === String(code));

            return rateSupportsCod(opt);
        });
    }

    return rateSupportsCod(selectedDelivery.value);
});

function isCodMethod(method: CheckoutPaymentOption): boolean {
    const slug = method.slug?.toLowerCase().trim() ?? '';
    const paymentType = method.payment_type?.toLowerCase().trim() ?? '';

    return slug === 'cod' || paymentType === 'cod';
}

function paymentLimitNote(
    method: CheckoutPaymentOption,
    amount: number,
    curr: string,
): string | null {
    if (method.min_amount && amount < method.min_amount) {
        return `Minimal ${formatMoney(method.min_amount, curr)} untuk metode ini.`;
    }

    if (method.max_amount && amount > method.max_amount) {
        return `Maksimal ${formatMoney(method.max_amount, curr)} untuk metode ini.`;
    }

    return null;
}

function paymentDisabledReason(method: CheckoutPaymentOption): string | null {
    const limitNote = paymentLimitNote(method, total.value, currency.value);

    if (limitNote) {
        return limitNote;
    }

    if (isCodMethod(method) && !selectedRatesSupportCod.value) {
        return 'Kurir yang dipilih tidak mendukung Cash on Delivery (COD).';
    }

    return null;
}

// Any driver other than Shopper's manual one takes the payment online.
const isOnlinePaymentSelected = computed<boolean>(
    () =>
        Boolean(currentPaymentMethod.value) &&
        (currentPaymentMethod.value?.driver ?? 'manual') !== 'manual',
);
const canPlaceOrder = computed<boolean>(
    () =>
        Boolean(currentPaymentMethod.value) &&
        !isInPageSelected.value &&
        paymentDisabledReason(currentPaymentMethod.value!) === null,
);
const preparingInPage = ref<boolean>(false);
const inPageMounted = ref<boolean>(false);

watch(
    () => isInPageSelected.value && Boolean(inPageAction.value),
    (active) => {
        if (active) {
            inPageMounted.value = true;
        }
    },
    { immediate: true },
);

watch(
    () => paymentForm.payment_method_id,
    (id) => {
        if (!id) {
            return;
        }

        const method = props.paymentOptions.find((m) => m.id === id) ?? null;

        if (!method || paymentDisabledReason(method) !== null) {
            return;
        }

        if (method.confirm_in_page && !inPageAction.value) {
            preparingInPage.value = true;
            router.post(
                checkout.preparePayment.url(),
                { payment_method_id: id },
                {
                    preserveScroll: true,
                    onFinish: () => (preparingInPage.value = false),
                },
            );
        }
    },
);

function selectAddress(address: SavedCheckoutAddress): void {
    selectedAddressId.value = address.id;
    addressForm.first_name = address.first_name ?? '';
    addressForm.last_name = address.last_name;
    addressForm.street_address = address.street_address;
    addressForm.street_address_plus = address.street_address_plus ?? '';
    addressForm.postal_code = address.postal_code;
    addressForm.city = address.city;
    addressForm.state = address.state ?? '';
    addressForm.phone_number = address.phone_number ?? '';
    addressForm.pin_point = address.pin_point ?? '';

    const destinationId = String(address.destination_id ?? '').trim();
    const destinationLabel = String(address.destination_label ?? '').trim();

    if (destinationId !== '' && props.shippingCapabilities.destinationSearch) {
        addressForm.destination_id = destinationId;
        addressForm.destination_label = destinationLabel;
        providedByDestination.state = Boolean(address.state);
        providedByDestination.city = Boolean(address.city);
        providedByDestination.postal_code = Boolean(address.postal_code);
        destinationQuery.value = destinationLabel || destinationId;
        destinationResults.value = [];
        addressForm.clearErrors('destination_id');
        // One tap: reuse street + district and jump to courier rates.
        submitAddress();

        return;
    }

    addressForm.destination_id = '';
    addressForm.destination_label = '';

    if (!props.shippingCapabilities.requiresDestination) {
        // City / postal code are enough for the configured shipping: one tap
        // reuses the saved address and jumps to courier rates.
        destinationQuery.value = '';
        destinationResults.value = [];
        submitAddress();

        return;
    }

    // Legacy saved addresses without district — prompt search.
    addressForm.destination_id = '';
    addressForm.destination_label = '';
    destinationQuery.value = [address.city, address.postal_code]
        .filter(Boolean)
        .join(' ');
    destinationResults.value = [];

    if (destinationQuery.value.trim().length >= 2) {
        void searchDestinations(destinationQuery.value.trim());
    }
}

function clearAddress(): void {
    selectedAddressId.value = null;
    addressForm.reset();
}

function goToStep(target: 1 | 2 | 3): void {
    if (target === step.value) {
        return;
    }

    if (target > maxStep.value) {
        return;
    }

    router.get(
        checkout.index.url(),
        { step: target },
        { preserveScroll: true, preserveState: false },
    );
}

function submitAddress(): void {
    if (
        props.shippingCapabilities.requiresDestination &&
        !String(addressForm.destination_id ?? '').trim()
    ) {
        addressForm.setError(
            'destination_id',
            'Pilih kecamatan dari daftar pencarian.',
        );

        return;
    }

    addressForm
        .transform((data) => ({
            ...data,
            destination_id: String(data.destination_id ?? '').trim(),
            destination_label: String(data.destination_label ?? '').trim(),
            postal_code: String(data.postal_code ?? '').trim(),
            city: String(data.city ?? '').trim(),
        }))
        .post(checkout.shippingAddress.url(), { preserveScroll: true });
}

function submitShipping(): void {
    shippingForm.post(checkout.shippingOption.url(), { preserveScroll: true });
}

function placeOrder(): void {
    paymentForm.post(checkout.placeOrder.url(), { preserveScroll: true });
}

function lineImage(line: Cart['lines'][number]): string | null {
    return (
        line.purchasable.thumbnail ?? line.purchasable.images?.[0]?.url ?? null
    );
}

function lineName(line: Cart['lines'][number]): string {
    return (line.purchasable as { name?: string })?.name ?? '';
}

const steps = [
    { n: 1, label: 'Alamat' },
    { n: 2, label: 'Ongkir' },
    { n: 3, label: 'Pembayaran' },
] as const;
</script>

<template>
    <Head title="Checkout" />

    <AppPageHeader
        class="lg:hidden"
        title="Checkout"
        :back-href="cartRoute.url()"
        max-width-class="max-w-7xl"
    />

    <Container class="py-8 sm:py-12">
        <h1
            class="hidden text-lg font-semibold tracking-tight text-foreground lg:block"
        >
            Checkout
        </h1>

        <nav class="mt-8 mb-10">
            <ol class="flex items-center gap-2">
                <li
                    v-for="(s, i) in steps"
                    :key="s.n"
                    class="flex items-center gap-2"
                >
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="s.n > maxStep"
                        class="h-auto gap-2 px-0 text-sm font-medium"
                        :class="[
                            step === s.n
                                ? 'text-primary hover:text-primary'
                                : maxStep > s.n
                                  ? 'text-green-600 hover:text-green-600'
                                  : 'text-muted-foreground',
                        ]"
                        @click="goToStep(s.n as 1 | 2 | 3)"
                    >
                        <Badge
                            :variant="
                                step === s.n
                                    ? 'default'
                                    : step > s.n
                                      ? 'success'
                                      : 'secondary'
                            "
                            class="size-7 shrink-0 justify-center rounded-full p-0 text-xs font-bold"
                        >
                            <Check
                                v-if="step > s.n"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <template v-else>{{ s.n }}</template>
                        </Badge>
                        {{ s.label }}
                    </Button>
                    <ChevronRight
                        v-if="i < steps.length - 1"
                        class="size-4 text-muted-foreground/50"
                        aria-hidden="true"
                    />
                </li>
            </ol>
        </nav>

        <div class="lg:grid lg:grid-cols-12 lg:gap-x-12">
            <div class="lg:col-span-7">
                <template v-if="step === 1">
                    <div v-if="savedAddresses.length" class="mb-8">
                        <h2
                            class="text-base font-semibold tracking-tight text-foreground"
                        >
                            Alamat tersimpan
                        </h2>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            {{
                                shippingCapabilities.destinationSearch
                                    ? 'Ketuk sekali untuk pakai lagi — kecamatan tersimpan ikut dipakai.'
                                    : 'Ketuk sekali untuk langsung memakai alamat ini.'
                            }}
                        </p>
                        <RadioGroup
                            v-model="selectedSavedAddressValue"
                            class="mt-4 grid gap-3 sm:grid-cols-2"
                        >
                            <SelectableCard
                                v-for="address in savedAddresses"
                                :key="address.id"
                                :id="`saved_address_${address.id}`"
                                :value="String(address.id)"
                            >
                                <p class="text-sm font-medium text-foreground">
                                    {{ address.first_name }}
                                    {{ address.last_name }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ address.street_address }},
                                    {{ address.city }}
                                    {{ address.postal_code }}
                                </p>
                                <p
                                    v-if="address.destination_label"
                                    class="mt-1 text-[11px] text-emerald-700"
                                >
                                    {{ address.destination_label }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ address.country?.name }}
                                </p>
                                <Badge
                                    v-if="address.shipping_default"
                                    variant="secondary"
                                    class="mt-2"
                                >
                                    Utama
                                </Badge>
                            </SelectableCard>
                        </RadioGroup>

                        <Button
                            v-if="selectedAddressId"
                            type="button"
                            variant="link"
                            size="sm"
                            class="mt-3 h-auto px-0 text-muted-foreground"
                            @click="clearAddress"
                        >
                            Pakai alamat lain
                        </Button>

                        <Separator class="my-6" />
                    </div>

                    <form
                        class="flex flex-col gap-5"
                        @submit.prevent="submitAddress"
                    >
                        <h2
                            class="text-base font-semibold tracking-tight text-foreground"
                        >
                            Alamat pengiriman
                        </h2>

                        <div class="grid grid-cols-2 gap-4">
                            <AuthTextField
                                id="first_name"
                                v-model="addressForm.first_name"
                                label="Nama depan"
                                placeholder="Nama depan"
                                :error="addressForm.errors.first_name"
                                required
                            />
                            <AuthTextField
                                id="last_name"
                                v-model="addressForm.last_name"
                                label="Nama belakang"
                                placeholder="Nama belakang"
                                :error="addressForm.errors.last_name"
                                required
                            />
                        </div>

                        <AuthTextField
                            id="street_address"
                            v-model="addressForm.street_address"
                            label="Alamat"
                            placeholder="Jl. contoh no. 1"
                            :error="addressForm.errors.street_address"
                            required
                        />

                        <AuthTextField
                            id="street_address_plus"
                            v-model="addressForm.street_address_plus"
                            label="Apartemen, blok, dll. (opsional)"
                            placeholder="Blok / unit (opsional)"
                        />

                        <AuthTextField
                            id="phone_number"
                            v-model="addressForm.phone_number"
                            label="Nomor telepon"
                            type="tel"
                            placeholder="08…"
                            required
                            :error="addressForm.errors.phone_number"
                        />

                        <div
                            v-if="shippingCapabilities.destinationSearch"
                            class="flex flex-col gap-1.5"
                        >
                            <Label for="destination_search">
                                Kecamatan pengiriman
                                <span
                                    v-if="
                                        shippingCapabilities.requiresDestination
                                    "
                                    class="text-red-500"
                                    >*</span
                                >
                            </Label>
                            <p
                                class="text-[11px] leading-snug text-muted-foreground"
                            >
                                Ketik nama kecamatan / kota, lalu pilih dari
                                daftar supaya ongkir akurat.
                            </p>
                            <div class="relative">
                                <Input
                                    id="destination_search"
                                    v-model="destinationQuery"
                                    type="search"
                                    autocomplete="off"
                                    class="h-[var(--om-control-height)] w-full pr-14 text-[13px] [&::-webkit-search-cancel-button]:hidden"
                                    placeholder="Contoh: Kedawung Cirebon"
                                    @focus="
                                        destinationQuery.trim().length >= 2 &&
                                        searchDestinations(
                                            destinationQuery.trim(),
                                        )
                                    "
                                />
                                <Button
                                    v-if="addressForm.destination_id"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="absolute inset-y-0 right-2 h-auto px-2 text-xs text-muted-foreground"
                                    @click="clearDestination"
                                >
                                    Ganti
                                </Button>
                                <Card
                                    v-if="destinationResults.length"
                                    class="absolute z-20 mt-1 max-h-56 w-full gap-0 overflow-auto rounded-md py-0 shadow-sm"
                                >
                                    <CardContent class="p-0">
                                        <Button
                                            v-for="result in destinationResults"
                                            :key="result.id"
                                            type="button"
                                            variant="ghost"
                                            class="h-auto w-full justify-start rounded-none px-3 py-2.5 text-left text-[13px] font-normal"
                                            @click="selectDestination(result)"
                                        >
                                            {{ result.label }}
                                        </Button>
                                    </CardContent>
                                </Card>
                            </div>
                            <p
                                v-if="destinationSearching"
                                class="text-xs text-muted-foreground"
                            >
                                Mencari…
                            </p>
                            <p
                                v-else-if="destinationSearchError"
                                class="text-xs text-red-600"
                            >
                                {{ destinationSearchError }}
                            </p>
                            <Alert
                                v-else-if="addressForm.destination_id"
                                variant="success"
                                class="py-2"
                            >
                                <AlertDescription
                                    class="text-[12px] text-current"
                                >
                                    ✓
                                    {{
                                        addressForm.destination_label ||
                                        destinationQuery
                                    }}
                                </AlertDescription>
                            </Alert>
                            <p
                                v-else-if="
                                    shippingCapabilities.requiresDestination
                                "
                                class="text-xs text-amber-700"
                            >
                                Wajib pilih dari daftar pencarian (jangan ketik
                                manual saja).
                            </p>
                            <p
                                v-if="addressForm.errors.destination_id"
                                class="text-xs text-red-600"
                            >
                                {{ addressForm.errors.destination_id }}
                            </p>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <AuthTextField
                                id="state"
                                v-model="addressForm.state"
                                label="Provinsi"
                                :placeholder="
                                    regionPlaceholder(
                                        'state',
                                        'Contoh: Jawa Barat',
                                    )
                                "
                                :error="addressForm.errors.state"
                                :readonly="regionLocked('state')"
                                required
                            />
                            <AuthTextField
                                id="city"
                                v-model="addressForm.city"
                                label="Kota"
                                :placeholder="
                                    regionPlaceholder('city', 'Contoh: Cirebon')
                                "
                                :error="addressForm.errors.city"
                                :readonly="regionLocked('city')"
                                autocomplete="address-level2"
                                required
                            />
                            <AuthTextField
                                id="postal_code"
                                v-model="addressForm.postal_code"
                                label="Kode pos"
                                :placeholder="
                                    regionPlaceholder(
                                        'postal_code',
                                        'Contoh: 45153',
                                    )
                                "
                                :error="addressForm.errors.postal_code"
                                :readonly="regionLocked('postal_code')"
                                inputmode="numeric"
                                autocomplete="postal-code"
                                required
                            />
                        </div>

                        <div
                            v-if="shippingCapabilities.requiresCoordinates"
                            class="flex flex-col gap-1.5"
                        >
                            <Label>Titik lokasi (opsional)</Label>
                            <p
                                class="text-[11px] leading-snug text-muted-foreground"
                            >
                                Bagikan lokasi Anda saat ini agar kurir lebih
                                mudah menemukan alamat.
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="locating"
                                    @click="useCurrentLocation"
                                >
                                    {{
                                        locating
                                            ? 'Mengambil lokasi…'
                                            : addressForm.pin_point
                                              ? 'Perbarui lokasi'
                                              : 'Gunakan lokasi saya'
                                    }}
                                </Button>
                                <template v-if="addressForm.pin_point">
                                    <span class="text-xs text-muted-foreground">
                                        ✓ {{ addressForm.pin_point }}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="h-auto px-2 text-xs text-muted-foreground"
                                        @click="clearLocation"
                                    >
                                        Hapus
                                    </Button>
                                </template>
                            </div>
                            <p
                                v-if="locationError"
                                class="text-xs text-amber-700"
                            >
                                {{ locationError }}
                            </p>
                            <p
                                v-if="addressForm.errors.pin_point"
                                class="text-xs text-red-600"
                            >
                                {{ addressForm.errors.pin_point }}
                            </p>
                        </div>

                        <div class="flex">
                            <Button
                                type="submit"
                                size="xl"
                                :disabled="
                                    addressForm.processing ||
                                    (shippingCapabilities.requiresDestination &&
                                        !addressForm.destination_id)
                                "
                            >
                                Lanjut ke pengiriman
                            </Button>
                        </div>
                    </form>
                </template>

                <template v-else-if="step === 2">
                    <template v-if="isMultiPackage">
                        <div class="flex flex-col gap-5">
                            <h2
                                class="text-base font-semibold tracking-tight text-foreground"
                            >
                                Metode pengiriman
                            </h2>

                            <Alert v-if="shippingRatesHint" variant="warning">
                                <AlertDescription class="text-sm text-current">
                                    {{ shippingRatesHint }}
                                </AlertDescription>
                            </Alert>

                            <ShipmentRatePicker
                                v-model="ratesByShipment"
                                :packages="allocation!"
                                :delivery-options-by-shipment="
                                    deliveryOptionsByShipment
                                "
                                :empty-hint="shippingRatesHint"
                            />

                            <div class="flex">
                                <Button
                                    type="button"
                                    size="xl"
                                    :disabled="!allPackagesSelected"
                                    @click="submitMultiShipping"
                                >
                                    Lanjut ke pembayaran
                                </Button>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div v-if="!deliveryOptions.length">
                            <Alert variant="warning">
                                <ShoppingBag
                                    class="size-5"
                                    aria-hidden="true"
                                />
                                <AlertDescription class="text-sm text-current">
                                    {{
                                        shippingRatesHint ||
                                        'Tidak ada opsi pengiriman untuk alamatmu.'
                                    }}
                                </AlertDescription>
                            </Alert>
                            <Button
                                type="button"
                                variant="link"
                                size="sm"
                                class="mt-4 h-auto px-0 text-muted-foreground"
                                @click="goToStep(1)"
                            >
                                ← Kembali ke alamat
                            </Button>
                        </div>

                        <form
                            v-else
                            class="flex flex-col gap-5"
                            @submit.prevent="submitShipping"
                        >
                            <h2
                                class="text-base font-semibold tracking-tight text-foreground"
                            >
                                Metode pengiriman
                            </h2>
                            <p
                                v-if="shippingForm.errors.service_code"
                                class="text-xs text-red-600"
                            >
                                {{ shippingForm.errors.service_code }}
                            </p>
                            <p
                                v-if="pageErrors.rates"
                                class="text-xs text-red-600"
                            >
                                {{ pageErrors.rates }}
                            </p>

                            <RadioGroup
                                v-model="selectedShippingServiceValue"
                                class="flex flex-col gap-2"
                            >
                                <label
                                    v-for="option in deliveryOptions"
                                    :key="option.service_code"
                                    class="flex cursor-pointer items-center justify-between rounded-md border p-3 hover:bg-muted/50"
                                    :class="
                                        selectedShippingServiceValue ===
                                        String(option.service_code)
                                            ? 'border-primary bg-primary/5'
                                            : 'border-border'
                                    "
                                >
                                    <div class="flex items-center gap-3">
                                        <input
                                            type="radio"
                                            class="sr-only"
                                            v-model="
                                                selectedShippingServiceValue
                                            "
                                            :value="String(option.service_code)"
                                        />
                                        <div
                                            class="flex h-10 w-16 shrink-0 items-center justify-center rounded-md border border-border/60 bg-white p-1.5 shadow-2xs"
                                        >
                                            <img
                                                v-if="option.carrier_logo"
                                                :src="option.carrier_logo"
                                                :alt="option.carrier_name ?? ''"
                                                class="max-h-full max-w-full object-contain"
                                                loading="lazy"
                                            />
                                            <Truck
                                                v-else
                                                class="size-5 text-muted-foreground"
                                            />
                                        </div>
                                        <div class="flex flex-col">
                                            <div
                                                class="flex flex-wrap items-center gap-1.5"
                                            >
                                                <span
                                                    class="text-sm font-medium text-foreground"
                                                    >{{
                                                        formatCourierTitle(
                                                            option,
                                                        )
                                                    }}</span
                                                >
                                                <Badge
                                                    v-if="
                                                        rateCodStatus(
                                                            option,
                                                        ) === true
                                                    "
                                                    variant="secondary"
                                                    class="text-[10px]"
                                                >
                                                    Bisa COD
                                                </Badge>
                                                <Badge
                                                    v-else-if="
                                                        rateCodStatus(
                                                            option,
                                                        ) === false
                                                    "
                                                    variant="outline"
                                                    class="text-[10px] text-muted-foreground"
                                                >
                                                    Tidak bisa COD
                                                </Badge>
                                            </div>
                                            <span
                                                v-if="option.description"
                                                class="text-xs font-normal text-muted-foreground"
                                                >{{ option.description }}</span
                                            >
                                            <span
                                                v-if="option.estimated_days"
                                                class="text-[11px] text-muted-foreground/80"
                                                >Estimasi:
                                                {{ option.estimated_days }}
                                                hari</span
                                            >
                                        </div>
                                    </div>
                                    <span class="text-sm font-semibold">{{
                                        formatMoney(
                                            option.amount,
                                            option.currency,
                                        )
                                    }}</span>
                                </label>
                            </RadioGroup>

                            <div class="flex">
                                <Button
                                    type="submit"
                                    size="xl"
                                    :disabled="
                                        !shippingForm.service_code ||
                                        shippingForm.processing
                                    "
                                >
                                    Lanjut ke pembayaran
                                </Button>
                            </div>
                        </form>
                    </template>
                </template>

                <template v-else>
                    <div class="flex flex-col gap-5">
                        <div>
                            <h2
                                class="text-base font-semibold tracking-tight text-foreground"
                            >
                                Metode pembayaran
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Semua transaksi aman dan terenkripsi.
                            </p>
                        </div>

                        <p
                            v-if="paymentForm.errors.payment_method_id"
                            class="text-xs text-red-600"
                        >
                            {{ paymentForm.errors.payment_method_id }}
                        </p>

                        <Alert
                            v-for="message in checkoutErrors"
                            :key="message"
                            variant="destructive"
                        >
                            <AlertDescription>{{ message }}</AlertDescription>
                        </Alert>

                        <p
                            v-if="!paymentOptions.length"
                            class="text-sm text-muted-foreground"
                        >
                            Tidak ada metode pembayaran untuk wilayahmu.
                        </p>

                        <template v-else>
                            <RadioGroup
                                v-model="selectedPaymentMethodValue"
                                class="flex flex-col gap-1"
                            >
                                <SelectableCard
                                    v-for="method in paymentOptions"
                                    :key="method.id"
                                    :id="`payment_method_${method.id}`"
                                    :value="String(method.id)"
                                    :disabled="
                                        Boolean(paymentDisabledReason(method))
                                    "
                                    class="items-center px-3 py-3"
                                >
                                    <div
                                        class="flex items-center justify-between gap-6"
                                    >
                                        <span
                                            class="text-sm font-medium text-foreground"
                                            >{{ method.title }}</span
                                        >
                                        <img
                                            v-if="method.logo"
                                            :src="method.logo!"
                                            :alt="method.title"
                                            class="h-5 w-auto object-cover"
                                        />
                                    </div>
                                    <p
                                        v-if="paymentDisabledReason(method)"
                                        class="mt-1 text-xs text-destructive"
                                    >
                                        {{ paymentDisabledReason(method) }}
                                    </p>
                                </SelectableCard>
                            </RadioGroup>

                            <div
                                v-if="isInPageSelected && inPageAction"
                                class="flex flex-col gap-4 pt-2"
                            >
                                <div class="flex items-center gap-3">
                                    <h3
                                        class="text-xs font-medium tracking-wider text-muted-foreground uppercase"
                                    >
                                        Detail kartu
                                    </h3>
                                    <Separator class="flex-1" />
                                </div>

                                <component
                                    :is="inPageAction.form"
                                    v-if="inPageMounted"
                                    :key="inPageAction.data.client_secret"
                                    :client-secret="
                                        inPageAction.data.client_secret
                                    "
                                    :publishable-key="
                                        inPageAction.data.publishable_key
                                    "
                                    :return-url="inPageAction.data.return_url"
                                    :total="total"
                                />
                            </div>

                            <template v-if="canPlaceOrder">
                                <div class="flex flex-col gap-3 pt-2">
                                    <Separator />
                                    <div
                                        class="flex items-center justify-between gap-4"
                                    >
                                        <div class="flex flex-col">
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >Total {{ taxLabel }}</span
                                            >
                                            <span
                                                class="text-lg font-semibold text-foreground"
                                                >{{
                                                    formatMoney(total, currency)
                                                }}</span
                                            >
                                        </div>
                                        <Button
                                            type="button"
                                            size="xl"
                                            :disabled="paymentForm.processing"
                                            @click="placeOrder"
                                        >
                                            {{
                                                paymentForm.processing
                                                    ? 'Memproses…'
                                                    : isOnlinePaymentSelected
                                                      ? 'Bayar sekarang'
                                                      : 'Buat pesanan'
                                            }}
                                        </Button>
                                    </div>
                                    <p
                                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                                    >
                                        <Lock
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        Aman &amp; terenkripsi
                                    </p>
                                </div>
                            </template>

                            <div
                                v-if="
                                    isInPageSelected &&
                                    !inPageAction &&
                                    preparingInPage
                                "
                                class="flex items-center gap-2 pt-3 text-sm text-muted-foreground"
                            >
                                <span
                                    class="inline-block size-4 animate-spin rounded-full border-2 border-border border-t-foreground"
                                />
                                Menyiapkan formulir pembayaran…
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="mt-8 lg:col-span-5 lg:mt-0">
                <Card
                    class="gap-0 rounded-md border-border bg-card py-0 text-card-foreground shadow-none lg:sticky lg:top-6"
                >
                    <CardHeader class="gap-1 p-6 pb-0">
                        <CardTitle
                            class="text-base font-semibold tracking-tight"
                        >
                            Ringkasan pesanan
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="p-6">
                        <ul
                            v-if="cart"
                            role="list"
                            class="mt-4 divide-y divide-border"
                        >
                            <li
                                v-for="line in cart.lines"
                                :key="line.id"
                                class="flex gap-3 py-3"
                            >
                                <div
                                    class="size-14 shrink-0 overflow-hidden rounded-md bg-muted"
                                >
                                    <img
                                        v-if="lineImage(line)"
                                        :src="lineImage(line)!"
                                        :alt="lineName(line)"
                                        class="size-full object-cover"
                                    />
                                </div>
                                <div class="flex flex-1 justify-between">
                                    <div>
                                        <p
                                            class="text-sm font-medium text-foreground"
                                        >
                                            {{ lineName(line) }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Jml: {{ line.quantity }}
                                        </p>
                                    </div>
                                    <p
                                        class="text-sm font-medium text-foreground"
                                    >
                                        {{
                                            formatMoney(
                                                line.unit_price_amount *
                                                    line.quantity,
                                                currency,
                                            )
                                        }}
                                    </p>
                                </div>
                            </li>
                        </ul>

                        <Separator class="mt-4" />

                        <div
                            class="mt-4 flex flex-col gap-3 text-sm text-muted-foreground"
                        >
                            <div class="border-b border-border pb-3">
                                <CouponField
                                    :coupon-code="couponCode"
                                    :discount-total="cartContext?.discountTotal"
                                />
                            </div>

                            <dl class="flex flex-col gap-3">
                                <div
                                    class="flex items-center justify-between border-b border-border pb-3"
                                >
                                    <dt>Pajak</dt>
                                    <dd class="text-base text-foreground">
                                        {{
                                            formatMoney(
                                                cartContext?.taxTotal ?? 0,
                                                currency,
                                            )
                                        }}
                                    </dd>
                                </div>

                                <div
                                    class="flex items-center justify-between border-b border-border pb-3"
                                >
                                    <dt>Ongkir</dt>
                                    <dd class="text-base text-foreground">
                                        <template
                                            v-if="
                                                isMultiPackage &&
                                                allPackagesSelected
                                            "
                                            >{{
                                                multiShippingTotal > 0
                                                    ? formatMoney(
                                                          multiShippingTotal,
                                                          multiShippingCurrency,
                                                      )
                                                    : 'Gratis'
                                            }}</template
                                        >
                                        <template
                                            v-else-if="selectedDelivery"
                                            >{{
                                                selectedDelivery.amount > 0
                                                    ? formatMoney(
                                                          selectedDelivery.amount,
                                                          selectedDelivery.currency,
                                                      )
                                                    : 'Gratis'
                                            }}</template
                                        >
                                        <template v-else
                                            >Dihitung di langkah
                                            berikutnya</template
                                        >
                                    </dd>
                                </div>

                                <div
                                    v-if="
                                        cartContext &&
                                        cartContext.discountTotal > 0
                                    "
                                    class="flex items-center justify-between border-b border-border pb-3"
                                >
                                    <dt>Diskon</dt>
                                    <dd class="text-emerald-600">
                                        −{{
                                            formatMoney(
                                                cartContext.discountTotal,
                                                currency,
                                            )
                                        }}
                                    </dd>
                                </div>

                                <div
                                    class="flex items-center justify-between pt-1"
                                >
                                    <dt
                                        class="text-base font-semibold text-foreground"
                                    >
                                        Total {{ taxLabel }}
                                    </dt>
                                    <dd
                                        class="text-base font-semibold text-foreground"
                                    >
                                        {{ formatMoney(total, currency) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </Container>
</template>
