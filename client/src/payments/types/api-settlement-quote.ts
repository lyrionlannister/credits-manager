/** Raw shape returned by `GET/POST /creditos/{id}/liquidacion`. */
export type ApiSettlementQuote = {
    /** Decimal string (ej. "12345.66"), no number — viene de `App\Core\Dinero\Dinero`. */
    capital_pendiente: string;
    dias_transcurridos: number;
    tasa_diaria: number;
    /** Decimal string (ej. "12345.66"), no number — viene de `App\Core\Dinero\Dinero`. */
    interes: string;
    /** Otros gastos de administración devengados o vencidos a la fecha. Decimal string. */
    otros_gastos: string;
    /** Decimal string (ej. "12345.66"), no number — viene de `App\Core\Dinero\Dinero`. */
    total: string;
    fecha_referencia: string;
    /** Fecha hasta la que se cuentan los días (`Y-m-d`): la `fecha_pago` enviada, o hoy si no se envió. */
    fecha_pago: string;
};
