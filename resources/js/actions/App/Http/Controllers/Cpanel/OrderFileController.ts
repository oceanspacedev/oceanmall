import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showProof
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
export const showProof = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showProof.url(args, options),
    method: 'get',
})

showProof.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/payment-proofs/{proof}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showProof
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
showProof.url = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions) => {
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

    return showProof.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace('{proof}', parsedArgs.proof.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showProof
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
showProof.get = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showProof.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showProof
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
showProof.head = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showProof.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showProof
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
    const showProofForm = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: showProof.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showProof
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
        showProofForm.get = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: showProof.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showProof
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:17
 * @route '/cpanel/orders/{order}/payment-proofs/{proof}'
 */
        showProofForm.head = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: showProof.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    showProof.form = showProofForm
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showReturnEvidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
export const showReturnEvidence = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showReturnEvidence.url(args, options),
    method: 'get',
})

showReturnEvidence.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showReturnEvidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
showReturnEvidence.url = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions) => {
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

    return showReturnEvidence.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace('{returnRequest}', parsedArgs.returnRequest.toString())
            .replace('{index}', parsedArgs.index.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showReturnEvidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
showReturnEvidence.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showReturnEvidence.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showReturnEvidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
showReturnEvidence.head = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showReturnEvidence.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showReturnEvidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
    const showReturnEvidenceForm = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: showReturnEvidence.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showReturnEvidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
        showReturnEvidenceForm.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: showReturnEvidence.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\OrderFileController::showReturnEvidence
 * @see app/Http/Controllers/Cpanel/OrderFileController.php:28
 * @route '/cpanel/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
        showReturnEvidenceForm.head = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: showReturnEvidence.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    showReturnEvidence.form = showReturnEvidenceForm
const OrderFileController = { showProof, showReturnEvidence }

export default OrderFileController