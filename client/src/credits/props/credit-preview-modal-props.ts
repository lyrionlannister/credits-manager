import type { ApiClient } from '../../clients/types/api-client';
import type { ApiCreditPreview } from '../types/api-credit-preview';
import type { PaymentType } from '../types/payment-type';

export type CreditPreviewModalProps = {
    preview: ApiCreditPreview;
    cliente: ApiClient;
    creditoOrigenId: number | null;
    tasaInteres: string;
    plazo: number;
    tipoPago: PaymentType;
    fechaDesembolso: string;
    isCreating: boolean;
    onConfirm: () => void;
    onClose: () => void;
};
