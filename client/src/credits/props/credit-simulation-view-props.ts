import type { ApiClient } from '../../clients/types/api-client';
import type { ApiCreditPreview } from '../types/api-credit-preview';
import type { PaymentType } from '../types/payment-type';

export type CreditSimulationViewProps = {
    preview: ApiCreditPreview;
    cliente: ApiClient;
    creditoOrigenId: number | null;
    tasaInteres: string;
    tasaEfectivaAnual: string;
    plazo: number;
    tipoPago: PaymentType;
    fechaDesembolso: string;
    /** Los mismos datos con que se previsualizó: el servidor los recalcula y los imprime como "Simulación de crédito". */
    pdfPayload?: Record<string, unknown>;
    /** `editar` cambia los textos y el botón de confirmar: las condiciones nuevas reemplazan las cuotas de un crédito existente. */
    modo?: 'crear' | 'editar';
    isCreating: boolean;
    onConfirm: () => void;
    onBack: () => void;
};
