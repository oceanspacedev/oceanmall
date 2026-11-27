import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Auth\VerifyEmailLinkController::__invoke
 * @see app/Http/Controllers/Auth/VerifyEmailLinkController.php:22
 * @route '/email/verify/{id}/{hash}'
 */
const VerifyEmailLinkController = (args: { id: string | number, hash: string | number } | [id: string | number, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: VerifyEmailLinkController.url(args, options),
    method: 'get',
})

VerifyEmailLinkController.definition = {
    methods: ["get","head"],
    url: '/email/verify/{id}/{hash}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Auth\VerifyEmailLinkController::__invoke
 * @see app/Http/Controllers/Auth/VerifyEmailLinkController.php:22
 * @route '/email/verify/{id}/{hash}'
 */
VerifyEmailLinkController.url = (args: { id: string | number, hash: string | number } | [id: string | number, hash: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
                    id: args[0],
                    hash: args[1],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        id: args.id,
                                hash: args.hash,
                }

    return VerifyEmailLinkController.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace('{hash}', parsedArgs.hash.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Auth\VerifyEmailLinkController::__invoke
 * @see app/Http/Controllers/Auth/VerifyEmailLinkController.php:22
 * @route '/email/verify/{id}/{hash}'
 */
VerifyEmailLinkController.get = (args: { id: string | number, hash: string | number } | [id: string | number, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: VerifyEmailLinkController.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Auth\VerifyEmailLinkController::__invoke
 * @see app/Http/Controllers/Auth/VerifyEmailLinkController.php:22
 * @route '/email/verify/{id}/{hash}'
 */
VerifyEmailLinkController.head = (args: { id: string | number, hash: string | number } | [id: string | number, hash: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: VerifyEmailLinkController.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Auth\VerifyEmailLinkController::__invoke
 * @see app/Http/Controllers/Auth/VerifyEmailLinkController.php:22
 * @route '/email/verify/{id}/{hash}'
 */
    const VerifyEmailLinkControllerForm = (args: { id: string | number, hash: string | number } | [id: string | number, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: VerifyEmailLinkController.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Auth\VerifyEmailLinkController::__invoke
 * @see app/Http/Controllers/Auth/VerifyEmailLinkController.php:22
 * @route '/email/verify/{id}/{hash}'
 */
        VerifyEmailLinkControllerForm.get = (args: { id: string | number, hash: string | number } | [id: string | number, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: VerifyEmailLinkController.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Auth\VerifyEmailLinkController::__invoke
 * @see app/Http/Controllers/Auth/VerifyEmailLinkController.php:22
 * @route '/email/verify/{id}/{hash}'
 */
        VerifyEmailLinkControllerForm.head = (args: { id: string | number, hash: string | number } | [id: string | number, hash: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: VerifyEmailLinkController.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    VerifyEmailLinkController.form = VerifyEmailLinkControllerForm
export default VerifyEmailLinkController