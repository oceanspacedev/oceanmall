import Auth from './Auth'
import Api from './Api'
import Shop from './Shop'
import LegalPageController from './LegalPageController'
import OrderInvoiceController from './OrderInvoiceController'
import StripeWebhookController from './StripeWebhookController'
import Account from './Account'
import Cpanel from './Cpanel'
import DashboardController from './DashboardController'
import Settings from './Settings'
const Controllers = {
    Auth: Object.assign(Auth, Auth),
Api: Object.assign(Api, Api),
Shop: Object.assign(Shop, Shop),
LegalPageController: Object.assign(LegalPageController, LegalPageController),
OrderInvoiceController: Object.assign(OrderInvoiceController, OrderInvoiceController),
StripeWebhookController: Object.assign(StripeWebhookController, StripeWebhookController),
Account: Object.assign(Account, Account),
Cpanel: Object.assign(Cpanel, Cpanel),
DashboardController: Object.assign(DashboardController, DashboardController),
Settings: Object.assign(Settings, Settings),
}

export default Controllers