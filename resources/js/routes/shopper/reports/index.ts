import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
export const finance = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: finance.url(options),
    method: 'get',
})

finance.definition = {
    methods: ["get","head"],
    url: '/cpanel/reports/finance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
finance.url = (options?: RouteQueryOptions) => {
    return finance.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
finance.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: finance.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
finance.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: finance.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
    const financeForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: finance.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
        financeForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: finance.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\FinanceReports::__invoke
 * @see app/Livewire/Shopper/Pages/FinanceReports.php:7
 * @route '/cpanel/reports/finance'
 */
        financeForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: finance.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    finance.form = financeForm
const reports = {
    finance: Object.assign(finance, finance),
}

export default reports