import PrintShipmentLabelController from './PrintShipmentLabelController'
import OverrideAllocationController from './OverrideAllocationController'
import WarehouseDocsController from './WarehouseDocsController'
import FinanceExportController from './FinanceExportController'
import OrderFileController from './OrderFileController'
const Cpanel = {
    PrintShipmentLabelController: Object.assign(PrintShipmentLabelController, PrintShipmentLabelController),
OverrideAllocationController: Object.assign(OverrideAllocationController, OverrideAllocationController),
WarehouseDocsController: Object.assign(WarehouseDocsController, WarehouseDocsController),
FinanceExportController: Object.assign(FinanceExportController, FinanceExportController),
OrderFileController: Object.assign(OrderFileController, OrderFileController),
}

export default Cpanel