import type { ApiClient } from '../../clients/types/api-client';
import type { ApiPreviewInstallment } from '../types/api-credit-preview';
import type { PaymentType } from '../types/payment-type';

export type CreditConditionsCardProps = {
    cliente: ApiClient;
    creditoOrigenId: number | null;
    tasaInteres: string;
    /** Vacío en créditos anteriores a la EA. */
    tasaEfectivaAnual: string;
    plazo: number;
    tipoPago: PaymentType;
    fechaDesembolso: string;
    /** Sirve tanto la cuota simulada como la persistida: solo se leen las fechas de la primera y la última. */
    cuotas: Pick<ApiPreviewInstallment, 'fecha_vencimiento'>[];
};
