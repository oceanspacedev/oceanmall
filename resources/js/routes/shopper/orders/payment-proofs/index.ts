import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::show
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
export const show = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/payment-proofs/{proof}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::show
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
show.url = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
                    order: args[0],
                    proof: args[1],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        order: typeof args.order === 'object'
                ? args.order.id
                : args.order,
                                proof: typeof args.proof === 'object'
                ? args.proof.id
                : args.proof,
                }

    return show.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace('{proof}', parsedArgs.proof.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::show
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
show.get = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::show
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
show.head = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::show
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
    const showForm = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::show
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
        showForm.get = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::show
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
        showForm.head = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    show.form = showForm
const paymentProofs = {
    show: Object.assign(show, show),
}

export default paymentProofs