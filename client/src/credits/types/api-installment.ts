import type { InstallmentStatus } from './installment-status';

/** Raw shape of a `cuotas` row nested in `ApiCredit` (`GET /creditos/{id}` and related endpoints). */
export type ApiInstallment = {
    id: number;
    credito_id: number;
    numero_cuota: number;
    fecha_vencimiento: string;
    valor: string;
    valor_interes: string;
    /** Otros gastos de administración de la cuota. Decimal string. */
    valor_otros_gastos: string;
    /** Total exigible de la cuota: capital + interés + otros gastos. Decimal string calculado por el servidor. */
    valor_total_cuota: string;
    valor_capital: string;
    saldo_capital: string;
    valor_pagado: string;
    estado: InstallmentStatus;
    /** Fecha real del último pago aplicado (`Y-m-d`). Solo la trae `GET /creditos/{id}/estado-cuenta`. */
    fecha_pago?: string | null;
};
