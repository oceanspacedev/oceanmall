import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\OrderInvoiceController::invoice
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
export const invoice = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: invoice.url(args, options),
    method: 'get',
})

invoice.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/invoice',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\OrderInvoiceController::invoice
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
invoice.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return invoice.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\OrderInvoiceController::invoice
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
invoice.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: invoice.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\OrderInvoiceController::invoice
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
invoice.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: invoice.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\OrderInvoiceController::invoice
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
    const invoiceForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: invoice.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\OrderInvoiceController::invoice
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
        invoiceForm.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: invoice.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\OrderInvoiceController::invoice
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
        invoiceForm.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: invoice.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    invoice.form = invoiceForm
const orders = {
    invoice: Object.assign(invoice, invoice),
}

export default orders