import General from './General'
import PaymentMethods from './PaymentMethods'
import Carriers from './Carriers'
import TeamIndex from './TeamIndex'
import TeamRoles from './TeamRoles'
const Settings = {
    General: Object.assign(General, General),
PaymentMethods: Object.assign(PaymentMethods, PaymentMethods),
Carriers: Object.assign(Carriers, Carriers),
TeamIndex: Object.assign(TeamIndex, TeamIndex),
TeamRoles: Object.assign(TeamRoles, TeamRoles),
}

export default Settings