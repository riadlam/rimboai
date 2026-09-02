import { Component, type ErrorInfo, type ReactNode } from 'react';

type Props = {
    children: ReactNode;
};

type State = {
    hasError: boolean;
};

export default class AppErrorBoundary extends Component<Props, State> {
    state: State = { hasError: false };

    static getDerivedStateFromError(): State {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        console.error('App render error:', error, info);
    }

    private openInBrowser = () => {
        window.open(window.location.href, '_blank', 'noopener,noreferrer');
    };

    render() {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <div className="flex min-h-screen flex-col items-center justify-center bg-[#0d0d12] px-6 py-12 text-center text-zinc-200">
                <img
                    src="/storage/ai_icons/logo_icon_only.png"
                    alt=""
                    width={56}
                    height={56}
                    className="mb-5 h-14 w-14 rounded-2xl"
                />
                <h1 className="text-lg font-semibold text-white">Something went wrong</h1>
                <p className="mt-2 max-w-sm text-sm text-zinc-400">
                    This page could not load in the in-app browser. Try opening it in Safari or Chrome.
                </p>
                <button
                    type="button"
                    onClick={this.openInBrowser}
                    className="mt-6 rounded-xl bg-[#FF5733] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#e64d2e]"
                >
                    Open in browser
                </button>
            </div>
        );
    }
}
