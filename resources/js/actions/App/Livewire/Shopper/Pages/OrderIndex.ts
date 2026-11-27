import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
const OrderIndex = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OrderIndex.url(options),
    method: 'get',
})

OrderIndex.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
OrderIndex.url = (options?: RouteQueryOptions) => {
    return OrderIndex.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
OrderIndex.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OrderIndex.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
OrderIndex.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OrderIndex.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
    const OrderIndexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: OrderIndex.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
        OrderIndexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OrderIndex.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
        OrderIndexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OrderIndex.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    OrderIndex.form = OrderIndexForm
export default OrderIndex