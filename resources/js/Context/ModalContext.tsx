import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';

export type ToolModalData = {
    name: string;
    video: string;
    poster: string;
};

type ModalName = 'settings' | 'tool' | null;

type ModalContextValue = {
    activeModal: ModalName;
    toolData: ToolModalData | null;
    loading: boolean;
    progress: number;
    open: (name: Exclude<ModalName, null | 'tool'>) => void;
    openTool: (data: ToolModalData) => void;
    close: () => void;
    simulateGeneration: () => void;
};

const ModalContext = createContext<ModalContextValue | null>(null);

export function ModalProvider({ children }: { children: ReactNode }) {
    const [activeModal, setActiveModal] = useState<ModalName>(null);
    const [toolData, setToolData] = useState<ToolModalData | null>(null);
    const [loading, setLoading] = useState(false);
    const [progress, setProgress] = useState(0);
    const progressInterval = useRef<ReturnType<typeof setInterval> | null>(null);

    const clearProgress = useCallback(() => {
        if (progressInterval.current) {
            clearInterval(progressInterval.current);
            progressInterval.current = null;
        }
    }, []);

    const close = useCallback(() => {
        setActiveModal(null);
        setToolData(null);
        setLoading(false);
        setProgress(0);
        clearProgress();
        document.body.style.overflow = '';
    }, [clearProgress]);

    const open = useCallback(
        (name: Exclude<ModalName, null | 'tool'>) => {
            clearProgress();
            setActiveModal(name);
            setToolData(null);
            setLoading(false);
            setProgress(0);
            document.body.style.overflow = 'hidden';
        },
        [clearProgress],
    );

    const openTool = useCallback(
        (data: ToolModalData) => {
            clearProgress();
            setActiveModal('tool');
            setToolData(data);
            setLoading(false);
            setProgress(0);
            document.body.style.overflow = 'hidden';
        },
        [clearProgress],
    );

    const simulateGeneration = useCallback(() => {
        clearProgress();
        setLoading(true);
        setProgress(0);
        progressInterval.current = setInterval(() => {
            setProgress((prev) => {
                if (prev >= 90) return prev;
                const next = prev + Math.random() * 8 + 2;
                return next > 90 ? 90 : next;
            });
        }, 600);

        setTimeout(() => {
            clearProgress();
            setProgress(100);
            setTimeout(() => {
                setLoading(false);
                setProgress(0);
            }, 800);
        }, 4500);
    }, [clearProgress]);

    useEffect(() => () => clearProgress(), [clearProgress]);

    const value = useMemo(
        () => ({
            activeModal,
            toolData,
            loading,
            progress,
            open,
            openTool,
            close,
            simulateGeneration,
        }),
        [activeModal, toolData, loading, progress, open, openTool, close, simulateGeneration],
    );

    return <ModalContext.Provider value={value}>{children}</ModalContext.Provider>;
}

export function useModal() {
    const ctx = useContext(ModalContext);
    if (!ctx) throw new Error('useModal must be used within ModalProvider');
    return ctx;
}
