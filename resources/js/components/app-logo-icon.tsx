import { cn } from '@/lib/utils';
import { ImgHTMLAttributes } from 'react';

// Square Mubarak Boardinghouse app icon (has its own navy background, so it works in light and dark mode).
export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img src="/branding/mubarak-app-icon.webp" alt="Mubarak Boardinghouse" className={cn('rounded-md object-contain', className)} {...props} />
    );
}
