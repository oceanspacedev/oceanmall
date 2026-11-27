import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\OrderController::index
 * @see app/Http/Controllers/Api/V1/OrderController.php:53
 * @route '/api/v1/orders'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/v1/orders',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::index
 * @see app/Http/Controllers/Api/V1/OrderController.php:53
 * @route '/api/v1/orders'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::index
 * @see app/Http/Controllers/Api/V1/OrderController.php:53
 * @route '/api/v1/orders'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Api\V1\OrderController::index
 * @see app/Http/Controllers/Api/V1/OrderController.php:53
 * @route '/api/v1/orders'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::index
 * @see app/Http/Controllers/Api/V1/OrderController.php:53
 * @route '/api/v1/orders'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::index
 * @see app/Http/Controllers/Api/V1/OrderController.php:53
 * @route '/api/v1/orders'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Api\V1\OrderController::index
 * @see app/Http/Controllers/Api/V1/OrderController.php:53
 * @route '/api/v1/orders'
 */
        indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    index.form = indexForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::show
 * @see app/Http/Controllers/Api/V1/OrderController.php:111
 * @route '/api/v1/orders/{number}'
 */
export const show = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/v1/orders/{number}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::show
 * @see app/Http/Controllers/Api/V1/OrderController.php:111
 * @route '/api/v1/orders/{number}'
 */
show.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { number: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                }

    return show.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::show
 * @see app/Http/Controllers/Api/V1/OrderController.php:111
 * @route '/api/v1/orders/{number}'
 */
show.get = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Api\V1\OrderController::show
 * @see app/Http/Controllers/Api/V1/OrderController.php:111
 * @route '/api/v1/orders/{number}'
 */
show.head = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::show
 * @see app/Http/Controllers/Api/V1/OrderController.php:111
 * @route '/api/v1/orders/{number}'
 */
    const showForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::show
 * @see app/Http/Controllers/Api/V1/OrderController.php:111
 * @route '/api/v1/orders/{number}'
 */
        showForm.get = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Api\V1\OrderController::show
 * @see app/Http/Controllers/Api/V1/OrderController.php:111
 * @route '/api/v1/orders/{number}'
 */
        showForm.head = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    show.form = showForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::showProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:459
 * @route '/api/v1/orders/{number}/payment-proofs/{proof}'
 */
export const showProof = (args: { number: string | number, proof: number | { id: number } } | [number: string | number, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showProof.url(args, options),
    method: 'get',
})

showProof.definition = {
    methods: ["get","head"],
    url: '/api/v1/orders/{number}/payment-proofs/{proof}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::showProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:459
 * @route '/api/v1/orders/{number}/payment-proofs/{proof}'
 */
showProof.url = (args: { number: string | number, proof: number | { id: number } } | [number: string | number, proof: number | { id: number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                    proof: args[1],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                                proof: typeof args.proof === 'object'
                ? args.proof.id
                : args.proof,
                }

    return showProof.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace('{proof}', parsedArgs.proof.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::showProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:459
 * @route '/api/v1/orders/{number}/payment-proofs/{proof}'
 */
showProof.get = (args: { number: string | number, proof: number | { id: number } } | [number: string | number, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showProof.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Api\V1\OrderController::showProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:459
 * @route '/api/v1/orders/{number}/payment-proofs/{proof}'
 */
showProof.head = (args: { number: string | number, proof: number | { id: number } } | [number: string | number, proof: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showProof.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::showProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:459
 * @route '/api/v1/orders/{number}/payment-proofs/{proof}'
 */
    const showProofForm = (args: { number: string | number, proof: number | { id: number } } | [number: string | number, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: showProof.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::showProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:459
 * @route '/api/v1/orders/{number}/payment-proofs/{proof}'
 */
        showProofForm.get = (args: { number: string | number, proof: number | { id: number } } | [number: string | number, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: showProof.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Api\V1\OrderController::showProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:459
 * @route '/api/v1/orders/{number}/payment-proofs/{proof}'
 */
        showProofForm.head = (args: { number: string | number, proof: number | { id: number } } | [number: string | number, proof: number | { id: number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \App\Http\Controllers\Api\V1\OrderController::retryPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:285
 * @route '/api/v1/orders/{number}/retry-payment'
 */
export const retryPayment = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryPayment.url(args, options),
    method: 'post',
})

retryPayment.definition = {
    methods: ["post"],
    url: '/api/v1/orders/{number}/retry-payment',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::retryPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:285
 * @route '/api/v1/orders/{number}/retry-payment'
 */
retryPayment.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { number: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                }

    return retryPayment.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::retryPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:285
 * @route '/api/v1/orders/{number}/retry-payment'
 */
retryPayment.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retryPayment.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::retryPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:285
 * @route '/api/v1/orders/{number}/retry-payment'
 */
    const retryPaymentForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: retryPayment.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::retryPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:285
 * @route '/api/v1/orders/{number}/retry-payment'
 */
        retryPaymentForm.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: retryPayment.url(args, options),
            method: 'post',
        })
    
    retryPayment.form = retryPaymentForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::syncPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:313
 * @route '/api/v1/orders/{number}/sync-payment'
 */
export const syncPayment = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: syncPayment.url(args, options),
    method: 'post',
})

syncPayment.definition = {
    methods: ["post"],
    url: '/api/v1/orders/{number}/sync-payment',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::syncPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:313
 * @route '/api/v1/orders/{number}/sync-payment'
 */
syncPayment.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { number: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                }

    return syncPayment.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::syncPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:313
 * @route '/api/v1/orders/{number}/sync-payment'
 */
syncPayment.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: syncPayment.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::syncPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:313
 * @route '/api/v1/orders/{number}/sync-payment'
 */
    const syncPaymentForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: syncPayment.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::syncPayment
 * @see app/Http/Controllers/Api/V1/OrderController.php:313
 * @route '/api/v1/orders/{number}/sync-payment'
 */
        syncPaymentForm.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: syncPayment.url(args, options),
            method: 'post',
        })
    
    syncPayment.form = syncPaymentForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::track
 * @see app/Http/Controllers/Api/V1/OrderController.php:338
 * @route '/api/v1/orders/{number}/shipments/{shipment}/track'
 */
export const track = (args: { number: string | number, shipment: string | number } | [number: string | number, shipment: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: track.url(args, options),
    method: 'post',
})

track.definition = {
    methods: ["post"],
    url: '/api/v1/orders/{number}/shipments/{shipment}/track',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::track
 * @see app/Http/Controllers/Api/V1/OrderController.php:338
 * @route '/api/v1/orders/{number}/shipments/{shipment}/track'
 */
track.url = (args: { number: string | number, shipment: string | number } | [number: string | number, shipment: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                    shipment: args[1],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                                shipment: args.shipment,
                }

    return track.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace('{shipment}', parsedArgs.shipment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::track
 * @see app/Http/Controllers/Api/V1/OrderController.php:338
 * @route '/api/v1/orders/{number}/shipments/{shipment}/track'
 */
track.post = (args: { number: string | number, shipment: string | number } | [number: string | number, shipment: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: track.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::track
 * @see app/Http/Controllers/Api/V1/OrderController.php:338
 * @route '/api/v1/orders/{number}/shipments/{shipment}/track'
 */
    const trackForm = (args: { number: string | number, shipment: string | number } | [number: string | number, shipment: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: track.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::track
 * @see app/Http/Controllers/Api/V1/OrderController.php:338
 * @route '/api/v1/orders/{number}/shipments/{shipment}/track'
 */
        trackForm.post = (args: { number: string | number, shipment: string | number } | [number: string | number, shipment: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: track.url(args, options),
            method: 'post',
        })
    
    track.form = trackForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::confirmReceived
 * @see app/Http/Controllers/Api/V1/OrderController.php:363
 * @route '/api/v1/orders/{number}/confirm-received'
 */
export const confirmReceived = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: confirmReceived.url(args, options),
    method: 'post',
})

confirmReceived.definition = {
    methods: ["post"],
    url: '/api/v1/orders/{number}/confirm-received',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::confirmReceived
 * @see app/Http/Controllers/Api/V1/OrderController.php:363
 * @route '/api/v1/orders/{number}/confirm-received'
 */
confirmReceived.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { number: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                }

    return confirmReceived.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::confirmReceived
 * @see app/Http/Controllers/Api/V1/OrderController.php:363
 * @route '/api/v1/orders/{number}/confirm-received'
 */
confirmReceived.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: confirmReceived.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::confirmReceived
 * @see app/Http/Controllers/Api/V1/OrderController.php:363
 * @route '/api/v1/orders/{number}/confirm-received'
 */
    const confirmReceivedForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: confirmReceived.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::confirmReceived
 * @see app/Http/Controllers/Api/V1/OrderController.php:363
 * @route '/api/v1/orders/{number}/confirm-received'
 */
        confirmReceivedForm.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: confirmReceived.url(args, options),
            method: 'post',
        })
    
    confirmReceived.form = confirmReceivedForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::cancel
 * @see app/Http/Controllers/Api/V1/OrderController.php:379
 * @route '/api/v1/orders/{number}/cancel'
 */
export const cancel = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

cancel.definition = {
    methods: ["post"],
    url: '/api/v1/orders/{number}/cancel',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::cancel
 * @see app/Http/Controllers/Api/V1/OrderController.php:379
 * @route '/api/v1/orders/{number}/cancel'
 */
cancel.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { number: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                }

    return cancel.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::cancel
 * @see app/Http/Controllers/Api/V1/OrderController.php:379
 * @route '/api/v1/orders/{number}/cancel'
 */
cancel.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: cancel.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::cancel
 * @see app/Http/Controllers/Api/V1/OrderController.php:379
 * @route '/api/v1/orders/{number}/cancel'
 */
    const cancelForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: cancel.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::cancel
 * @see app/Http/Controllers/Api/V1/OrderController.php:379
 * @route '/api/v1/orders/{number}/cancel'
 */
        cancelForm.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: cancel.url(args, options),
            method: 'post',
        })
    
    cancel.form = cancelForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::storeReturn
 * @see app/Http/Controllers/Api/V1/OrderController.php:396
 * @route '/api/v1/orders/{number}/returns'
 */
export const storeReturn = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeReturn.url(args, options),
    method: 'post',
})

storeReturn.definition = {
    methods: ["post"],
    url: '/api/v1/orders/{number}/returns',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::storeReturn
 * @see app/Http/Controllers/Api/V1/OrderController.php:396
 * @route '/api/v1/orders/{number}/returns'
 */
storeReturn.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { number: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                }

    return storeReturn.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::storeReturn
 * @see app/Http/Controllers/Api/V1/OrderController.php:396
 * @route '/api/v1/orders/{number}/returns'
 */
storeReturn.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeReturn.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::storeReturn
 * @see app/Http/Controllers/Api/V1/OrderController.php:396
 * @route '/api/v1/orders/{number}/returns'
 */
    const storeReturnForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: storeReturn.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::storeReturn
 * @see app/Http/Controllers/Api/V1/OrderController.php:396
 * @route '/api/v1/orders/{number}/returns'
 */
        storeReturnForm.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: storeReturn.url(args, options),
            method: 'post',
        })
    
    storeReturn.form = storeReturnForm
/**
* @see \App\Http\Controllers\Api\V1\OrderController::uploadProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:428
 * @route '/api/v1/orders/{number}/payment-proof'
 */
export const uploadProof = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: uploadProof.url(args, options),
    method: 'post',
})

uploadProof.definition = {
    methods: ["post"],
    url: '/api/v1/orders/{number}/payment-proof',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\OrderController::uploadProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:428
 * @route '/api/v1/orders/{number}/payment-proof'
 */
uploadProof.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { number: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    number: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        number: args.number,
                }

    return uploadProof.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\OrderController::uploadProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:428
 * @route '/api/v1/orders/{number}/payment-proof'
 */
uploadProof.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: uploadProof.url(args, options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Api\V1\OrderController::uploadProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:428
 * @route '/api/v1/orders/{number}/payment-proof'
 */
    const uploadProofForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: uploadProof.url(args, options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Api\V1\OrderController::uploadProof
 * @see app/Http/Controllers/Api/V1/OrderController.php:428
 * @route '/api/v1/orders/{number}/payment-proof'
 */
        uploadProofForm.post = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: uploadProof.url(args, options),
            method: 'post',
        })
    
    uploadProof.form = uploadProofForm
const OrderController = { index, show, showProof, retryPayment, syncPayment, track, confirmReceived, cancel, storeReturn, uploadProof }

export default OrderController