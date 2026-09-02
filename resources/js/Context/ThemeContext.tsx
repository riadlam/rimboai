import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { safeGetItem, safeSetItem } from '@/lib/safeStorage';

type ThemeContextValue = {
    isDark: boolean;
    toggle: () => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);

function applyTheme(isDark: boolean) {
    if (isDark) {
        document.documentElement.classList.add('dark');
        safeSetItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark');
        safeSetItem('theme', 'light');
    }
}

export function ThemeProvider({ children }: { children: ReactNode }) {
    const [isDark, setIsDark] = useState(() => {
        if (typeof window === 'undefined') return true;
        const stored = safeGetItem('theme');
        return stored ? stored === 'dark' : true;
    });

    useEffect(() => {
        applyTheme(isDark);
    }, [isDark]);

    const toggle = useCallback(() => {
        setIsDark((prev) => !prev);
    }, []);

    const value = useMemo(() => ({ isDark, toggle }), [isDark, toggle]);

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
    const ctx = useContext(ThemeContext);
    if (!ctx) throw new Error('useTheme must be used within ThemeProvider');
    return ctx;
}
