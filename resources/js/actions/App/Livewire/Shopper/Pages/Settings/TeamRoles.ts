import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamRoles::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamRoles.php:7
 * @route '/cpanel/setting/team/roles/{role}'
 */
const TeamRoles = (args: { role: string | number } | [role: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TeamRoles.url(args, options),
    method: 'get',
})

TeamRoles.definition = {
    methods: ["get","head"],
    url: '/cpanel/setting/team/roles/{role}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamRoles::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamRoles.php:7
 * @route '/cpanel/setting/team/roles/{role}'
 */
TeamRoles.url = (args: { role: string | number } | [role: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { role: args }
    }

    
    if (Array.isArray(args)) {
        args = {
                    role: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        role: args.role,
                }

    return TeamRoles.definition.url
            .replace('{role}', parsedArgs.role.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamRoles::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamRoles.php:7
 * @route '/cpanel/setting/team/roles/{role}'
 */
TeamRoles.get = (args: { role: string | number } | [role: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TeamRoles.url(args, options),
    method: 'get',
})
/**
* @see \App\Livewire\Shopper\Pages\Settings\TeamRoles::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamRoles.php:7
 * @route '/cpanel/setting/team/roles/{role}'
 */
TeamRoles.head = (args: { role: string | number } | [role: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: TeamRoles.url(args, options),
    method: 'head',
})

    /**
* @see \App\Livewire\Shopper\Pages\Settings\TeamRoles::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamRoles.php:7
 * @route '/cpanel/setting/team/roles/{role}'
 */
    const TeamRolesForm = (args: { role: string | number } | [role: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
        action: TeamRoles.url(args, options),
        method: 'get',
    })

            /**
* @see \App\Livewire\Shopper\Pages\Settings\TeamRoles::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamRoles.php:7
 * @route '/cpanel/setting/team/roles/{role}'
 */
        TeamRolesForm.get = (args: { role: string | number } | [role: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: TeamRoles.url(args, options),
            method: 'get',
        })
            /**
* @see \App\Livewire\Shopper\Pages\Settings\TeamRoles::__invoke
 * @see app/Livewire/Shopper/Pages/Settings/TeamRoles.php:7
 * @route '/cpanel/setting/team/roles/{role}'
 */
        TeamRolesForm.head = (args: { role: string | number } | [role: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
            action: TeamRoles.url(args, {
                        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
                            _method: 'HEAD',
                            ...(options?.query ?? options?.mergeQuery ?? {}),
                        }
                    }),
            method: 'get',
        })
    
    TeamRoles.form = TeamRolesForm
export default TeamRoles