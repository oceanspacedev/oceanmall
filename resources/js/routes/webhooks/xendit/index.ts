import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
export const invoice = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: invoice.url(options),
    method: 'post',
})

invoice.definition = {
    methods: ["post"],
    url: '/webhooks/xendit/invoice',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
invoice.url = (options?: RouteQueryOptions) => {
    return invoice.definition.url + queryParams(options)
}

/**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
invoice.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: invoice.url(options),
    method: 'post',
})

    /**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
    const invoiceForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
        action: invoice.url(options),
        method: 'post',
    })

            /**
* @see \App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController::__invoke
 * @see app/Integrations/Xendit/Http/Controllers/XenditInvoiceWebhookController.php:37
 * @route '/webhooks/xendit/invoice'
 */
        invoiceForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
            action: invoice.url(options),
            method: 'post',
        })
    
    invoice.form = invoiceForm
const xendit = {
    invoice: Object.assign(invoice, invoice),
}

export default xendit