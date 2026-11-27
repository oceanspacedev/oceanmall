import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::evidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
export const evidence = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: evidence.url(args, options),
    method: 'get',
})

evidence.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::evidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
evidence.url = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
                    order: args[0],
                    returnRequest: args[1],
                    index: args[2],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        order: typeof args.order === 'object'
                ? args.order.id
                : args.order,
                                returnRequest: typeof args.returnRequest === 'object'
                ? args.returnRequest.id
                : args.returnRequest,
                                index: args.index,
                }

    return evidence.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace('{returnRequest}', parsedArgs.returnRequest.toString())
            .replace('{index}', parsedArgs.index.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::evidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
evidence.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: evidence.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::evidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
evidence.head = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: evidence.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::evidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
    const evidenceForm = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: evidence.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::evidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
        evidenceForm.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: evidence.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::evidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
        evidenceForm.head = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: evidence.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    evidence.form = evidenceForm
const returns = {
    evidence: Object.assign(evidence, evidence),
}

export default returns