/** Inverso de `centsFromMoneyText`. */
export function moneyTextFromCents(centavos: bigint): string {
    const negativo = centavos < 0n;
    const absolutos = negativo ? -centavos : centavos;

    return `${negativo ? '-' : ''}${absolutos / 100n}.${(absolutos % 100n).toString().padStart(2, '0')}`;
}
