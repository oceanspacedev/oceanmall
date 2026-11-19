import Komerce from './Komerce'
import Xendit from './Xendit'
import Midtrans from './Midtrans'
import Biteship from './Biteship'
import KiriminAja from './KiriminAja'
const Integrations = {
    Komerce: Object.assign(Komerce, Komerce),
Xendit: Object.assign(Xendit, Xendit),
Midtrans: Object.assign(Midtrans, Midtrans),
Biteship: Object.assign(Biteship, Biteship),
KiriminAja: Object.assign(KiriminAja, KiriminAja),
}

export default Integrations