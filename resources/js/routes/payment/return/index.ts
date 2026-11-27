import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
 * @see routes/web.php:116
 * @route '/payment/return/mobile/{order}'
 */
export const mobile = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: mobile.url(args, options),
    method: 'get',
})

mobile.definition = {
    methods: ["get","head"],
    url: '/payment/return/mobile/{order}',
} satisfies RouteDefinition<["get","head"]>

/**
 * @see routes/web.php:116
 * @route '/payment/return/mobile/{order}'
 */
mobile.url = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions) => {
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

    return mobile.definition.url
            .replace('{order}', parsedArgs.order.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
 * @see routes/web.php:116
 * @route '/payment/return/mobile/{order}'
 */
mobile.get = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: mobile.url(args, options),
    method: 'get',
})
/**
 * @see routes/web.php:116
 * @route '/payment/return/mobile/{order}'
 */
mobile.head = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: mobile.url(args, options),
    method: 'head',
})

    /**
 * @see routes/web.php:116
 * @route '/payment/return/mobile/{order}'
 */
    const mobileForm = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: mobile.url(args, options),
        method: 'get',
    })

            /**
 * @see routes/web.php:116
 * @route '/payment/return/mobile/{order}'
 */
        mobileForm.get = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: mobile.url(args, options),
            method: 'get',
        })
            /**
 * @see routes/web.php:116
 * @route '/payment/return/mobile/{order}'
 */
        mobileForm.head = (args: { order: string | { number: string } } | [order: string | { number: string } ] | string | { number: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: mobile.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    mobile.form = mobileForm
const returnMethod = {
    mobile: Object.assign(mobile, mobile),
}

export default returnMethod