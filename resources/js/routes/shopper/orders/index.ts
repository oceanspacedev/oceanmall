import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
import paymentProofs52c075 from './payment-proofs'
import returns4b8e07 from './returns'
import fulfillment from './fulfillment'
import warehouse from './warehouse'
/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
    const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: index.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
 */
        indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: index.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\OrderIndex::__invoke
 * @see app/Livewire/Shopper/Pages/OrderIndex.php:7
 * @route '/cpanel/orders'
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
* @see \App\Livewire\Shopper\Pages\OrderShipments::__invoke
 * @see app/Livewire/Shopper/Pages/OrderShipments.php:7
 * @route '/cpanel/orders/shipments'
 */
export const shipments = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: shipments.url(options),
    method: 'get',
})

shipments.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/shipments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\OrderShipments::__invoke
 * @see app/Livewire/Shopper/Pages/OrderShipments.php:7
 * @route '/cpanel/orders/shipments'
 */
shipments.url = (options?: RouteQueryOptions) => {
    return shipments.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\OrderShipments::__invoke
 * @see app/Livewire/Shopper/Pages/OrderShipments.php:7
 * @route '/cpanel/orders/shipments'
 */
shipments.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: shipments.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\OrderShipments::__invoke
 * @see app/Livewire/Shopper/Pages/OrderShipments.php:7
 * @route '/cpanel/orders/shipments'
 */
shipments.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: shipments.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\OrderShipments::__invoke
 * @see app/Livewire/Shopper/Pages/OrderShipments.php:7
 * @route '/cpanel/orders/shipments'
 */
    const shipmentsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: shipments.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\OrderShipments::__invoke
 * @see app/Livewire/Shopper/Pages/OrderShipments.php:7
 * @route '/cpanel/orders/shipments'
 */
        shipmentsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: shipments.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\OrderShipments::__invoke
 * @see app/Livewire/Shopper/Pages/OrderShipments.php:7
 * @route '/cpanel/orders/shipments'
 */
        shipmentsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: shipments.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    shipments.form = shipmentsForm
/**
* @see \Shopper\Livewire\Pages\Order\AbandonedCarts::__invoke
 * @see vendor/shopper/framework/src/Livewire/Pages/Order/AbandonedCarts.php:7
 * @route '/cpanel/orders/abandoned-carts'
 */
export const abandonedCarts = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: abandonedCarts.url(options),
    method: 'get',
})

abandonedCarts.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/abandoned-carts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Shopper\Livewire\Pages\Order\AbandonedCarts::__invoke
 * @see vendor/shopper/framework/src/Livewire/Pages/Order/AbandonedCarts.php:7
 * @route '/cpanel/orders/abandoned-carts'
 */
abandonedCarts.url = (options?: RouteQueryOptions) => {
    return abandonedCarts.definition.url + queryParams(options)
}

/**
* @see \Shopper\Livewire\Pages\Order\AbandonedCarts::__invoke
 * @see vendor/shopper/framework/src/Livewire/Pages/Order/AbandonedCarts.php:7
 * @route '/cpanel/orders/abandoned-carts'
 */
abandonedCarts.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: abandonedCarts.url(options),
    method: 'get',
})
/**
* @see \Shopper\Livewire\Pages\Order\AbandonedCarts::__invoke
 * @see vendor/shopper/framework/src/Livewire/Pages/Order/AbandonedCarts.php:7
 * @route '/cpanel/orders/abandoned-carts'
 */
abandonedCarts.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: abandonedCarts.url(options),
    method: 'head',
})

    /**
* @see \Shopper\Livewire\Pages\Order\AbandonedCarts::__invoke
 * @see vendor/shopper/framework/src/Livewire/Pages/Order/AbandonedCarts.php:7
 * @route '/cpanel/orders/abandoned-carts'
 */
    const abandonedCartsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: abandonedCarts.url(options),
        method: 'get',
    })

            /**
* @see \Shopper\Livewire\Pages\Order\AbandonedCarts::__invoke
 * @see vendor/shopper/framework/src/Livewire/Pages/Order/AbandonedCarts.php:7
 * @route '/cpanel/orders/abandoned-carts'
 */
        abandonedCartsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: abandonedCarts.url(options),
            method: 'get',
        })
            /**
* @see \Shopper\Livewire\Pages\Order\AbandonedCarts::__invoke
 * @see vendor/shopper/framework/src/Livewire/Pages/Order/AbandonedCarts.php:7
 * @route '/cpanel/orders/abandoned-carts'
 */
        abandonedCartsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: abandonedCarts.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    abandonedCarts.form = abandonedCartsForm
/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
export const detail = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: detail.url(args, options),
    method: 'get',
})

detail.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/detail',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
detail.url = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { order: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    order: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        order: args.order,
                }

    return detail.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
detail.get = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: detail.url(args, options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
detail.head = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: detail.url(args, options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
    const detailForm = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: detail.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
        detailForm.get = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: detail.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\OrderDetail::__invoke
 * @see app/Livewire/Shopper/Pages/OrderDetail.php:7
 * @route '/cpanel/orders/{order}/detail'
 */
        detailForm.head = (args: { order: string | number } | [order: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: detail.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    detail.form = detailForm
/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
export const paymentProofs = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: paymentProofs.url(options),
    method: 'get',
})

paymentProofs.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/payment-proofs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
paymentProofs.url = (options?: RouteQueryOptions) => {
    return paymentProofs.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
paymentProofs.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: paymentProofs.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
paymentProofs.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: paymentProofs.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
    const paymentProofsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: paymentProofs.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
        paymentProofsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: paymentProofs.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\ManualPaymentProofs::__invoke
 * @see app/Livewire/Shopper/Pages/ManualPaymentProofs.php:7
 * @route '/cpanel/orders/payment-proofs'
 */
        paymentProofsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: paymentProofs.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    paymentProofs.form = paymentProofsForm
/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
export const returns = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: returns.url(options),
    method: 'get',
})

returns.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/returns',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
returns.url = (options?: RouteQueryOptions) => {
    return returns.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
returns.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: returns.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
returns.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: returns.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
    const returnsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: returns.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
        returnsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: returns.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\OrderReturns::__invoke
 * @see app/Livewire/Shopper/Pages/OrderReturns.php:7
 * @route '/cpanel/orders/returns'
 */
        returnsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: returns.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    returns.form = returnsForm
/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
export const disputes = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: disputes.url(options),
    method: 'get',
})

disputes.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/disputes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
disputes.url = (options?: RouteQueryOptions) => {
    return disputes.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
disputes.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: disputes.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
disputes.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: disputes.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
    const disputesForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: disputes.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
        disputesForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: disputes.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\PaymentDisputes::__invoke
 * @see app/Livewire/Shopper/Pages/PaymentDisputes.php:7
 * @route '/cpanel/orders/disputes'
 */
        disputesForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: disputes.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    disputes.form = disputesForm
/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
export const allocationLogs = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: allocationLogs.url(options),
    method: 'get',
})

allocationLogs.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/allocation-logs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
allocationLogs.url = (options?: RouteQueryOptions) => {
    return allocationLogs.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
allocationLogs.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: allocationLogs.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
allocationLogs.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: allocationLogs.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
    const allocationLogsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: allocationLogs.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
        allocationLogsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: allocationLogs.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
        allocationLogsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: allocationLogs.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    allocationLogs.form = allocationLogsForm
const orders = {
    index: Object.assign(index, index),
shipments: Object.assign(shipments, shipments),
abandonedCarts: Object.assign(abandonedCarts, abandonedCarts),
detail: Object.assign(detail, detail),
paymentProofs: Object.assign(paymentProofs, paymentProofs52c075),
returns: Object.assign(returns, returns4b8e07),
disputes: Object.assign(disputes, disputes),
allocationLogs: Object.assign(allocationLogs, allocationLogs),
fulfillment: Object.assign(fulfillment, fulfillment),
warehouse: Object.assign(warehouse, warehouse),
}

export default orders