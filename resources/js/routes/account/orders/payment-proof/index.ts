import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
export const store = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/account/orders/{order}/payment-proof',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
store.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { order: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { order: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    order: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        order: typeof args.order === 'object'
                ? args.order.id
                : args.order,
                }

    return store.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
store.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
    const storeForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
        storeForm.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(args, options),
            method: 'post',
        })
    
    store.form = storeForm
const paymentProof = {
    store: Object.assign(store, store),
}

export default paymentProof