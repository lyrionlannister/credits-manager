import type { FC } from 'react';
import { useEffect, useRef } from 'react';

import { X } from 'lucide-react';
import { MODAL_SIZE_CLASSES } from '../constants/modal-size-classes';
import type { ModalProps } from '../props/modal-props';

export const Modal: FC<ModalProps> = ({
    isOpen,
    onClose,
    size = 'md',
    showCloseButton = true,
    className = '',
    children,
}) => {
    const modalRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const handleEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', handleEscape);
        return () => document.removeEventListener('keydown', handleEscape);
    }, [isOpen, onClose]);

    useEffect(() => {
        document.body.style.overflow = isOpen ? 'hidden' : 'unset';
        return () => {
            document.body.style.overflow = 'unset';
        };
    }, [isOpen]);

    if (!isOpen) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-99999 flex justify-center overflow-y-auto p-4 print:static print:block print:h-auto print:overflow-visible print:p-0">
            <div
                className="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[32px] print:hidden dark:bg-gray-900/50"
                onClick={onClose}
            />

            <div
                ref={modalRef}
                onClick={(event) => event.stopPropagation()}
                // `my-auto` centra verticalmente cuando cabe, sin el recorte de `items-center` cuando el contenido es más alto que el viewport.
                className={`relative my-auto w-full rounded-3xl bg-white p-5 dark:bg-gray-900 sm:p-8 print:max-w-full print:rounded-none print:bg-white print:p-0 print:shadow-none ${MODAL_SIZE_CLASSES[size]} ${className}`}
            >
                {showCloseButton && (
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="absolute top-3 right-3 flex h-9.5 w-9.5 items-center justify-center rounded-full bg-gray-100 text-gray-400 transition-colors hover:bg-gray-200 hover:text-gray-700 sm:top-6 sm:right-6 sm:h-11 sm:w-11 print:hidden dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    >
                        <X className="size-5 fill-current" />
                    </button>
                )}
                {children}
            </div>
        </div>
    );
};
