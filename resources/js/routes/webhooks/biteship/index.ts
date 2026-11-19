import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Integrations\Biteship\Http\Controllers\BiteshipWebhookController::__invoke
 * @see app/Integrations/Biteship/Http/Controllers/BiteshipWebhookController.php:36
 * @route '/webhooks/biteship'
 */
export const order = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: order.url(options),
    method: 'post',
})

order.definition = {
    methods: ["post"],
    url: '/webhooks/biteship',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Integrations\Biteship\Http\Controllers\BiteshipWebhookController::__invoke
 * @see app/Integrations/Biteship/Http/Controllers/BiteshipWebhookController.php:36
 * @route '/webhooks/biteship'
 */
order.url = (options?: RouteQueryOptions) => {
    return order.definition.url + queryParams(options)
}

/**
* @see \App\Integrations\Biteship\Http\Controllers\BiteshipWebhookController::__invoke
 * @see app/Integrations/Biteship/Http/Controllers/BiteshipWebhookController.php:36
 * @route '/webhooks/biteship'
 */
order.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: order.url(options),
    method: 'post',
})

    /**
* @see \App\Integrations\Biteship\Http\Controllers\BiteshipWebhookController::__invoke
 * @see app/Integrations/Biteship/Http/Controllers/BiteshipWebhookController.php:36
 * @route '/webhooks/biteship'
 */
    const orderForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: order.url(options),
        method: 'post',
    })

            /**
* @see \App\Integrations\Biteship\Http\Controllers\BiteshipWebhookController::__invoke
 * @see app/Integrations/Biteship/Http/Controllers/BiteshipWebhookController.php:36
 * @route '/webhooks/biteship'
 */
        orderForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: order.url(options),
            method: 'post',
        })
    
    order.form = orderForm
const biteship = {
    order: Object.assign(order, order),
}

export default biteship