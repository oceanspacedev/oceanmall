import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\OrderInvoiceController::signedCustomer
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
export const signedCustomer = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: signedCustomer.url(args, options),
    method: 'get',
})

signedCustomer.definition = {
    methods: ["get","head"],
    url: '/orders/{order}/invoice/signed',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\OrderInvoiceController::signedCustomer
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
signedCustomer.url = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { order: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'number' in args) {
            args = { order: args.number }
        }
    
    if (Array.isArray(args)) {
        args = {
                    order: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        order: typeof args.order === 'object'
                ? args.order.number
                : args.order,
                }

    return signedCustomer.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\OrderInvoiceController::signedCustomer
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
signedCustomer.get = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: signedCustomer.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\OrderInvoiceController::signedCustomer
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
signedCustomer.head = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: signedCustomer.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\OrderInvoiceController::signedCustomer
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
    const signedCustomerForm = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: signedCustomer.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\OrderInvoiceController::signedCustomer
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
        signedCustomerForm.get = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: signedCustomer.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\OrderInvoiceController::signedCustomer
 * @see app/Http/Controllers/OrderInvoiceController.php:29
 * @route '/orders/{order}/invoice/signed'
 */
        signedCustomerForm.head = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: signedCustomer.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    signedCustomer.form = signedCustomerForm
/**
* @see \App\Http\Controllers\OrderInvoiceController::customer
 * @see app/Http/Controllers/OrderInvoiceController.php:22
 * @route '/account/orders/{order}/invoice'
 */
export const customer = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: customer.url(args, options),
    method: 'get',
})

customer.definition = {
    methods: ["get","head"],
    url: '/account/orders/{order}/invoice',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\OrderInvoiceController::customer
 * @see app/Http/Controllers/OrderInvoiceController.php:22
 * @route '/account/orders/{order}/invoice'
 */
customer.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return customer.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\OrderInvoiceController::customer
 * @see app/Http/Controllers/OrderInvoiceController.php:22
 * @route '/account/orders/{order}/invoice'
 */
customer.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: customer.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\OrderInvoiceController::customer
 * @see app/Http/Controllers/OrderInvoiceController.php:22
 * @route '/account/orders/{order}/invoice'
 */
customer.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: customer.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\OrderInvoiceController::customer
 * @see app/Http/Controllers/OrderInvoiceController.php:22
 * @route '/account/orders/{order}/invoice'
 */
    const customerForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: customer.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\OrderInvoiceController::customer
 * @see app/Http/Controllers/OrderInvoiceController.php:22
 * @route '/account/orders/{order}/invoice'
 */
        customerForm.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: customer.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\OrderInvoiceController::customer
 * @see app/Http/Controllers/OrderInvoiceController.php:22
 * @route '/account/orders/{order}/invoice'
 */
        customerForm.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: customer.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    customer.form = customerForm
/**
* @see \App\Http\Controllers\OrderInvoiceController::staff
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
export const staff = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: staff.url(args, options),
    method: 'get',
})

staff.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/{order}/invoice',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\OrderInvoiceController::staff
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
staff.url = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return staff.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\OrderInvoiceController::staff
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
staff.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: staff.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\OrderInvoiceController::staff
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
staff.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: staff.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\OrderInvoiceController::staff
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
    const staffForm = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: staff.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\OrderInvoiceController::staff
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
        staffForm.get = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: staff.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\OrderInvoiceController::staff
 * @see app/Http/Controllers/OrderInvoiceController.php:34
 * @route '/cpanel/orders/{order}/invoice'
 */
        staffForm.head = (args: { order: number | { id: number } } | [order: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: staff.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    staff.form = staffForm
const OrderInvoiceController = { signedCustomer, customer, staff }

export default OrderInvoiceController