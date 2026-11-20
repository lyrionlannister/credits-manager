/** Tasa mensual (%) equivalente a una efectiva anual (%), con la misma fórmula del servidor. Solo para mostrar: el servidor la recalcula. */
export function monthlyRateFromAnnual(efectivaAnual: string): string | null {
    const valor = Number(efectivaAnual);

    return efectivaAnual.trim() === '' || Number.isNaN(valor) ? null : (((1 + valor / 100) ** (1 / 12) - 1) * 100).toFixed(4);
}
