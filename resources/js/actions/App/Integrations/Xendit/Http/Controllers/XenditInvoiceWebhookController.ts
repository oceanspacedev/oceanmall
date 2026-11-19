import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
const XenditInvoiceWebhookController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: XenditInvoiceWebhookController.url(options),
    method: 'post',
})

XenditInvoiceWebhookController.definition = {
    methods: ["post"],
    url: '/webhooks/xendit/invoice',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
XenditInvoiceWebhookController.url = (options?: RouteQueryOptions) => {
    return XenditInvoiceWebhookController.definition.url + queryParams(options)
}

/**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
XenditInvoiceWebhookController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: XenditInvoiceWebhookController.url(options),
    method: 'post',
})

    /**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
    const XenditInvoiceWebhookControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: XenditInvoiceWebhookController.url(options),
        method: 'post',
    })

            /**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
        XenditInvoiceWebhookControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: XenditInvoiceWebhookController.url(options),
            method: 'post',
        })
    
    XenditInvoiceWebhookController.form = XenditInvoiceWebhookControllerForm
export default XenditInvoiceWebhookController