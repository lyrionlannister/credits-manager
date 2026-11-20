import type { ApiCredit } from '../types/api-credit';

/** EA con la que se pactó el crédito; los anteriores a la EA solo tienen la mensual, así que se deriva de ella. */
export function annualRateOfCredit(credito: Pick<ApiCredit, 'tasa_efectiva_anual' | 'tasa_interes'>): string {
    return credito.tasa_efectiva_anual ?? (((1 + Number(credito.tasa_interes) / 100) ** 12 - 1) * 100).toFixed(4);
}
