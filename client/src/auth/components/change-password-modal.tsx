import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Modal } from '@ui/modal/components/modal';
import { Spinner } from '@ui/spinner/components/spinner';
import { Eye, EyeOff, KeyRound } from 'lucide-react';
import type { ChangePasswordModalProps } from '../props/change-password-modal-props';

export const ChangePasswordModal: FC<ChangePasswordModalProps> = ({ user, onClose, onSuccess }) => {
    const { sendRequest } = useSendRequest();

    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);

        if (password !== confirmPassword) {
            setError('Las contraseñas no coinciden.');
            return;
        }

        setIsSubmitting(true);

        try {
            await sendRequest(`/usuarios/${user.id}/clave`, { method: 'PATCH', body: { clave: password } });
            onSuccess();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Modal isOpen onClose={onClose} size="sm">
            <h4 className="mb-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                Cambiar contraseña de {user.nombre}
            </h4>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Es temporal: {user.nombre} deberá cambiarla al iniciar sesión.
            </p>

            <form onSubmit={handleSubmit}>
                <div className="space-y-5">
                    {error && (
                        <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                            {error}
                        </div>
                    )}

                    <div>
                        <Label htmlFor="new-password">Nueva contraseña</Label>
                        <Input
                            id="new-password"
                            name="new-password"
                            autoComplete="new-password"
                            type={showPassword ? 'text' : 'password'}
                            startIcon={<KeyRound className="size-4" />}
                            endIcon={
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                                    className="cursor-pointer text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                >
                                    {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            }
                            minLength={8}
                            hint="Mínimo 8 caracteres, con letras, números y un carácter especial."
                            value={password}
                            onChange={(event) => setPassword(event.target.value)}
                        />
                    </div>

                    <div>
                        <Label htmlFor="confirm-new-password">Confirmar contraseña</Label>
                        <Input
                            id="confirm-new-password"
                            name="confirm-new-password"
                            autoComplete="new-password"
                            type={showPassword ? 'text' : 'password'}
                            startIcon={<KeyRound className="size-4" />}
                            minLength={8}
                            value={confirmPassword}
                            onChange={(event) => setConfirmPassword(event.target.value)}
                        />
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <Button variant="outline" type="button" onClick={onClose} disabled={isSubmitting}>
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={isSubmitting}
                            startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}
                        >
                            {isSubmitting ? 'Guardando…' : 'Guardar'}
                        </Button>
                    </div>
                </div>
            </form>
        </Modal>
    );
};
