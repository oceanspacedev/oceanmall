import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
const PaymentDisputes = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PaymentDisputes.url(options),
    method: 'get',
})

PaymentDisputes.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/disputes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
PaymentDisputes.url = (options?: RouteQueryOptions) => {
    return PaymentDisputes.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
PaymentDisputes.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PaymentDisputes.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
PaymentDisputes.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PaymentDisputes.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
    const PaymentDisputesForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: PaymentDisputes.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
        PaymentDisputesForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: PaymentDisputes.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
        PaymentDisputesForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: PaymentDisputes.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    PaymentDisputes.form = PaymentDisputesForm
export default PaymentDisputes