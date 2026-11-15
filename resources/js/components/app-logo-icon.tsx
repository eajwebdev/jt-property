import { cn } from '@/lib/utils';
import { ImgHTMLAttributes } from 'react';

// Square JT app icon (has its own navy background, so it works in light and dark mode).
export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return <img src="/branding/jt-app-icon.webp" alt="JT Property" className={cn('rounded-md object-contain', className)} {...props} />;
}
