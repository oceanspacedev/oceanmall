<script setup lang="ts">
import { computed } from 'vue';
import PaymentInstructionsPanel from '@/components/shop/payment-instructions-panel.vue';
import type { PaymentInstructions } from '@/components/shop/payment-instructions-panel.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { PaymentAction } from '@/types/shop';

const props = defineProps<{
    action: PaymentAction;
}>();

const instructions = computed<PaymentInstructions | null>(() =>
    props.action.kind === 'instructions' || props.action.kind === 'qr'
        ? (props.action.data as unknown as PaymentInstructions)
        : null,
);

const redirectUrl = computed<string | null>(() => {
    const url = props.action.data.url;

    return props.action.kind === 'redirect' && typeof url === 'string'
        ? url
        : null;
});

const redirectExpiry = computed<string | null>(() => {
    const raw = props.action.data.expiry_date ?? props.action.data.expires_at;
    if (typeof raw !== 'string' || !raw.trim()) {
        return null;
    }

    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) {
        return null;
    }

    return d.toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

const methodNote = computed<string | null>(() => {
    if (props.action.kind !== 'none') {
        return null;
    }

    const { instructions: text, description } = props.action.data;

    if (typeof text === 'string' && text.trim() !== '') {
        return text;
    }

    return typeof description === 'string' && description.trim() !== ''
        ? description
        : null;
});
</script>

<template>
    <PaymentInstructionsPanel v-if="instructions" :payment="instructions" />

    <Card
        v-else-if="redirectUrl"
        class="gap-0 rounded-md border-border bg-muted py-0 shadow-none"
    >
        <CardContent class="flex flex-col gap-3 p-4">
            <p class="text-[13px] text-foreground">
                Selesaikan pembayaran di halaman pembayaran.
            </p>
            <p v-if="redirectExpiry" class="text-[12px] text-muted-foreground">
                Berlaku hingga {{ redirectExpiry }}
            </p>
            <Button as-child size="xl">
                <a :href="redirectUrl" rel="noopener noreferrer">
                    Lanjutkan pembayaran
                </a>
            </Button>
        </CardContent>
    </Card>

    <p
        v-else-if="methodNote"
        class="text-[13px] whitespace-pre-line text-muted-foreground"
    >
        {{ methodNote }}
    </p>
</template>
