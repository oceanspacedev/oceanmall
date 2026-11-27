import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamIndex::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamIndex.php:7
 * @route '/cpanel/setting/team'
 */
const TeamIndex = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TeamIndex.url(options),
    method: 'get',
})

TeamIndex.definition = {
    methods: ["get","head"],
    url: '/cpanel/setting/team',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamIndex::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamIndex.php:7
 * @route '/cpanel/setting/team'
 */
TeamIndex.url = (options?: RouteQueryOptions) => {
    return TeamIndex.definition.url + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamIndex::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamIndex.php:7
 * @route '/cpanel/setting/team'
 */
TeamIndex.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TeamIndex.url(options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamIndex::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamIndex.php:7
 * @route '/cpanel/setting/team'
 */
TeamIndex.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: TeamIndex.url(options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\Settings\TeamIndex::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamIndex.php:7
 * @route '/cpanel/setting/team'
 */
    const TeamIndexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: TeamIndex.url(options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\Settings\TeamIndex::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamIndex.php:7
 * @route '/cpanel/setting/team'
 */
        TeamIndexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: TeamIndex.url(options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\Settings\TeamIndex::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamIndex.php:7
 * @route '/cpanel/setting/team'
 */
        TeamIndexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: TeamIndex.url({
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    TeamIndex.form = TeamIndexForm
export default TeamIndex