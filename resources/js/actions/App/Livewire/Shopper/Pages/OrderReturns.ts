import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
const OrderReturns = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OrderReturns.url(options),
    method: 'get',
})

OrderReturns.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/returns',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
OrderReturns.url = (options?: RouteQueryOptions) => {
    return OrderReturns.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
OrderReturns.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OrderReturns.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
OrderReturns.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OrderReturns.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
    const OrderReturnsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: OrderReturns.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
        OrderReturnsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OrderReturns.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
        OrderReturnsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OrderReturns.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    OrderReturns.form = OrderReturnsForm
export default OrderReturns