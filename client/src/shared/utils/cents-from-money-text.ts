/** Convierte un string decimal de 2 cifras (ej. "1234.56", el formato que siempre emite `Dinero`) a centavos, sin pasar por float. */
export function centsFromMoneyText(valor: string): bigint {
    const negativo = valor.startsWith('-');
    const [entero, decimal = '00'] = valor.replace('-', '').split('.');
    const centavos = BigInt(entero) * 100n + BigInt(decimal.padEnd(2, '0').slice(0, 2));

    return negativo ? -centavos : centavos;
}
