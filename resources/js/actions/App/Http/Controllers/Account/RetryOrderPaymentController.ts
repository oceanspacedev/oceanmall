import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Account\RetryOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/RetryOrderPaymentController.php:15
 * @route '/account/orders/{order}/retry-payment'
 */
const RetryOrderPaymentController = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RetryOrderPaymentController.url(args, options),
    method: 'post',
})

RetryOrderPaymentController.definition = {
    methods: ["post"],
    url: '/account/orders/{order}/retry-payment',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Account\RetryOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/RetryOrderPaymentController.php:15
 * @route '/account/orders/{order}/retry-payment'
 */
RetryOrderPaymentController.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return RetryOrderPaymentController.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Account\RetryOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/RetryOrderPaymentController.php:15
 * @route '/account/orders/{order}/retry-payment'
 */
RetryOrderPaymentController.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RetryOrderPaymentController.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Account\RetryOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/RetryOrderPaymentController.php:15
 * @route '/account/orders/{order}/retry-payment'
 */
    const RetryOrderPaymentControllerForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: RetryOrderPaymentController.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Account\RetryOrderPaymentController::__invoke
 * @see app/Http/Controllers/Account/RetryOrderPaymentController.php:15
 * @route '/account/orders/{order}/retry-payment'
 */
        RetryOrderPaymentControllerForm.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: RetryOrderPaymentController.url(args, options),
            method: 'post',
        })
    
    RetryOrderPaymentController.form = RetryOrderPaymentControllerForm
export default RetryOrderPaymentController