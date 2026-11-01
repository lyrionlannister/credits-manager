import type { FC } from 'react';
import { useState } from 'react';
import { Check, Copy } from 'lucide-react';

import { Modal } from '@ui/modal/components/modal';
import type { TemporaryPasswordModalProps } from '../props/temporary-password-modal-props';

export const TemporaryPasswordModal: FC<TemporaryPasswordModalProps> = ({ adminName, password, onClose }) => {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        if (!password) {
            return;
        }

        try {
            await navigator.clipboard.writeText(password);
            setCopied(true);
        } catch {
            // Sin permiso de portapapeles: el admin igual puede seleccionar el texto a mano.
        }
    };

    return (
        <Modal isOpen={password !== null} onClose={() => { setCopied(false); onClose(); }} size="sm">
            {password && (
                <div className="flex flex-col gap-5 pr-10">
                    <div>
                        <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Contraseña temporal de {adminName}</h3>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Se muestra una sola vez. Compártela por un canal seguro — deberá cambiarla al iniciar sesión.
                        </p>
                    </div>

                    <div className="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-white/5">
                        <code className="text-sm font-medium wrap-break-word text-gray-800 dark:text-white/90">{password}</code>
                        <button
                            type="button"
                            onClick={() => void copy()}
                            aria-label="Copiar contraseña"
                            className="shrink-0 cursor-pointer text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                        >
                            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                        </button>
                    </div>
                </div>
            )}
        </Modal>
    );
};
