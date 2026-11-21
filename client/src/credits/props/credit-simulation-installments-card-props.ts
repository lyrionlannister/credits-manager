import type { ApiPreviewInstallment } from '../types/api-credit-preview';
import type { PaymentType } from '../types/payment-type';

export type CreditSimulationInstallmentsCardProps = {
    cuotas: ApiPreviewInstallment[];
    tipoPago: PaymentType;
};
