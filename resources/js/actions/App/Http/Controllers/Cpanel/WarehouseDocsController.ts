import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::packingSlip
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:55
 * @route '/cpanel/orders/{order}/packing-slip'
 */
export const packingSlip = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: packingSlip.url(args, options),
    method: 'get',
})

packingSlip.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/packing-slip',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::packingSlip
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:55
 * @route '/cpanel/orders/{order}/packing-slip'
 */
packingSlip.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return packingSlip.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::packingSlip
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:55
 * @route '/cpanel/orders/{order}/packing-slip'
 */
packingSlip.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: packingSlip.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::packingSlip
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:55
 * @route '/cpanel/orders/{order}/packing-slip'
 */
packingSlip.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: packingSlip.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::packingSlip
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:55
 * @route '/cpanel/orders/{order}/packing-slip'
 */
    const packingSlipForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: packingSlip.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::packingSlip
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:55
 * @route '/cpanel/orders/{order}/packing-slip'
 */
        packingSlipForm.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: packingSlip.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::packingSlip
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:55
 * @route '/cpanel/orders/{order}/packing-slip'
 */
        packingSlipForm.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: packingSlip.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    packingSlip.form = packingSlipForm
/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::pickList
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:20
 * @route '/cpanel/orders/warehouse/pick-list'
 */
export const pickList = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: pickList.url(options),
    method: 'get',
})

pickList.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/warehouse/pick-list',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::pickList
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:20
 * @route '/cpanel/orders/warehouse/pick-list'
 */
pickList.url = (options?: RouteQueryOptions) => {
    return pickList.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::pickList
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:20
 * @route '/cpanel/orders/warehouse/pick-list'
 */
pickList.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: pickList.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::pickList
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:20
 * @route '/cpanel/orders/warehouse/pick-list'
 */
pickList.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: pickList.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::pickList
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:20
 * @route '/cpanel/orders/warehouse/pick-list'
 */
    const pickListForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: pickList.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::pickList
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:20
 * @route '/cpanel/orders/warehouse/pick-list'
 */
        pickListForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: pickList.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::pickList
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:20
 * @route '/cpanel/orders/warehouse/pick-list'
 */
        pickListForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: pickList.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    pickList.form = pickListForm
/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::bulkLabels
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:67
 * @route '/cpanel/orders/warehouse/bulk-labels'
 */
export const bulkLabels = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: bulkLabels.url(options),
    method: 'get',
})

bulkLabels.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/warehouse/bulk-labels',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::bulkLabels
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:67
 * @route '/cpanel/orders/warehouse/bulk-labels'
 */
bulkLabels.url = (options?: RouteQueryOptions) => {
    return bulkLabels.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::bulkLabels
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:67
 * @route '/cpanel/orders/warehouse/bulk-labels'
 */
bulkLabels.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: bulkLabels.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::bulkLabels
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:67
 * @route '/cpanel/orders/warehouse/bulk-labels'
 */
bulkLabels.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: bulkLabels.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::bulkLabels
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:67
 * @route '/cpanel/orders/warehouse/bulk-labels'
 */
    const bulkLabelsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: bulkLabels.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::bulkLabels
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:67
 * @route '/cpanel/orders/warehouse/bulk-labels'
 */
        bulkLabelsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: bulkLabels.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\WarehouseDocsController::bulkLabels
 * @see app/Http/Controllers/Cpanel/WarehouseDocsController.php:67
 * @route '/cpanel/orders/warehouse/bulk-labels'
 */
        bulkLabelsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: bulkLabels.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    bulkLabels.form = bulkLabelsForm
const WarehouseDocsController = { packingSlip, pickList, bulkLabels }

export default WarehouseDocsController