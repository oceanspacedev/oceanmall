import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
const AllocationOverrideLogs = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AllocationOverrideLogs.url(options),
    method: 'get',
})

AllocationOverrideLogs.definition = {
    methods: ["get","head"],
    url: '/cpanel/orders/allocation-logs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
AllocationOverrideLogs.url = (options?: RouteQueryOptions) => {
    return AllocationOverrideLogs.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
AllocationOverrideLogs.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AllocationOverrideLogs.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
AllocationOverrideLogs.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AllocationOverrideLogs.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
    const AllocationOverrideLogsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: AllocationOverrideLogs.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
        AllocationOverrideLogsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: AllocationOverrideLogs.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\AllocationOverrideLogs::__invoke
 * @see app/Livewire/Shopper/Pages/AllocationOverrideLogs.php:7
 * @route '/cpanel/orders/allocation-logs'
 */
        AllocationOverrideLogsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: AllocationOverrideLogs.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    AllocationOverrideLogs.form = AllocationOverrideLogsForm
export default AllocationOverrideLogs