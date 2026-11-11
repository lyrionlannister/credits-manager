import type { ApiPayment } from '../../payments/types/api-payment';
import type { ApiCredit } from './api-credit';
import type { ApiRefinancingLink } from './api-refinancing-link';
import type { ApiResumenCuotas } from './api-resumen-cuotas';

/** Raw shape returned by `GET /creditos/{id}`. */
export type ApiCreditDetail = {
    credito: ApiCredit;
    /** Decimal string (ej. "12345.66"), no number — viene de `App\Core\Dinero\Dinero`. */
    saldo: string;
    cartera: 'En mora' | 'Al día' | 'Refinanciado';
    /** Lo que falta de las cuotas vencidas y los días desde la primera vencida; sin interés de mora. */
    mora: {
        /** Decimal string. */
        monto_vencido: string;
        cuotas_vencidas: number;
        dias: number;
    };
    refinanciacion: ApiRefinancingLink | null;
    pagos: ApiPayment[];
    resumen_cuotas: ApiResumenCuotas;
};
