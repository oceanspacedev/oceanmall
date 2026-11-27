import OrderController from './OrderController'
import RetryOrderPaymentController from './RetryOrderPaymentController'
import SyncOrderPaymentController from './SyncOrderPaymentController'
import TrackShipmentController from './TrackShipmentController'
import ConfirmOrderReceivedController from './ConfirmOrderReceivedController'
import OrderActionController from './OrderActionController'
import NotificationController from './NotificationController'
import AddressController from './AddressController'
const Account = {
    OrderController: Object.assign(OrderController, OrderController),
RetryOrderPaymentController: Object.assign(RetryOrderPaymentController, RetryOrderPaymentController),
SyncOrderPaymentController: Object.assign(SyncOrderPaymentController, SyncOrderPaymentController),
TrackShipmentController: Object.assign(TrackShipmentController, TrackShipmentController),
ConfirmOrderReceivedController: Object.assign(ConfirmOrderReceivedController, ConfirmOrderReceivedController),
OrderActionController: Object.assign(OrderActionController, OrderActionController),
NotificationController: Object.assign(NotificationController, NotificationController),
AddressController: Object.assign(AddressController, AddressController),
}

export default Account