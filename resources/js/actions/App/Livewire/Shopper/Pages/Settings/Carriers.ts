import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\Settings\Carriers::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/Carriers.php:7
 * @route '/cpanel/setting/carriers'
 */
const Carriers = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Carriers.url(options),
    method: 'get',
})

Carriers.definition = {
    methods: ["get","head"],
    url: '/cpanel/setting/carriers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\Settings\Carriers::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/Carriers.php:7
 * @route '/cpanel/setting/carriers'
 */
Carriers.url = (options?: RouteQueryOptions) => {
    return Carriers.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\Settings\Carriers::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/Carriers.php:7
 * @route '/cpanel/setting/carriers'
 */
Carriers.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Carriers.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\Settings\Carriers::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/Carriers.php:7
 * @route '/cpanel/setting/carriers'
 */
Carriers.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Carriers.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\Settings\Carriers::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/Carriers.php:7
 * @route '/cpanel/setting/carriers'
 */
    const CarriersForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: Carriers.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\Settings\Carriers::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/Carriers.php:7
 * @route '/cpanel/setting/carriers'
 */
        CarriersForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: Carriers.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\Settings\Carriers::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/Carriers.php:7
 * @route '/cpanel/setting/carriers'
 */
        CarriersForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: Carriers.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    Carriers.form = CarriersForm
export default Carriers