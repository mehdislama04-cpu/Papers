import { createContext, use, useCallback, useMemo, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api, { ApiError, type Envelope } from './api';
import { clearQueue } from './queue';
import { purgeAppCaches } from './pwa';
import type { User } from './types';

export interface Credentials {
    email: string;
    password: string;
}

export interface Registration extends Credentials {
    name: string;
    password_confirmation: string;
}

export interface AuthContextValue {
    user: User | null;
    isLoading: boolean;
    isAuthenticated: boolean;
    login: (credentials: Credentials) => Promise<User>;
    register: (payload: Registration) => Promise<User>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export const ME_QUERY_KEY = ['auth', 'me'] as const;

async function fetchMe(): Promise<User | null> {
    try {
        const body = await api.get<Envelope<User>>('/me');
        return body.data;
    } catch (error) {
        // 401 n'est pas une erreur ici : c'est simplement « pas connecte ».
        if (error instanceof ApiError && error.isUnauthenticated) return null;
        throw error;
    }
}

export function AuthProvider({ children }: { children: ReactNode }) {
    const queryClient = useQueryClient();

    const { data, isPending } = useQuery({
        queryKey: ME_QUERY_KEY,
        queryFn: fetchMe,
        staleTime: 5 * 60_000,
        retry: false,
    });

    const user = data ?? null;

    const login = useCallback(
        async (credentials: Credentials) => {
            const body = await api.post<Envelope<User>>('/login', credentials);
            queryClient.setQueryData(ME_QUERY_KEY, body.data);
            await queryClient.invalidateQueries();
            return body.data;
        },
        [queryClient],
    );

    const register = useCallback(
        async (payload: Registration) => {
            const body = await api.post<Envelope<User>>('/register', payload);
            queryClient.setQueryData(ME_QUERY_KEY, body.data);
            await queryClient.invalidateQueries();
            return body.data;
        },
        [queryClient],
    );

    const logout = useCallback(async () => {
        try {
            await api.post('/logout');
        } catch (error) {
            // Une session deja expiree cote serveur ne doit pas bloquer la
            // deconnexion locale.
            if (!(error instanceof ApiError)) throw error;
        }
        queryClient.setQueryData(ME_QUERY_KEY, null);
        queryClient.clear();
        // Les documents en attente appartiennent au compte qui part.
        await clearQueue();
        await purgeAppCaches();
    }, [queryClient]);

    const refresh = useCallback(async () => {
        await queryClient.invalidateQueries({ queryKey: ME_QUERY_KEY });
    }, [queryClient]);

    const value = useMemo<AuthContextValue>(
        () => ({
            user,
            isLoading: isPending,
            isAuthenticated: user !== null,
            login,
            register,
            logout,
            refresh,
        }),
        [user, isPending, login, register, logout, refresh],
    );

    return <AuthContext value={value}>{children}</AuthContext>;
}

export function useAuth(): AuthContextValue {
    const context = use(AuthContext);
    if (!context) {
        throw new Error('useAuth doit etre utilise a l’interieur de <AuthProvider>.');
    }
    return context;
}
