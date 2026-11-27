import Dashboard from './Dashboard'
import Settings from './Settings'
import OrderIndex from './OrderIndex'
import OrderShipments from './OrderShipments'
import OrderDetail from './OrderDetail'
import ManualPaymentProofs from './ManualPaymentProofs'
import OrderReturns from './OrderReturns'
import PaymentDisputes from './PaymentDisputes'
import AllocationOverrideLogs from './AllocationOverrideLogs'
import FinanceReports from './FinanceReports'
const Pages = {
    Dashboard: Object.assign(Dashboard, Dashboard),
Settings: Object.assign(Settings, Settings),
OrderIndex: Object.assign(OrderIndex, OrderIndex),
OrderShipments: Object.assign(OrderShipments, OrderShipments),
OrderDetail: Object.assign(OrderDetail, OrderDetail),
ManualPaymentProofs: Object.assign(ManualPaymentProofs, ManualPaymentProofs),
OrderReturns: Object.assign(OrderReturns, OrderReturns),
PaymentDisputes: Object.assign(PaymentDisputes, PaymentDisputes),
AllocationOverrideLogs: Object.assign(AllocationOverrideLogs, AllocationOverrideLogs),
FinanceReports: Object.assign(FinanceReports, FinanceReports),
}

export default Pages