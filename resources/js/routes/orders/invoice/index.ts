import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\OrderInvoiceController::signed
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
export const signed = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: signed.url(args, options),
    method: 'get',
})

signed.definition = {
    methods: ["get","head"],
    url: '/orders/{order}/invoice/signed',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\OrderInvoiceController::signed
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
signed.url = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { order: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'number' in args) {
            args = { order: args.number }
        }
    
    if (Array.isArray(args)) {
        args = {
                    order: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        order: typeof args.order === 'object'
                ? args.order.number
                : args.order,
                }

    return signed.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\OrderInvoiceController::signed
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
signed.get = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: signed.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\OrderInvoiceController::signed
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
signed.head = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: signed.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\OrderInvoiceController::signed
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
    const signedForm = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: signed.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\OrderInvoiceController::signed
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
        signedForm.get = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: signed.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\OrderInvoiceController::signed
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
        signedForm.head = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: signed.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    signed.form = signedForm
const invoice = {
    signed: Object.assign(signed, signed),
}

export default invoice