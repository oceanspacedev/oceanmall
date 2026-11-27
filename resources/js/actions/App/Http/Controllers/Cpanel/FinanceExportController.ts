import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
const FinanceExportController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FinanceExportController.url(options),
    method: 'get',
})

FinanceExportController.definition = {
    methods: ["get","head"],
    url: '/cpanel/reports/finance/export',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
FinanceExportController.url = (options?: RouteQueryOptions) => {
    return FinanceExportController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
FinanceExportController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FinanceExportController.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
FinanceExportController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: FinanceExportController.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
    const FinanceExportControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: FinanceExportController.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
        FinanceExportControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: FinanceExportController.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
        FinanceExportControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: FinanceExportController.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    FinanceExportController.form = FinanceExportControllerForm
export default FinanceExportController