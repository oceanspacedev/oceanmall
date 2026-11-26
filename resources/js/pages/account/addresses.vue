<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    CreditCard,
    MapPin,
    MoreHorizontal,
    Truck,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import AddressController from '@/actions/App/Http/Controllers/Account/AddressController';
import AuthSelectField from '@/components/auth/auth-select-field.vue';
import AuthSubmitButton from '@/components/auth/auth-submit-button.vue';
import AuthTextField from '@/components/auth/auth-text-field.vue';
import EmptyState from '@/components/shop/empty-state.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AddressType } from '@/types/shop';
import type {
    Address,
    AddressDestinationFields,
    ShippingCapabilities,
} from '@/types/shop';

type CountryOption = { id: number; name: string; cca2: string };

type DestinationOption = {
    destination_id: string | number;
    label: string;
    province_name?: string | null;
    city_name?: string | null;
    zip_code?: string | null;
};

type AddressForm = {
    first_name: string;
    last_name: string;
    street_address: string;
    street_address_plus: string;
    postal_code: string;
    city: string;
    state: string;
    phone_number: string;
    country_id: number | null;
    type: AddressType;
    destination_id: string;
    destination_label: string;
    pin_point: string;
};

const props = defineProps<{
    addresses: Array<Address & { metadata?: AddressDestinationFields | string | null }>;
    countries: CountryOption[];
    shippingCapabilities?: ShippingCapabilities;
}>();

const editing = ref<Address | null>(null);
const open = ref<boolean>(false);

const defaults: AddressForm = {
    first_name: '',
    last_name: '',
    street_address: '',
    street_address_plus: '',
    postal_code: '',
    city: '',
    state: '',
    phone_number: '',
    country_id: null,
    type: AddressType.SHIPPING,
    destination_id: '',
    destination_label: '',
    pin_point: '',
};

const form = useForm<AddressForm>({ ...defaults });

const destinationQuery = ref('');
const destinationResults = ref<DestinationOption[]>([]);
const destinationSearching = ref(false);
let destinationTimer: ReturnType<typeof setTimeout> | null = null;

watch(destinationQuery, (value) => {
    if (destinationTimer) {
        clearTimeout(destinationTimer);
    }

    if (!props.shippingCapabilities?.destinationSearch || value.trim().length < 2) {
        destinationResults.value = [];

        return;
    }

    if (form.destination_id && value === form.destination_label) {
        return;
    }

    destinationTimer = setTimeout(async () => {
        destinationSearching.value = true;
        try {
            const res = await fetch(`/checkout/destinations?q=${encodeURIComponent(value.trim())}&limit=8`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const json = (await res.json()) as { data?: DestinationOption[] };
            destinationResults.value = Array.isArray(json.data) ? json.data : [];
        } catch {
            destinationResults.value = [];
        } finally {
            destinationSearching.value = false;
        }
    }, 300);
});

function pickDestination(opt: DestinationOption): void {
    form.destination_id = String(opt.destination_id);
    form.destination_label = opt.label;
    destinationQuery.value = opt.label;
    if (opt.city_name) form.city = opt.city_name;
    if (opt.province_name) form.state = opt.province_name;
    if (opt.zip_code) form.postal_code = opt.zip_code;
    destinationResults.value = [];
}

function parseMeta(raw: unknown): AddressDestinationFields {
    if (typeof raw === 'string' && raw.trim() !== '') {
        try {
            return JSON.parse(raw) as AddressDestinationFields;
        } catch {
            return {};
        }
    }

    return (raw && typeof raw === 'object' ? raw : {}) as AddressDestinationFields;
}

const countryOptions = computed(() =>
    props.countries.map((country) => ({
        value: String(country.id),
        label: country.name,
    })),
);

const countryId = computed({
    get: () => form.country_id?.toString() ?? '',
    set: (value: string) => {
        form.country_id = value ? Number(value) : null;
    },
});

const canSaveAddress = computed(
    () =>
        form.first_name.trim().length > 0 &&
        form.last_name.trim().length > 0 &&
        form.street_address.trim().length > 0 &&
        form.city.trim().length > 0 &&
        form.postal_code.trim().length > 0 &&
        (form.type !== AddressType.SHIPPING || form.phone_number.trim().length > 0) &&
        form.country_id !== null &&
        !form.processing,
);

function startCreate(): void {
    editing.value = null;
    form.reset();
    Object.assign(form, defaults);
    if (props.countries.length === 1) {
        form.country_id = props.countries[0].id;
    }
    destinationQuery.value = '';
    destinationResults.value = [];
    open.value = true;
}

function startEdit(address: Address & { metadata?: AddressDestinationFields | string | null }): void {
    editing.value = address;
    const meta = parseMeta(address.metadata);
    form.first_name = address.first_name ?? '';
    form.last_name = address.last_name;
    form.street_address = address.street_address;
    form.street_address_plus = address.street_address_plus ?? '';
    form.postal_code = address.postal_code;
    form.city = address.city;
    form.state = address.state ?? '';
    form.phone_number = address.phone_number ?? '';
    form.country_id = address.country_id;
    form.type = address.type;
    form.destination_id = meta.destination_id ? String(meta.destination_id) : '';
    form.destination_label = meta.destination_label ?? '';
    form.pin_point = meta.pin_point ?? '';
    destinationQuery.value = form.destination_label;
    destinationResults.value = [];
    open.value = true;
}

function submit(): void {
    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            editing.value = null;
            form.reset();
        },
    };

    if (editing.value) {
        form.patch(AddressController.update.url(editing.value.id), opts);
    } else {
        form.post(AddressController.store.url(), opts);
    }
}

function destroy(address: Address): void {
    if (!window.confirm('Yakin ingin menghapus alamat ini?')) {
return;
}

    router.delete(AddressController.destroy.url(address.id), {
        preserveScroll: true,
    });
}

function setDefaultShipping(address: Address): void {
    router.patch(
        AddressController.setDefaultShipping.url(address.id),
        {},
        { preserveScroll: true },
    );
}

function setDefaultBilling(address: Address): void {
    router.patch(
        AddressController.setDefaultBilling.url(address.id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Alamat" />

    <div class="flex items-center justify-between gap-3">
        <p class="text-sm text-muted-foreground">
            Alamat pengiriman & penagihan
        </p>
        <Button
            type="button"
            variant="link"
            class="om-action-primary h-auto p-0"
            @click="startCreate"
        >
            Tambah
        </Button>
    </div>

    <div class="mt-4 flex flex-col gap-3">
        <div v-if="addresses.length" class="flex flex-col gap-3">
            <Card
                v-for="address in addresses"
                :key="address.id"
                class="gap-0 overflow-hidden py-0 shadow-none"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-border bg-muted/30 px-3.5 py-2.5"
                >
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ address.first_name }} {{ address.last_name }}
                    </h3>
                    <Badge
                        v-if="address.type === AddressType.BILLING"
                        variant="secondary"
                        class="text-[11px]"
                    >
                        Penagihan
                    </Badge>
                </div>

                <CardContent class="p-3.5 pt-3">
                    <address
                        class="text-sm leading-5 text-muted-foreground not-italic"
                    >
                        <span class="block">
                            {{ address.street_address
                            }}<span v-if="address.street_address_plus"
                                >, {{ address.street_address_plus }}</span
                            >
                        </span>
                        <span class="block"
                            >{{ address.postal_code }}, {{ address.city }}</span
                        >
                        <span v-if="address.country" class="block">{{
                            address.country.name
                        }}</span>
                    </address>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <Badge
                            v-if="address.shipping_default"
                            variant="secondary"
                            class="text-[11px]"
                        >
                            <Check aria-hidden="true" />
                            Default kirim
                        </Badge>
                        <Badge
                            v-if="address.billing_default"
                            variant="secondary"
                            class="text-[11px]"
                        >
                            <Check aria-hidden="true" />
                            Default tagih
                        </Badge>
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="px-3 text-[12px]"
                            @click="startEdit(address)"
                        >
                            Ubah
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            class="px-3 text-[12px] text-destructive hover:bg-destructive/10 hover:text-destructive"
                            @click="destroy(address)"
                        >
                            Hapus
                        </Button>
                        <DropdownMenu
                            v-if="
                                !address.shipping_default ||
                                !address.billing_default
                            "
                        >
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="size-9"
                                    aria-label="Lainnya"
                                >
                                    <MoreHorizontal aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    v-if="!address.shipping_default"
                                    @click="setDefaultShipping(address)"
                                >
                                    <Truck aria-hidden="true" />
                                    Jadikan default kirim
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="!address.billing_default"
                                    @click="setDefaultBilling(address)"
                                >
                                    <CreditCard aria-hidden="true" />
                                    Jadikan default tagih
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </CardContent>
            </Card>
        </div>

        <EmptyState
            v-else
            title="Belum ada alamat tersimpan"
            description="Tambahkan alamat pengiriman atau penagihan untuk checkout."
            :icon="MapPin"
            class="py-8"
        />
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogTitle>
                {{ editing ? 'Ubah alamat' : 'Tambah alamat' }}
            </DialogTitle>
            <form class="flex flex-col gap-3.5" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-2.5">
                    <AuthTextField
                        id="first_name"
                        v-model="form.first_name"
                        label="Nama depan"
                        required
                        placeholder="Nama depan *"
                        :error="form.errors.first_name"
                    />
                    <AuthTextField
                        id="last_name"
                        v-model="form.last_name"
                        label="Nama belakang"
                        required
                        placeholder="Nama belakang *"
                        :error="form.errors.last_name"
                    />
                    <div class="col-span-2">
                        <AuthTextField
                            id="street_address"
                            v-model="form.street_address"
                            label="Alamat"
                            required
                            placeholder="Alamat lengkap *"
                            :error="form.errors.street_address"
                        />
                    </div>
                    <div class="col-span-2">
                        <AuthTextField
                            id="street_address_plus"
                            v-model="form.street_address_plus"
                            label="Detail (opsional)"
                            placeholder="Apartemen, blok, dll."
                        />
                    </div>
                    <AuthTextField
                        id="city"
                        v-model="form.city"
                        label="Kota"
                        required
                        placeholder="Kota *"
                        :error="form.errors.city"
                    />
                    <AuthTextField
                        id="postal_code"
                        v-model="form.postal_code"
                        label="Kode pos"
                        required
                        placeholder="Kode pos *"
                        :error="form.errors.postal_code"
                    />
                    <AuthTextField
                        id="state"
                        v-model="form.state"
                        label="Provinsi"
                        placeholder="Provinsi"
                    />
                    <AuthSelectField
                        id="country_id"
                        v-model="countryId"
                        label="Negara"
                        placeholder="Pilih negara"
                        :options="countryOptions"
                        :error="form.errors.country_id"
                    />
                    <div class="col-span-2">
                        <AuthTextField
                            id="phone_number"
                            v-model="form.phone_number"
                            label="No. HP"
                            :required="form.type === AddressType.SHIPPING"
                            type="tel"
                            placeholder="08xxxxxxxxxx"
                            :error="form.errors.phone_number"
                        />
                    </div>
                    <div
                        v-if="shippingCapabilities?.destinationSearch"
                        class="relative col-span-2"
                    >
                        <AuthTextField
                            id="destination_query"
                            v-model="destinationQuery"
                            label="Kecamatan / Kode Pos tujuan"
                            placeholder="Cari kecamatan atau kode pos..."
                            :error="form.errors.destination_id"
                        />
                        <div
                            v-if="destinationSearching"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            Mencari lokasi…
                        </div>
                        <ul
                            v-else-if="destinationResults.length"
                            class="mt-1 max-h-40 overflow-y-auto rounded-md border border-border bg-background text-xs shadow-sm"
                        >
                            <li
                                v-for="opt in destinationResults"
                                :key="opt.destination_id"
                                class="cursor-pointer px-3 py-2 hover:bg-muted"
                                @click="pickDestination(opt)"
                            >
                                {{ opt.label }}
                            </li>
                        </ul>
                    </div>
                    <div
                        v-if="shippingCapabilities?.requiresCoordinates"
                        class="col-span-2"
                    >
                        <AuthTextField
                            id="pin_point"
                            v-model="form.pin_point"
                            label="Koordinat Pin Point (opsional)"
                            placeholder="-6.200000,106.816666"
                        />
                    </div>
                    <fieldset class="col-span-2 flex flex-col gap-2">
                        <legend class="text-sm font-medium text-foreground">
                            Jenis alamat
                        </legend>
                        <RadioGroup
                            v-model="form.type"
                            class="flex flex-wrap gap-4 pt-1"
                        >
                            <div class="flex items-center gap-2">
                                <RadioGroupItem
                                    :id="`type-shipping`"
                                    :value="AddressType.SHIPPING"
                                />
                                <Label
                                    :for="`type-shipping`"
                                    class="text-[13px] font-normal text-foreground"
                                >
                                    Pengiriman
                                </Label>
                            </div>
                            <div class="flex items-center gap-2">
                                <RadioGroupItem
                                    :id="`type-billing`"
                                    :value="AddressType.BILLING"
                                />
                                <Label
                                    :for="`type-billing`"
                                    class="text-[13px] font-normal text-foreground"
                                >
                                    Penagihan
                                </Label>
                            </div>
                        </RadioGroup>
                    </fieldset>
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <Button
                        type="button"
                        variant="ghost"
                        class="om-action-muted px-3"
                        @click="open = false"
                    >
                        Batal
                    </Button>
                    <AuthSubmitButton
                        class="!w-auto px-5"
                        label="Simpan"
                        :enabled="canSaveAddress"
                        :processing="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
