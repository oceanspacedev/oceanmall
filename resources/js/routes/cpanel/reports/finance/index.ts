import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
export const exportMethod = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: exportMethod.url(options),
    method: 'get',
})

exportMethod.definition = {
    methods: ["get","head"],
    url: '/cpanel/reports/finance/export',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
exportMethod.url = (options?: RouteQueryOptions) => {
    return exportMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
exportMethod.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: exportMethod.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
exportMethod.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: exportMethod.url(options),
    method: 'head',
})

    /**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
    const exportMethodForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: exportMethod.url(options),
        method: 'get',
    })

            /**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
        exportMethodForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: exportMethod.url(options),
            method: 'get',
        })
            /**
* @see \App\Http\Controllers\Cpanel\FinanceExportController::__invoke
 * @see app/Http/Controllers/Cpanel/FinanceExportController.php:23
 * @route '/cpanel/reports/finance/export'
 */
        exportMethodForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: exportMethod.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    exportMethod.form = exportMethodForm
const finance = {
    export: Object.assign(exportMethod, exportMethod),
}

export default finance