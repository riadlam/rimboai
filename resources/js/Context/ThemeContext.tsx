import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';

type ThemeContextValue = {
    isDark: boolean;
    toggle: () => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);

function applyTheme(isDark: boolean) {
    if (isDark) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    }
}

export function ThemeProvider({ children }: { children: ReactNode }) {
    const [isDark, setIsDark] = useState(() => {
        if (typeof window === 'undefined') return true;
        const stored = localStorage.getItem('theme');
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
