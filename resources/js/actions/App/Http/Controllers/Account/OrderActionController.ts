import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Account\OrderActionController::cancel
 * @see app/Http/Controllers/Account/OrderActionController.php:22
 * @route '/account/orders/{order}/cancel'
 */
export const cancel = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/account/orders/{order}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::cancel
 * @see app/Http/Controllers/Account/OrderActionController.php:22
 * @route '/account/orders/{order}/cancel'
 */
cancel.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return cancel.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Account\OrderActionController::cancel
 * @see app/Http/Controllers/Account/OrderActionController.php:22
 * @route '/account/orders/{order}/cancel'
 */
cancel.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::cancel
 * @see app/Http/Controllers/Account/OrderActionController.php:22
 * @route '/account/orders/{order}/cancel'
 */
    const cancelForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: cancel.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::cancel
 * @see app/Http/Controllers/Account/OrderActionController.php:22
 * @route '/account/orders/{order}/cancel'
 */
        cancelForm.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: cancel.url(args, options),
            method: 'post',
        })
    
    cancel.form = cancelForm
/**
* @see \App\Http\Controllers\Account\OrderActionController::storeReturn
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
export const storeReturn = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeReturn.url(args, options),
    method: 'post',
})

storeReturn.definition = {
    methods: ["post"],
    url: '/account/orders/{order}/returns',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::storeReturn
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
storeReturn.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return storeReturn.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Account\OrderActionController::storeReturn
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
storeReturn.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeReturn.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::storeReturn
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
    const storeReturnForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: storeReturn.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::storeReturn
 * @see app/Http/Controllers/Account/OrderActionController.php:37
 * @route '/account/orders/{order}/returns'
 */
        storeReturnForm.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: storeReturn.url(args, options),
            method: 'post',
        })
    
    storeReturn.form = storeReturnForm
/**
* @see \App\Http\Controllers\Account\OrderActionController::uploadProof
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
export const uploadProof = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: uploadProof.url(args, options),
    method: 'post',
})

uploadProof.definition = {
    methods: ["post"],
    url: '/account/orders/{order}/payment-proof',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::uploadProof
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
uploadProof.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return uploadProof.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Account\OrderActionController::uploadProof
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
uploadProof.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: uploadProof.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::uploadProof
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
    const uploadProofForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: uploadProof.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::uploadProof
 * @see app/Http/Controllers/Account/OrderActionController.php:65
 * @route '/account/orders/{order}/payment-proof'
 */
        uploadProofForm.post = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: uploadProof.url(args, options),
            method: 'post',
        })
    
    uploadProof.form = uploadProofForm
/**
* @see \App\Http\Controllers\Account\OrderActionController::showProof
 * @see app/Http/Controllers/Account/OrderActionController.php:92
 * @route '/account/orders/{order}/payment-proofs/{proof}'
 */
export const showProof = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showProof.url(args, options),
    method: 'get',
})

showProof.definition = {
    methods: ["get","head"],
    url: '/account/orders/{order}/payment-proofs/{proof}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::showProof
 * @see app/Http/Controllers/Account/OrderActionController.php:92
 * @route '/account/orders/{order}/payment-proofs/{proof}'
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
* @see \App\Http\Controllers\Account\OrderActionController::showProof
 * @see app/Http/Controllers/Account/OrderActionController.php:92
 * @route '/account/orders/{order}/payment-proofs/{proof}'
 */
showProof.get = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showProof.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Account\OrderActionController::showProof
 * @see app/Http/Controllers/Account/OrderActionController.php:92
 * @route '/account/orders/{order}/payment-proofs/{proof}'
 */
showProof.head = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showProof.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::showProof
 * @see app/Http/Controllers/Account/OrderActionController.php:92
 * @route '/account/orders/{order}/payment-proofs/{proof}'
 */
    const showProofForm = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: showProof.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::showProof
 * @see app/Http/Controllers/Account/OrderActionController.php:92
 * @route '/account/orders/{order}/payment-proofs/{proof}'
 */
        showProofForm.get = (args: { order: number | { id: number }, proof: number | { id: number } } | [order: number | { id: number }, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: showProof.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Account\OrderActionController::showProof
 * @see app/Http/Controllers/Account/OrderActionController.php:92
 * @route '/account/orders/{order}/payment-proofs/{proof}'
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
* @see \App\Http\Controllers\Account\OrderActionController::showReturnEvidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
export const showReturnEvidence = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showReturnEvidence.url(args, options),
    method: 'get',
})

showReturnEvidence.definition = {
    methods: ["get","head"],
    url: '/account/orders/{order}/returns/{returnRequest}/evidence/{index}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Account\OrderActionController::showReturnEvidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
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
* @see \App\Http\Controllers\Account\OrderActionController::showReturnEvidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
showReturnEvidence.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showReturnEvidence.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Account\OrderActionController::showReturnEvidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
showReturnEvidence.head = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showReturnEvidence.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Account\OrderActionController::showReturnEvidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
    const showReturnEvidenceForm = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: showReturnEvidence.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Account\OrderActionController::showReturnEvidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
 */
        showReturnEvidenceForm.get = (args: { order: number | { id: number }, returnRequest: number | { id: number }, index: string | number } | [order: number | { id: number }, returnRequest: number | { id: number }, index: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: showReturnEvidence.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Account\OrderActionController::showReturnEvidence
 * @see app/Http/Controllers/Account/OrderActionController.php:102
 * @route '/account/orders/{order}/returns/{returnRequest}/evidence/{index}'
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
const OrderActionController = { cancel, storeReturn, uploadProof, showProof, showReturnEvidence }

export default OrderActionController