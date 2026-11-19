import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
export const notification = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: notification.url(options),
    method: 'post',
})

notification.definition = {
    methods: ["post"],
    url: '/webhooks/midtrans/notification',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
notification.url = (options?: RouteQueryOptions) => {
    return notification.definition.url + queryParams(options)
}

/**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
notification.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: notification.url(options),
    method: 'post',
})

    /**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
    const notificationForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: notification.url(options),
        method: 'post',
    })

            /**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
        notificationForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: notification.url(options),
            method: 'post',
        })
    
    notification.form = notificationForm
const midtrans = {
    notification: Object.assign(notification, notification),
}

export default midtrans