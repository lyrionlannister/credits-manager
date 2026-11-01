import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Eye, EyeOff, KeyRound } from 'lucide-react';
import { useAuth } from '../hooks/use-auth';
import type { WithChildrenProps } from '../../shared/props/with-children-props';

export const RequirePasswordChange: FC<WithChildrenProps> = ({ children }) => {
    const { user, logout, refreshUser } = useAuth();
    const { sendRequest } = useSendRequest();

    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [showPasswords, setShowPasswords] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    if (!user?.mustChangePassword) {
        return <>{children}</>;
    }

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);

        if (newPassword !== confirmPassword) {
            setError('Las contraseñas no coinciden.');
            return;
        }

        setIsSubmitting(true);

        try {
            await sendRequest('/me/clave', { method: 'PATCH', body: { clave_actual: currentPassword, clave_nueva: newPassword } });
            await refreshUser();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-900">
            <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-theme-sm dark:border-gray-800 dark:bg-gray-900">
                <h1 className="text-title-sm font-semibold text-gray-800 dark:text-white/90">Cambia tu contraseña</h1>
                <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Tu contraseña es temporal. Define una nueva para continuar.
                </p>

                <form onSubmit={handleSubmit} className="mt-6 space-y-5">
                    {error && (
                        <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                            {error}
                        </div>
                    )}

                    <div>
                        <Label htmlFor="current-password">Contraseña temporal</Label>
                        <Input
                            id="current-password"
                            name="current-password"
                            autoComplete="current-password"
                            type={showPasswords ? 'text' : 'password'}
                            startIcon={<KeyRound className="size-4" />}
                            endIcon={
                                <button
                                    type="button"
                                    onClick={() => setShowPasswords(!showPasswords)}
                                    aria-label={showPasswords ? 'Ocultar contraseñas' : 'Mostrar contraseñas'}
                                    className="cursor-pointer text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                                >
                                    {showPasswords ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                                </button>
                            }
                            value={currentPassword}
                            onChange={(event) => setCurrentPassword(event.target.value)}
                        />
                    </div>

                    <div>
                        <Label htmlFor="new-password">Nueva contraseña</Label>
                        <Input
                            id="new-password"
                            name="new-password"
                            autoComplete="new-password"
                            type={showPasswords ? 'text' : 'password'}
                            startIcon={<KeyRound className="size-4" />}
                            minLength={8}
                            hint="Mínimo 8 caracteres, con letras, números y un carácter especial."
                            value={newPassword}
                            onChange={(event) => setNewPassword(event.target.value)}
                        />
                    </div>

                    <div>
                        <Label htmlFor="confirm-new-password">Confirmar contraseña</Label>
                        <Input
                            id="confirm-new-password"
                            name="confirm-new-password"
                            autoComplete="new-password"
                            type={showPasswords ? 'text' : 'password'}
                            startIcon={<KeyRound className="size-4" />}
                            minLength={8}
                            value={confirmPassword}
                            onChange={(event) => setConfirmPassword(event.target.value)}
                        />
                    </div>

                    <div className="flex items-center justify-between gap-3 pt-2">
                        <Button variant="outline" type="button" onClick={() => void logout()}>
                            Cerrar sesión
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Guardando…' : 'Guardar y continuar'}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
};
