import type { ApiClient } from '../../clients/types/api-client';
import type { ApiInstallment } from './api-installment';
import type { CreditStatus } from './credit-status';
import type { CreditType } from './credit-type';
import type { PaymentType } from './payment-type';

/** Raw shape returned by `GET /creditos` (rows), `GET /creditos/{id}` and `POST /creditos`. */
export type ApiCredit = {
    id: number;
    cliente_id: number;
    cliente?: ApiClient;
    tipo_credito: CreditType;
    credito_origen_id: number | null;
    credito_origen?: ApiCredit | null;
    valor_prestamo: string;
    /** Solo en refinanciaciones: saldo de capital tomado del original. Decimal string; nulo si no aplica o es anterior a la columna. */
    saldo_refinanciado: string | null;
    /** Tasa mensual (%) con la que se calculan las cuotas; si hay EA, derivada de ella. Decimal string (ej. "2.117600"). */
    tasa_interes: string;
    /** Tasa efectiva anual (%) pactada. Decimal string; nula en créditos anteriores a la EA. */
    tasa_efectiva_anual: string | null;
    /** Porcentaje de otros gastos de administración sobre el capital inicial. Decimal string (ej. "0.200"). */
    gastos_administracion_pct: string;
    plazo: number;
    tipo_pago: PaymentType;
    valor_cuota: string;
    /** Cuota fija más otros gastos (lo que se cobra cada mes). Decimal string calculado por el servidor. */
    valor_cuota_total: string;
    /** Total a pagar del plan más los otros gastos de todas las cuotas. Decimal string calculado por el servidor. */
    valor_total_con_otros: string;
    valor_total: string;
    fecha_desembolso: string;
    desembolsado_en: string | null;
    estado: CreditStatus;
    creado_en: string | null;
    /** Solo presente en el listado (`GET /creditos`). */
    cuotas_vencidas?: number;
    /** Solo presente en el listado (`GET /creditos`). */
    pagos_count?: number;
    /** Decimal string en pesos: lo que queda pendiente de sus cuotas. Solo presente en el listado (`GET /creditos`). */
    saldo?: string;
    /** Solo presente en el detalle (`GET /creditos/{id}`). */
    cuotas?: ApiInstallment[];
};
