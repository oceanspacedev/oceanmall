import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
export const callback = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: callback.url(options),
    method: 'post',
})

callback.definition = {
    methods: ["post"],
    url: '/webhooks/kiriminaja',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
callback.url = (options?: RouteQueryOptions) => {
    return callback.definition.url + queryParams(options)
}

/**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
callback.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: callback.url(options),
    method: 'post',
})

    /**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
    const callbackForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: callback.url(options),
        method: 'post',
    })

            /**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
        callbackForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: callback.url(options),
            method: 'post',
        })
    
    callback.form = callbackForm
const kiriminaja = {
    callback: Object.assign(callback, callback),
}

export default kiriminaja