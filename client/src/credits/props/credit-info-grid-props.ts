import type { ApiClient } from '../../clients/types/api-client';
import type { CreditStatus } from '../types/credit-status';
import type { PaymentType } from '../types/payment-type';

export type CreditInfoGridProps = {
    credito: {
        cliente?: ApiClient;
        tasa_interes: string;
        plazo: number;
        tipo_pago: PaymentType;
        fecha_desembolso: string;
        estado: CreditStatus;
        credito_origen?: { id: number } | null;
    };
};
