import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Shop\CheckoutController::index
 * @see app/Http/Controllers/Shop/CheckoutController.php:67
 * @route '/checkout'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/checkout',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::index
 * @see app/Http/Controllers/Shop/CheckoutController.php:67
 * @route '/checkout'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::index
 * @see app/Http/Controllers/Shop/CheckoutController.php:67
 * @route '/checkout'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Shop\CheckoutController::index
 * @see app/Http/Controllers/Shop/CheckoutController.php:67
 * @route '/checkout'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::index
 * @see app/Http/Controllers/Shop/CheckoutController.php:67
 * @route '/checkout'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::index
 * @see app/Http/Controllers/Shop/CheckoutController.php:67
 * @route '/checkout'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Shop\CheckoutController::index
 * @see app/Http/Controllers/Shop/CheckoutController.php:67
 * @route '/checkout'
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
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingAddress
 * @see app/Http/Controllers/Shop/CheckoutController.php:289
 * @route '/checkout/shipping-address'
 */
export const saveShippingAddress = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: saveShippingAddress.url(options),
    method: 'post',
})

saveShippingAddress.definition = {
    methods: ["post"],
    url: '/checkout/shipping-address',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingAddress
 * @see app/Http/Controllers/Shop/CheckoutController.php:289
 * @route '/checkout/shipping-address'
 */
saveShippingAddress.url = (options?: RouteQueryOptions) => {
    return saveShippingAddress.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingAddress
 * @see app/Http/Controllers/Shop/CheckoutController.php:289
 * @route '/checkout/shipping-address'
 */
saveShippingAddress.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: saveShippingAddress.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingAddress
 * @see app/Http/Controllers/Shop/CheckoutController.php:289
 * @route '/checkout/shipping-address'
 */
    const saveShippingAddressForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: saveShippingAddress.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingAddress
 * @see app/Http/Controllers/Shop/CheckoutController.php:289
 * @route '/checkout/shipping-address'
 */
        saveShippingAddressForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: saveShippingAddress.url(options),
            method: 'post',
        })
    
    saveShippingAddress.form = saveShippingAddressForm
/**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingOption
 * @see app/Http/Controllers/Shop/CheckoutController.php:352
 * @route '/checkout/shipping-option'
 */
export const saveShippingOption = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: saveShippingOption.url(options),
    method: 'post',
})

saveShippingOption.definition = {
    methods: ["post"],
    url: '/checkout/shipping-option',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingOption
 * @see app/Http/Controllers/Shop/CheckoutController.php:352
 * @route '/checkout/shipping-option'
 */
saveShippingOption.url = (options?: RouteQueryOptions) => {
    return saveShippingOption.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingOption
 * @see app/Http/Controllers/Shop/CheckoutController.php:352
 * @route '/checkout/shipping-option'
 */
saveShippingOption.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: saveShippingOption.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingOption
 * @see app/Http/Controllers/Shop/CheckoutController.php:352
 * @route '/checkout/shipping-option'
 */
    const saveShippingOptionForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: saveShippingOption.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::saveShippingOption
 * @see app/Http/Controllers/Shop/CheckoutController.php:352
 * @route '/checkout/shipping-option'
 */
        saveShippingOptionForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: saveShippingOption.url(options),
            method: 'post',
        })
    
    saveShippingOption.form = saveShippingOptionForm
/**
* @see \App\Http\Controllers\Shop\CheckoutController::preparePayment
 * @see app/Http/Controllers/Shop/CheckoutController.php:484
 * @route '/checkout/prepare-payment'
 */
export const preparePayment = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preparePayment.url(options),
    method: 'post',
})

preparePayment.definition = {
    methods: ["post"],
    url: '/checkout/prepare-payment',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::preparePayment
 * @see app/Http/Controllers/Shop/CheckoutController.php:484
 * @route '/checkout/prepare-payment'
 */
preparePayment.url = (options?: RouteQueryOptions) => {
    return preparePayment.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::preparePayment
 * @see app/Http/Controllers/Shop/CheckoutController.php:484
 * @route '/checkout/prepare-payment'
 */
preparePayment.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preparePayment.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::preparePayment
 * @see app/Http/Controllers/Shop/CheckoutController.php:484
 * @route '/checkout/prepare-payment'
 */
    const preparePaymentForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: preparePayment.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::preparePayment
 * @see app/Http/Controllers/Shop/CheckoutController.php:484
 * @route '/checkout/prepare-payment'
 */
        preparePaymentForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: preparePayment.url(options),
            method: 'post',
        })
    
    preparePayment.form = preparePaymentForm
/**
* @see \App\Http\Controllers\Shop\CheckoutController::placeOrder
 * @see app/Http/Controllers/Shop/CheckoutController.php:549
 * @route '/checkout/place-order'
 */
export const placeOrder = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: placeOrder.url(options),
    method: 'post',
})

placeOrder.definition = {
    methods: ["post"],
    url: '/checkout/place-order',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::placeOrder
 * @see app/Http/Controllers/Shop/CheckoutController.php:549
 * @route '/checkout/place-order'
 */
placeOrder.url = (options?: RouteQueryOptions) => {
    return placeOrder.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::placeOrder
 * @see app/Http/Controllers/Shop/CheckoutController.php:549
 * @route '/checkout/place-order'
 */
placeOrder.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: placeOrder.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::placeOrder
 * @see app/Http/Controllers/Shop/CheckoutController.php:549
 * @route '/checkout/place-order'
 */
    const placeOrderForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: placeOrder.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::placeOrder
 * @see app/Http/Controllers/Shop/CheckoutController.php:549
 * @route '/checkout/place-order'
 */
        placeOrderForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: placeOrder.url(options),
            method: 'post',
        })
    
    placeOrder.form = placeOrderForm
/**
* @see \App\Http\Controllers\Shop\CheckoutController::confirmReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:612
 * @route '/checkout/confirm/{driver}/return'
 */
export const confirmReturn = (args: { driver: string | number } | [driver: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: confirmReturn.url(args, options),
    method: 'get',
})

confirmReturn.definition = {
    methods: ["get","head"],
    url: '/checkout/confirm/{driver}/return',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::confirmReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:612
 * @route '/checkout/confirm/{driver}/return'
 */
confirmReturn.url = (args: { driver: string | number } | [driver: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { driver: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    driver: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        driver: args.driver,
                }

    return confirmReturn.definition.url
            .replace('{driver}', parsedArgs.driver.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::confirmReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:612
 * @route '/checkout/confirm/{driver}/return'
 */
confirmReturn.get = (args: { driver: string | number } | [driver: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: confirmReturn.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Shop\CheckoutController::confirmReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:612
 * @route '/checkout/confirm/{driver}/return'
 */
confirmReturn.head = (args: { driver: string | number } | [driver: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: confirmReturn.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::confirmReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:612
 * @route '/checkout/confirm/{driver}/return'
 */
    const confirmReturnForm = (args: { driver: string | number } | [driver: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: confirmReturn.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::confirmReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:612
 * @route '/checkout/confirm/{driver}/return'
 */
        confirmReturnForm.get = (args: { driver: string | number } | [driver: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: confirmReturn.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Shop\CheckoutController::confirmReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:612
 * @route '/checkout/confirm/{driver}/return'
 */
        confirmReturnForm.head = (args: { driver: string | number } | [driver: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: confirmReturn.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    confirmReturn.form = confirmReturnForm
/**
* @see \App\Http\Controllers\Shop\CheckoutController::stripeReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:647
 * @route '/checkout/stripe-return'
 */
export const stripeReturn = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: stripeReturn.url(options),
    method: 'get',
})

stripeReturn.definition = {
    methods: ["get","head"],
    url: '/checkout/stripe-return',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::stripeReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:647
 * @route '/checkout/stripe-return'
 */
stripeReturn.url = (options?: RouteQueryOptions) => {
    return stripeReturn.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::stripeReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:647
 * @route '/checkout/stripe-return'
 */
stripeReturn.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: stripeReturn.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Shop\CheckoutController::stripeReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:647
 * @route '/checkout/stripe-return'
 */
stripeReturn.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: stripeReturn.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::stripeReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:647
 * @route '/checkout/stripe-return'
 */
    const stripeReturnForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: stripeReturn.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::stripeReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:647
 * @route '/checkout/stripe-return'
 */
        stripeReturnForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: stripeReturn.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Shop\CheckoutController::stripeReturn
 * @see app/Http/Controllers/Shop/CheckoutController.php:647
 * @route '/checkout/stripe-return'
 */
        stripeReturnForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: stripeReturn.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    stripeReturn.form = stripeReturnForm
/**
* @see \App\Http\Controllers\Shop\CheckoutController::legacyPaymentPage
 * @see app/Http/Controllers/Shop/CheckoutController.php:657
 * @route '/checkout/payment/{number}'
 */
export const legacyPaymentPage = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: legacyPaymentPage.url(args, options),
    method: 'get',
})

legacyPaymentPage.definition = {
    methods: ["get","head"],
    url: '/checkout/payment/{number}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Shop\CheckoutController::legacyPaymentPage
 * @see app/Http/Controllers/Shop/CheckoutController.php:657
 * @route '/checkout/payment/{number}'
 */
legacyPaymentPage.url = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return legacyPaymentPage.definition.url
            .replace('{number}', parsedArgs.number.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Shop\CheckoutController::legacyPaymentPage
 * @see app/Http/Controllers/Shop/CheckoutController.php:657
 * @route '/checkout/payment/{number}'
 */
legacyPaymentPage.get = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: legacyPaymentPage.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Shop\CheckoutController::legacyPaymentPage
 * @see app/Http/Controllers/Shop/CheckoutController.php:657
 * @route '/checkout/payment/{number}'
 */
legacyPaymentPage.head = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: legacyPaymentPage.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Shop\CheckoutController::legacyPaymentPage
 * @see app/Http/Controllers/Shop/CheckoutController.php:657
 * @route '/checkout/payment/{number}'
 */
    const legacyPaymentPageForm = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: legacyPaymentPage.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Shop\CheckoutController::legacyPaymentPage
 * @see app/Http/Controllers/Shop/CheckoutController.php:657
 * @route '/checkout/payment/{number}'
 */
        legacyPaymentPageForm.get = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: legacyPaymentPage.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Shop\CheckoutController::legacyPaymentPage
 * @see app/Http/Controllers/Shop/CheckoutController.php:657
 * @route '/checkout/payment/{number}'
 */
        legacyPaymentPageForm.head = (args: { number: string | number } | [number: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: legacyPaymentPage.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    legacyPaymentPage.form = legacyPaymentPageForm
const CheckoutController = { index, saveShippingAddress, saveShippingOption, preparePayment, placeOrder, confirmReturn, stripeReturn, legacyPaymentPage }

export default CheckoutController