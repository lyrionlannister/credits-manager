/** Cuota calculada por `POST /creditos/previsualizar`: mismas columnas que `ApiInstallment`, sin persistir (sin `id` ni `estado`). */
export type ApiPreviewInstallment = {
    numero_cuota: number;
    fecha_vencimiento: string;
    valor: string;
    valor_interes: string;
    valor_otros_gastos: string;
    valor_total_cuota: string;
    valor_capital: string;
    saldo_capital: string;
};

/** Respuesta de `POST /creditos/previsualizar`: el mismo cálculo que crearía el crédito, sin tocar la base de datos. */
export type ApiCreditPreview = {
    valor_prestamo: string;
    saldo_refinanciado: string | null;
    valor_cuota: string;
    valor_total: string;
    valor_total_con_otros: string;
    /** Tasa mensual (%) usada en el cálculo, derivada de la EA. Decimal string. */
    tasa_interes: string;
    interes_total: string;
    cuotas: ApiPreviewInstallment[];
};
