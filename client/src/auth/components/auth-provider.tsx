import type { FC } from 'react';
import { useCallback, useEffect, useState } from 'react';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { SESSION_EXPIRED_EVENT } from '../../shared/constants/auth-events';
import { clearSessionCookies } from '../../shared/utils/clear-session-cookies';
import { AuthContext } from '../hooks/use-auth';
import { mapUser } from '../utils/map-user';
import type { ApiUser } from '../types/api-user';
import type { User } from '../types/user';
import type { WithChildrenProps } from '../../shared/props/with-children-props';

export const AuthProvider: FC<WithChildrenProps> = ({ children }) => {
    const [user, setUser] = useState<User | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const { sendRequest } = useSendRequest();

    const refreshUser = useCallback(
        () =>
            sendRequest<{ usuario: ApiUser }>('/me')
                .then((data) => setUser(mapUser(data.usuario)))
                .catch(() => setUser(null)),
        [sendRequest],
    );

    useEffect(() => {
        refreshUser().finally(() => setIsLoading(false));
    }, [refreshUser]);

    useEffect(() => {
        const handleSessionExpired = () => {
            clearSessionCookies();
            setUser(null);
        };

        window.addEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
        return () => window.removeEventListener(SESSION_EXPIRED_EVENT, handleSessionExpired);
    }, []);

    const login = useCallback(
        async (username: string, password: string) => {
            const data = await sendRequest<{ usuario: ApiUser }>('/login', {
                method: 'POST',
                body: { usuario: username, clave: password },
            });

            setUser(mapUser(data.usuario));
        },
        [sendRequest],
    );

    const logout = useCallback(async () => {
        try {
            await sendRequest('/logout', { method: 'POST' });
        } catch {
            // Best-effort: si el servidor ya no reconoce la sesión (401) o la red falla,
            // igual cerramos sesión localmente — no hay nada más que el backend pueda hacer.
        } finally {
            clearSessionCookies();
            setUser(null);
        }
    }, [sendRequest]);

    return <AuthContext.Provider value={{ user, isLoading, login, logout, refreshUser }}>{children}</AuthContext.Provider>;
};
