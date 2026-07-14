import { motion, type HTMLMotionProps } from 'framer-motion';
import type { ReactNode } from 'react';

const easeOut = [0.22, 1, 0.36, 1] as const;

export function PageFade({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.35, ease: easeOut }}
            className={`min-w-0 w-full ${className ?? ''}`}
        >
            {children}
        </motion.div>
    );
}

export function FadeSlide({
    children,
    className,
    direction = 'left',
}: {
    children: ReactNode;
    className?: string;
    direction?: 'left' | 'right' | 'up' | 'down';
}) {
    const offset = {
        left: { x: -8, y: 0 },
        right: { x: 8, y: 0 },
        up: { x: 0, y: -8 },
        down: { x: 0, y: 8 },
    }[direction];

    return (
        <motion.div
            initial={{ opacity: 0, ...offset }}
            animate={{ opacity: 1, x: 0, y: 0 }}
            exit={{ opacity: 0, ...offset }}
            transition={{ duration: 0.2, ease: easeOut }}
            className={className}
        >
            {children}
        </motion.div>
    );
}

export function StaggerChildren({
    children,
    className,
    delay = 0.04,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    return (
        <motion.div
            className={className}
            initial="hidden"
            animate="show"
            variants={{
                hidden: {},
                show: { transition: { staggerChildren: delay } },
            }}
        >
            {children}
        </motion.div>
    );
}

export const staggerItem = {
    hidden: { opacity: 0, y: 16 },
    show: {
        opacity: 1,
        y: 0,
        transition: { duration: 0.35, ease: easeOut },
    },
};

export function HoverLift({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <motion.div
            className={className}
            whileHover={{ y: -2, transition: { duration: 0.2 } }}
            whileTap={{ scale: 0.98 }}
        >
            {children}
        </motion.div>
    );
}

export function GradientGlow({ className, ...props }: HTMLMotionProps<'div'>) {
    return (
        <motion.div
            className={className}
            initial={{ opacity: 0.7 }}
            animate={{ opacity: [0.55, 0.9, 0.55] }}
            transition={{ duration: 4, repeat: Infinity, ease: 'easeInOut' }}
            {...props}
        />
    );
}
