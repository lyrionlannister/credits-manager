/** Raw shape of a row returned by `GET /gastos/valores-uvt` and `POST /gastos/valores-uvt`. */
export type ApiUvtValue = {
    id: number;
    fecha_desde: string;
    fecha_hasta: string | null;
    valor: string;
};
