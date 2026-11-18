import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Account\SyncOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/SyncOrderPaymentController.php:16
 * @route '/account/orders/{order}/sync-payment'
 */
const SyncOrderPaymentController = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SyncOrderPaymentController.url(args, options),
    method: 'post',
})

SyncOrderPaymentController.definition = {
    methods: ["post"],
    url: '/account/orders/{order}/sync-payment',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Account\SyncOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/SyncOrderPaymentController.php:16
 * @route '/account/orders/{order}/sync-payment'
 */
SyncOrderPaymentController.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return SyncOrderPaymentController.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Account\SyncOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/SyncOrderPaymentController.php:16
 * @route '/account/orders/{order}/sync-payment'
 */
SyncOrderPaymentController.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SyncOrderPaymentController.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Account\SyncOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/SyncOrderPaymentController.php:16
 * @route '/account/orders/{order}/sync-payment'
 */
    const SyncOrderPaymentControllerForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: SyncOrderPaymentController.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Account\SyncOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/SyncOrderPaymentController.php:16
 * @route '/account/orders/{order}/sync-payment'
 */
        SyncOrderPaymentControllerForm.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: SyncOrderPaymentController.url(args, options),
            method: 'post',
        })
    
    SyncOrderPaymentController.form = SyncOrderPaymentControllerForm
export default SyncOrderPaymentController