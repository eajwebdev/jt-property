import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid, LogIn, Mail, MapPin, Phone } from 'lucide-react';
import { type ReactNode } from 'react';

export interface Company {
    name: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
}

/** Shell for the public website: brand header with section links and a staff login, plus a contact footer. */
export default function PublicLayout({ company, children }: { company: Company; children: ReactNode }) {
    const { auth } = usePage<SharedData>().props;
    const name = company.name || 'JT Property';

    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col">
            <a
                href="#main"
                className="focus:bg-primary focus:text-primary-foreground sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:px-4 focus:py-2"
            >
                Skip to content
            </a>
            <header className="bg-background/80 supports-[backdrop-filter]:bg-background/65 sticky top-0 z-30 border-b backdrop-blur-md">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 md:px-6">
                    <Link
                        href={route('home')}
                        className="focus-visible:ring-ring flex items-center gap-2.5 rounded-lg focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <img src="/branding/jt-app-icon.webp" alt="" className="size-9 rounded-lg" width={36} height={36} />
                        <span className="leading-tight font-semibold">{name}</span>
                    </Link>
                    <nav aria-label="Main" className="flex items-center gap-1 text-sm">
                        <a
                            href={`${route('home')}#places`}
                            className="hover:bg-muted hidden rounded-lg px-3 py-2 font-medium transition-colors sm:block"
                        >
                            Places
                        </a>
                        <a href="#contact" className="hover:bg-muted hidden rounded-lg px-3 py-2 font-medium transition-colors sm:block">
                            Contact
                        </a>
                        {auth.user ? (
                            <Button asChild size="sm" className="ml-2">
                                <Link href={route('dashboard')}>
                                    <LayoutGrid /> Dashboard
                                </Link>
                            </Button>
                        ) : (
                            <Button asChild size="sm" variant="outline" className="ml-2">
                                <Link href={route('login')}>
                                    <LogIn /> Staff login
                                </Link>
                            </Button>
                        )}
                    </nav>
                </div>
            </header>

            <main id="main" className="flex-1">
                {children}
            </main>

            <footer id="contact" className="bg-card mt-16 border-t">
                <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 md:grid-cols-[1.4fr_1fr] md:px-6">
                    <div className="space-y-3">
                        <div className="flex items-center gap-2.5">
                            <img src="/branding/jt-app-icon.webp" alt="" className="size-9 rounded-lg" width={36} height={36} loading="lazy" />
                            <span className="font-semibold">{name}</span>
                        </div>
                        <p className="text-muted-foreground max-w-md text-sm">
                            Ask about a room, schedule a visit, or reserve a place. We reply as soon as we can.
                        </p>
                    </div>
                    <address className="space-y-3 text-sm not-italic">
                        <h2 className="font-semibold">Contact us</h2>
                        {company.phone && (
                            <a
                                href={`tel:${company.phone.replace(/\s+/g, '')}`}
                                className="hover:text-primary flex items-center gap-2 transition-colors"
                            >
                                <Phone className="text-muted-foreground size-4" aria-hidden /> {company.phone}
                            </a>
                        )}
                        {company.email && (
                            <a href={`mailto:${company.email}`} className="hover:text-primary flex items-center gap-2 transition-colors">
                                <Mail className="text-muted-foreground size-4" aria-hidden /> {company.email}
                            </a>
                        )}
                        {company.address && (
                            <p className="flex items-start gap-2">
                                <MapPin className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden /> {company.address}
                            </p>
                        )}
                        {!company.phone && !company.email && !company.address && (
                            <p className="text-muted-foreground">Contact details coming soon.</p>
                        )}
                    </address>
                </div>
                <div className="text-muted-foreground border-t py-5 text-center text-xs">
                    © {new Date().getFullYear()} {name}
                </div>
            </footer>
        </div>
    );
}
