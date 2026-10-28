import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';

import { Ban, CheckCircle2, Hash, RotateCcw, Trash2 } from 'lucide-react';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { TextArea } from '@ui/forms/components/text-area';
import { Modal } from '@ui/modal/components/modal';
import { Spinner } from '@ui/spinner/components/spinner';
import type { ApiTaxObligationStatus } from '../types/api-tax-obligation';
import type { TaxObligationEventModalProps } from '../props/tax-obligation-event-modal-props';

const ESTADO_LABELS: Record<ApiTaxObligationStatus, string> = {
    pendiente: 'Pendiente',
    cumplida: 'Cumplida',
    no_aplica: 'No aplica',
};

/** El formulario solo se monta mientras el modal está abierto: así el `useState` de cada campo arranca de nuevo entre un evento y otro, en vez de arrastrar el valor anterior. */
export const TaxObligationEventModal: FC<TaxObligationEventModalProps> = ({ isOpen, onClose, ...props }) => (
    <Modal isOpen={isOpen} onClose={onClose} size="md">
        {isOpen && <TaxObligationEventForm onClose={onClose} {...props} />}
    </Modal>
);

type TaxObligationEventFormProps = Omit<TaxObligationEventModalProps, 'isOpen'>;

const TaxObligationEventForm: FC<TaxObligationEventFormProps> = ({
    obligacion,
    fechaInicial,
    tipos,
    responsables,
    canEdit,
    canChangeStatus,
    canDelete,
    onClose,
    onSaved,
    onDelete,
}) => {
    const { sendRequest } = useSendRequest();
    const isEditMode = obligacion !== null;
    const isReadOnly = isEditMode && !canEdit;

    const [tipoObligacionId, setTipoObligacionId] = useState('');
    const [periodo, setPeriodo] = useState(obligacion?.periodo ?? '');
    const [fechaLimite, setFechaLimite] = useState(obligacion?.fecha_limite ?? fechaInicial ?? '');
    const [responsableId, setResponsableId] = useState(obligacion?.responsable ? String(obligacion.responsable.id) : '');
    const [observaciones, setObservaciones] = useState(obligacion?.observaciones ?? '');
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [estadoEnCurso, setEstadoEnCurso] = useState<ApiTaxObligationStatus | null>(null);

    const responsableOptions = responsables.map((responsable) => ({ value: String(responsable.id), label: responsable.nombre }));
    const tipoOptions = tipos.map((tipo) => ({ value: String(tipo.id), label: tipo.nombre }));

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);

        if (!isEditMode && tipoObligacionId === '') {
            setError('Selecciona el tipo de obligación.');
            return;
        }

        setIsSubmitting(true);

        const body = {
            fecha_limite: fechaLimite,
            responsable_id: responsableId === '' ? null : Number(responsableId),
            observaciones: observaciones.trim() === '' ? null : observaciones.trim(),
        };

        try {
            if (isEditMode) {
                await sendRequest(`/obligaciones-tributarias/${obligacion.id}`, { method: 'PUT', body });
            } else {
                await sendRequest('/obligaciones-tributarias', {
                    method: 'POST',
                    body: { ...body, tipo_obligacion_id: Number(tipoObligacionId), periodo },
                });
            }

            onSaved();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    const cambiarEstado = async (estado: ApiTaxObligationStatus) => {
        if (!obligacion) {
            return;
        }

        setError(null);
        setEstadoEnCurso(estado);

        try {
            await sendRequest(`/obligaciones-tributarias/${obligacion.id}/estado`, { method: 'PUT', body: { estado } });
            onSaved();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setEstadoEnCurso(null);
        }
    };

    return (
        <>
            <h4 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                {isReadOnly ? 'Obligación tributaria' : isEditMode ? 'Editar obligación tributaria' : 'Nueva obligación tributaria'}
            </h4>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                {isReadOnly
                    ? 'Detalle de la obligación programada.'
                    : isEditMode
                      ? 'Ajusta la fecha, el responsable o las observaciones.'
                      : 'Programa una obligación en el calendario tributario.'}
            </p>

            <form onSubmit={handleSubmit}>
                <div className="space-y-5">
                    {error && (
                        <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                            {error}
                        </div>
                    )}

                    {isEditMode ? (
                        <div>
                            <Label htmlFor="evento-tipo">Tipo de obligación</Label>
                            <p id="evento-tipo" className="text-sm text-gray-700 dark:text-gray-300">
                                {obligacion.tipo_obligacion.nombre}
                            </p>
                        </div>
                    ) : (
                        <div>
                            <Label htmlFor="evento-tipo">Tipo de obligación</Label>
                            <Select id="evento-tipo" options={tipoOptions} placeholder="Selecciona el tipo" onChange={setTipoObligacionId} />
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="evento-periodo">Período</Label>
                            {isEditMode ? (
                                <p id="evento-periodo" className="text-sm text-gray-700 dark:text-gray-300">
                                    {obligacion.periodo}
                                </p>
                            ) : (
                                <Input
                                    id="evento-periodo"
                                    placeholder="Ej. 2026-10"
                                    required
                                    maxLength={20}
                                    startIcon={<Hash className="size-4" />}
                                    value={periodo}
                                    onChange={(event) => setPeriodo(event.target.value)}
                                />
                            )}
                        </div>

                        <div>
                            <Label htmlFor="evento-fecha-limite">Fecha límite</Label>
                            <DatePicker id="evento-fecha-limite" value={fechaLimite} onChange={setFechaLimite} required disabled={isReadOnly} />
                        </div>
                    </div>

                    <div>
                        <Label htmlFor="evento-responsable">Responsable</Label>
                        <Select
                            id="evento-responsable"
                            options={responsableOptions}
                            placeholder="Sin responsable asignado"
                            placeholderSelectable
                            defaultValue={responsableId}
                            onChange={setResponsableId}
                            disabled={isReadOnly}
                        />
                    </div>

                    <div>
                        <Label htmlFor="evento-observaciones">Observaciones</Label>
                        <TextArea
                            id="evento-observaciones"
                            rows={3}
                            maxLength={500}
                            disabled={isReadOnly}
                            value={observaciones}
                            onChange={(event) => setObservaciones(event.target.value)}
                        />
                    </div>

                    {isEditMode && canChangeStatus && (
                        <div className="border-t border-gray-100 pt-5 dark:border-gray-800">
                            <p className="mb-3 text-xs font-medium tracking-wide text-gray-400 uppercase dark:text-gray-500">
                                Estado actual: {ESTADO_LABELS[obligacion.estado]}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={obligacion.estado === 'cumplida' || estadoEnCurso !== null}
                                    startIcon={<CheckCircle2 className="size-4" />}
                                    onClick={() => void cambiarEstado('cumplida')}
                                >
                                    Marcar cumplida
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={obligacion.estado === 'pendiente' || estadoEnCurso !== null}
                                    startIcon={<RotateCcw className="size-4" />}
                                    onClick={() => void cambiarEstado('pendiente')}
                                >
                                    Marcar pendiente
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={obligacion.estado === 'no_aplica' || estadoEnCurso !== null}
                                    startIcon={<Ban className="size-4" />}
                                    onClick={() => void cambiarEstado('no_aplica')}
                                >
                                    No aplica
                                </Button>
                            </div>
                        </div>
                    )}

                    <div className="flex flex-col-reverse gap-3 border-t border-gray-100 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                        <div>
                            {isEditMode && canDelete && obligacion.estado !== 'cumplida' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={isSubmitting}
                                    startIcon={<Trash2 className="size-4" />}
                                    className="w-full sm:w-auto"
                                    onClick={() => onDelete(obligacion)}
                                >
                                    Eliminar
                                </Button>
                            )}
                        </div>
                        <div className="flex flex-col-reverse gap-3 sm:flex-row">
                            <Button variant="outline" type="button" onClick={onClose} disabled={isSubmitting}>
                                {isReadOnly ? 'Cerrar' : 'Cancelar'}
                            </Button>
                            {!isReadOnly && (
                                <Button type="submit" disabled={isSubmitting} startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}>
                                    {isSubmitting ? 'Guardando…' : isEditMode ? 'Guardar cambios' : 'Crear obligación'}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
};
