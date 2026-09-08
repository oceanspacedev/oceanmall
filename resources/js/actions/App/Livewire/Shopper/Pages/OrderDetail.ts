import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
const OrderDetail = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OrderDetail.url(args, options),
    method: 'get',
})

OrderDetail.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/detail',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
OrderDetail.url = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { order: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    order: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        order: args.order,
                }

    return OrderDetail.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
OrderDetail.get = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: OrderDetail.url(args, options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
OrderDetail.head = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: OrderDetail.url(args, options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
    const OrderDetailForm = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: OrderDetail.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
        OrderDetailForm.get = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OrderDetail.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
        OrderDetailForm.head = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: OrderDetail.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    OrderDetail.form = OrderDetailForm
export default OrderDetail