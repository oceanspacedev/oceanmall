import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
const ManualPaymentProofs = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ManualPaymentProofs.url(options),
    method: 'get',
})

ManualPaymentProofs.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/payment-proofs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
ManualPaymentProofs.url = (options?: RouteQueryOptions) => {
    return ManualPaymentProofs.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
ManualPaymentProofs.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ManualPaymentProofs.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
ManualPaymentProofs.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ManualPaymentProofs.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
    const ManualPaymentProofsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: ManualPaymentProofs.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
        ManualPaymentProofsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: ManualPaymentProofs.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
        ManualPaymentProofsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: ManualPaymentProofs.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    ManualPaymentProofs.form = ManualPaymentProofsForm
export default ManualPaymentProofs