import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
const MidtransNotificationController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: MidtransNotificationController.url(options),
    method: 'post',
})

MidtransNotificationController.definition = {
    methods: ["post"],
    url: '/webhooks/midtrans/notification',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
MidtransNotificationController.url = (options?: RouteQueryOptions) => {
    return MidtransNotificationController.definition.url + queryParams(options)
}

/**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
MidtransNotificationController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: MidtransNotificationController.url(options),
    method: 'post',
})

    /**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
    const MidtransNotificationControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: MidtransNotificationController.url(options),
        method: 'post',
    })

            /**
* @see \App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController::__invoke
 * @see app/Integrations/Midtrans/Http/Controllers/MidtransNotificationController.php:31
 * @route '/webhooks/midtrans/notification'
 */
        MidtransNotificationControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: MidtransNotificationController.url(options),
            method: 'post',
        })
    
    MidtransNotificationController.form = MidtransNotificationControllerForm
export default MidtransNotificationController