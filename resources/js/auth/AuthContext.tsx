import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, ApiError } from '@/lib/api';
import type { User } from './types';

interface RegisterInput {
    name: string;
    email: string;
    phone?: string;
    password: string;
    password_confirmation: string;
    accept_privacy_notice: boolean;
}

interface AuthState {
    user: User | null;
    loading: boolean;
    login: (email: string, password: string) => Promise<User>;
    register: (input: RegisterInput) => Promise<User>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
    can: (permission: string) => boolean;
}

const AuthContext = createContext<AuthState | null>(null);

async function fetchCurrentUser(): Promise<User | null> {
    try {
        return (await api<{ data: User }>('/auth/me')).data;
    } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null;
        throw e;
    }
}

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);

    const refresh = useCallback(async () => {
        setUser(await fetchCurrentUser());
    }, []);

    useEffect(() => {
        let active = true;
        fetchCurrentUser()
            .then((current) => active && setUser(current))
            .catch(() => active && setUser(null))
            .finally(() => active && setLoading(false));
        return () => {
            active = false;
        };
    }, []);

    const login = useCallback(async (email: string, password: string) => {
        const res = await api<{ user: User }>('/auth/login', { method: 'POST', body: { email, password } });
        setUser(res.user);
        return res.user;
    }, []);

    const register = useCallback(async (input: RegisterInput) => {
        const res = await api<{ user: User }>('/auth/register', { method: 'POST', body: input });
        setUser(res.user);
        return res.user;
    }, []);

    const logout = useCallback(async () => {
        await api('/auth/logout', { method: 'POST' });
        setUser(null);
    }, []);

    const value = useMemo<AuthState>(
        () => ({
            user,
            loading,
            login,
            register,
            logout,
            refresh,
            can: (permission) => !!user?.permissions.includes(permission),
        }),
        [user, loading, login, register, logout, refresh],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
    return ctx;
}

export function homePathFor(user: User): string {
    return user.is_staff ? '/admin' : '/account';
}
