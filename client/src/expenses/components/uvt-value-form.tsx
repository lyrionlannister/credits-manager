import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Spinner } from '@ui/spinner/components/spinner';
import type { UvtValueFormProps } from '../props/uvt-value-form-props';
import type { ApiUvtValue } from '../types/api-uvt-value';

export const UvtValueForm: FC<UvtValueFormProps> = ({ valorUvtId, initialData, onSaved }) => {
    const { sendRequest } = useSendRequest();
    const isEditMode = valorUvtId !== undefined;

    const [fechaDesde, setFechaDesde] = useState(() => initialData?.fecha_desde ?? new Date().toISOString().slice(0, 10));
    const [valor, setValor] = useState(initialData?.valor ?? '');
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            const respuesta = isEditMode
                ? await sendRequest<{ valor_uvt: ApiUvtValue }>(`/gastos/valores-uvt/${valorUvtId}`, {
                      method: 'PATCH',
                      body: { valor: parseFloat(valor) || 0 },
                  })
                : await sendRequest<{ valor_uvt: ApiUvtValue }>('/gastos/valores-uvt', {
                      method: 'POST',
                      body: { fecha_desde: fechaDesde, valor: parseFloat(valor) || 0 },
                  });

            onSaved(respuesta.valor_uvt);

            if (!isEditMode) {
                setValor('');
            }
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <div className="space-y-5">
                {error && (
                    <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                        {error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div>
                        <Label htmlFor="fecha-desde">Vigente desde</Label>
                        <DatePicker id="fecha-desde" name="fecha-desde" required disabled={isEditMode} value={fechaDesde} onChange={setFechaDesde} />
                    </div>

                    <div className="md:col-span-2">
                        <Label htmlFor="valor">Valor UVT</Label>
                        <Input
                            id="valor"
                            name="valor"
                            type="number"
                            step={0.01}
                            required
                            value={valor}
                            onChange={(event) => setValor(event.target.value)}
                        />
                    </div>
                </div>

                <div className="flex justify-end border-t border-gray-100 pt-6 dark:border-gray-800">
                    <Button type="submit" disabled={isSubmitting} startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}>
                        {isSubmitting ? 'Guardando…' : isEditMode ? 'Actualizar valor UVT' : 'Guardar valor UVT'}
                    </Button>
                </div>
            </div>
        </form>
    );
};
