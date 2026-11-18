import KomercePaymentWebhookController from './KomercePaymentWebhookController'
import KomerceDeliveryWebhookController from './KomerceDeliveryWebhookController'
import KomerceQrislyWebhookController from './KomerceQrislyWebhookController'
const Controllers = {
    KomercePaymentWebhookController: Object.assign(KomercePaymentWebhookController, KomercePaymentWebhookController),
KomerceDeliveryWebhookController: Object.assign(KomerceDeliveryWebhookController, KomerceDeliveryWebhookController),
KomerceQrislyWebhookController: Object.assign(KomerceQrislyWebhookController, KomerceQrislyWebhookController),
}

export default Controllers