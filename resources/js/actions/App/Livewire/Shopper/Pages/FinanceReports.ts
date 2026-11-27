import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
const FinanceReports = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FinanceReports.url(options),
    method: 'get',
})

FinanceReports.definition = {
    methods: ["get","head"],
    url: '/cpanel/reports/finance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
FinanceReports.url = (options?: RouteQueryOptions) => {
    return FinanceReports.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
FinanceReports.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: FinanceReports.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
FinanceReports.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: FinanceReports.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
    const FinanceReportsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: FinanceReports.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
        FinanceReportsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: FinanceReports.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
        FinanceReportsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: FinanceReports.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    FinanceReports.form = FinanceReportsForm
export default FinanceReports