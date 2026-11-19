import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
import komerce from './komerce'
import xendit from './xendit'
import midtrans from './midtrans'
import biteship from './biteship'
import kiriminaja from './kiriminaja'
/**
* @see \App\Http\Controllers\StripeWebhookController::__invoke
 * @see app/Http/Controllers/StripeWebhookController.php:18
 * @route '/webhooks/stripe'
 */
export const stripe = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stripe.url(options),
    method: 'post',
})

stripe.definition = {
    methods: ["post"],
    url: '/webhooks/stripe',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\StripeWebhookController::__invoke
 * @see app/Http/Controllers/StripeWebhookController.php:18
 * @route '/webhooks/stripe'
 */
stripe.url = (options?: RouteQueryOptions) => {
    return stripe.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\StripeWebhookController::__invoke
 * @see app/Http/Controllers/StripeWebhookController.php:18
 * @route '/webhooks/stripe'
 */
stripe.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: stripe.url(options),
    method: 'post',
})

    /**
* @see \App\Http\Controllers\StripeWebhookController::__invoke
 * @see app/Http/Controllers/StripeWebhookController.php:18
 * @route '/webhooks/stripe'
 */
    const stripeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: stripe.url(options),
        method: 'post',
    })

            /**
* @see \App\Http\Controllers\StripeWebhookController::__invoke
 * @see app/Http/Controllers/StripeWebhookController.php:18
 * @route '/webhooks/stripe'
 */
        stripeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: stripe.url(options),
            method: 'post',
        })
    
    stripe.form = stripeForm
const webhooks = {
    komerce: Object.assign(komerce, komerce),
xendit: Object.assign(xendit, xendit),
midtrans: Object.assign(midtrans, midtrans),
biteship: Object.assign(biteship, biteship),
kiriminaja: Object.assign(kiriminaja, kiriminaja),
stripe: Object.assign(stripe, stripe),
}

export default webhooks