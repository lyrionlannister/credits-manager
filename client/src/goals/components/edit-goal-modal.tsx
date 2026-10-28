import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Modal } from '@ui/modal/components/modal';
import { Spinner } from '@ui/spinner/components/spinner';
import { GOAL_TYPE_LABELS } from '../constants/goal-status';
import type { EditGoalModalProps } from '../props/edit-goal-modal-props';

export const EditGoalModal: FC<EditGoalModalProps> = ({ meta, periodo, onClose, onSaved }) => {
    const { sendRequest } = useSendRequest();

    const [valor, setValor] = useState(meta.valor_objetivo);
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            await sendRequest(`/metas/${meta.id}`, { method: 'PUT', body: { valor_objetivo: valor } });

            onSaved();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Modal isOpen onClose={onClose} size="sm">
            <h4 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Editar meta</h4>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                {GOAL_TYPE_LABELS[meta.tipo]} · {periodo}
            </p>

            <form onSubmit={handleSubmit}>
                <div className="space-y-5">
                    {error && (
                        <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                            {error}
                        </div>
                    )}

                    <div>
                        <Label htmlFor="editar-meta-valor">Valor objetivo</Label>
                        <Input
                            id="editar-meta-valor"
                            name="valor_objetivo"
                            type="number"
                            step={0.01}
                            min="0.01"
                            required
                            value={valor}
                            onChange={(event) => setValor(event.target.value)}
                        />
                    </div>

                    <div className="flex flex-col-reverse gap-3 border-t border-gray-100 pt-5 sm:flex-row sm:justify-end dark:border-gray-800">
                        <Button variant="outline" type="button" onClick={onClose} disabled={isSubmitting}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={isSubmitting} startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}>
                            {isSubmitting ? 'Guardando…' : 'Guardar cambios'}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>
    );
};
