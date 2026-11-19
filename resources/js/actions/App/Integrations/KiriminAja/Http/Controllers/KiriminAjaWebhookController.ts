import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
const KiriminAjaWebhookController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: KiriminAjaWebhookController.url(options),
    method: 'post',
})

KiriminAjaWebhookController.definition = {
    methods: ["post"],
    url: '/webhooks/kiriminaja',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
KiriminAjaWebhookController.url = (options?: RouteQueryOptions) => {
    return KiriminAjaWebhookController.definition.url + queryParams(options)
}

/**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
KiriminAjaWebhookController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: KiriminAjaWebhookController.url(options),
    method: 'post',
})

    /**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
    const KiriminAjaWebhookControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: KiriminAjaWebhookController.url(options),
        method: 'post',
    })

            /**
* @see \App\Integrations\KiriminAja\Http\Controllers\KiriminAjaWebhookController::__invoke
 * @see app/Integrations/KiriminAja/Http/Controllers/KiriminAjaWebhookController.php:38
 * @route '/webhooks/kiriminaja'
 */
        KiriminAjaWebhookControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: KiriminAjaWebhookController.url(options),
            method: 'post',
        })
    
    KiriminAjaWebhookController.form = KiriminAjaWebhookControllerForm
export default KiriminAjaWebhookController