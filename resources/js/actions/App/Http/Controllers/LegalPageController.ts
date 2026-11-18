import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\LegalPageController::show
 * @see app/Http/Controllers/LegalPageController.php:22
 * @route '/legal/{slug}'
 */
export const show = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/legal/{slug}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\LegalPageController::show
 * @see app/Http/Controllers/LegalPageController.php:22
 * @route '/legal/{slug}'
 */
show.url = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { slug: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    slug: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        slug: args.slug,
                }

    return show.definition.url
            .replace('{slug}', parsedArgs.slug.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\LegalPageController::show
 * @see app/Http/Controllers/LegalPageController.php:22
 * @route '/legal/{slug}'
 */
show.get = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\LegalPageController::show
 * @see app/Http/Controllers/LegalPageController.php:22
 * @route '/legal/{slug}'
 */
show.head = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\LegalPageController::show
 * @see app/Http/Controllers/LegalPageController.php:22
 * @route '/legal/{slug}'
 */
    const showForm = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: show.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\LegalPageController::show
 * @see app/Http/Controllers/LegalPageController.php:22
 * @route '/legal/{slug}'
 */
        showForm.get = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: show.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\LegalPageController::show
 * @see app/Http/Controllers/LegalPageController.php:22
 * @route '/legal/{slug}'
 */
        showForm.head = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \App\Http\Controllers\LegalPageController::support
 * @see app/Http/Controllers/LegalPageController.php:37
 * @route '/bantuan'
 */
export const support = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: support.url(options),
    method: 'get',
})

support.definition = {
    methods: ["get","head"],
    url: '/bantuan',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\LegalPageController::support
 * @see app/Http/Controllers/LegalPageController.php:37
 * @route '/bantuan'
 */
support.url = (options?: RouteQueryOptions) => {
    return support.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\LegalPageController::support
 * @see app/Http/Controllers/LegalPageController.php:37
 * @route '/bantuan'
 */
support.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: support.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\LegalPageController::support
 * @see app/Http/Controllers/LegalPageController.php:37
 * @route '/bantuan'
 */
support.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: support.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\LegalPageController::support
 * @see app/Http/Controllers/LegalPageController.php:37
 * @route '/bantuan'
 */
    const supportForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: support.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\LegalPageController::support
 * @see app/Http/Controllers/LegalPageController.php:37
 * @route '/bantuan'
 */
        supportForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: support.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\LegalPageController::support
 * @see app/Http/Controllers/LegalPageController.php:37
 * @route '/bantuan'
 */
        supportForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: support.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    support.form = supportForm
/**
* @see \App\Http\Controllers\LegalPageController::accountDeletion
 * @see app/Http/Controllers/LegalPageController.php:52
 * @route '/hapus-akun'
 */
export const accountDeletion = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: accountDeletion.url(options),
    method: 'get',
})

accountDeletion.definition = {
    methods: ["get","head"],
    url: '/hapus-akun',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\LegalPageController::accountDeletion
 * @see app/Http/Controllers/LegalPageController.php:52
 * @route '/hapus-akun'
 */
accountDeletion.url = (options?: RouteQueryOptions) => {
    return accountDeletion.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\LegalPageController::accountDeletion
 * @see app/Http/Controllers/LegalPageController.php:52
 * @route '/hapus-akun'
 */
accountDeletion.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: accountDeletion.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\LegalPageController::accountDeletion
 * @see app/Http/Controllers/LegalPageController.php:52
 * @route '/hapus-akun'
 */
accountDeletion.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: accountDeletion.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\LegalPageController::accountDeletion
 * @see app/Http/Controllers/LegalPageController.php:52
 * @route '/hapus-akun'
 */
    const accountDeletionForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: accountDeletion.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\LegalPageController::accountDeletion
 * @see app/Http/Controllers/LegalPageController.php:52
 * @route '/hapus-akun'
 */
        accountDeletionForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: accountDeletion.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\LegalPageController::accountDeletion
 * @see app/Http/Controllers/LegalPageController.php:52
 * @route '/hapus-akun'
 */
        accountDeletionForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: accountDeletion.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    accountDeletion.form = accountDeletionForm
const LegalPageController = { show, support, accountDeletion }

export default LegalPageController