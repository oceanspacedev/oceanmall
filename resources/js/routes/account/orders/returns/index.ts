import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
export const store = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/account/orders/{order}/returns',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
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
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
store.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
    const storeForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: store.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::store
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
        storeForm.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: store.url(args, options),
            method: 'post',
        })
    
    store.form = storeForm
/**
* @see \App\Http\Controllers\Account\OrderActionController::evidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
export const evidence = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: evidence.url(args, options),
    method: 'get',
})

evidence.definition = {
    methods: ["get","head"],
    url: '/account/orders/{order}/returns/{returnRequest}/evidence/{index}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::evidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
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
* @see \App\Http\Controllers\Account\OrderActionController::evidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
evidence.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: evidence.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Account\OrderActionController::evidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
evidence.head = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: evidence.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::evidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
    const evidenceForm = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: evidence.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::evidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
        evidenceForm.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: evidence.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Account\OrderActionController::evidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
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
    store: Object.assign(store, store),
evidence: Object.assign(evidence, evidence),
}

export default returns