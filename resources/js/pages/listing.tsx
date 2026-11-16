import { AvailabilityBadge, type ListingCard } from '@/components/listing-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import PublicLayout, { type Company } from '@/layouts/public-layout';
import { propertyTypeLabels, rentModeLabels } from '@/lib/property-labels';
import { cn } from '@/lib/utils';
import { type PropertyPhoto } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Building2, Check, ChevronLeft, ChevronRight, Expand, Mail, MapPin, Navigation, Phone } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Room {
    id: number;
    name: string;
    floor: string | null;
    rent_mode: string;
    capacity: number;
    available: number;
    amenities: string[];
}

interface Props {
    company: Company;
    listing: ListingCard & { description: string | null; photos: PropertyPhoto[]; rooms: Room[] };
}

export default function Listing({ company, listing }: Props) {
    const [open, setOpen] = useState<number | null>(null);
    const photos = listing.photos;
    const mapUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(listing.address)}`;

    return (
        <PublicLayout company={company}>
            <Head title={`${listing.name} · ${company.name || 'JT Property'}`}>
                <meta name="description" content={`${listing.name}: ${listing.address}. ${listing.available} places available.`} />
            </Head>

            <div className="mx-auto max-w-6xl space-y-8 px-4 py-8 md:px-6">
                <Link
                    href={route('home')}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm transition-colors"
                >
                    <ArrowLeft className="size-4" aria-hidden /> All places
                </Link>

                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div className="space-y-2">
                        <p className="text-primary text-xs font-semibold tracking-wide uppercase">
                            {propertyTypeLabels[listing.type] ?? listing.type}
                        </p>
                        <h1 className="text-3xl font-bold tracking-tight md:text-4xl">{listing.name}</h1>
                        <p className="text-muted-foreground flex items-start gap-1.5">
                            <MapPin className="mt-1 size-4 shrink-0" aria-hidden /> {listing.address}
                        </p>
                    </div>
                    <AvailabilityBadge available={listing.available} className="px-3 py-1 text-sm" />
                </header>

                <Gallery photos={photos} name={listing.name} onOpen={setOpen} />

                <div className="grid gap-8 lg:grid-cols-[1fr_22rem]">
                    <div className="space-y-8">
                        {listing.description && (
                            <section className="space-y-2">
                                <h2 className="text-xl font-semibold">About this place</h2>
                                <p className="text-muted-foreground leading-relaxed whitespace-pre-line">{listing.description}</p>
                            </section>
                        )}

                        {listing.amenities.length > 0 && (
                            <section className="space-y-3">
                                <h2 className="text-xl font-semibold">What is included</h2>
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {listing.amenities.map((a) => (
                                        <li key={a} className="flex items-center gap-2">
                                            <span className="bg-success/12 text-success grid size-6 place-items-center rounded-full" aria-hidden>
                                                <Check className="size-3.5" />
                                            </span>
                                            <span>{a}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="space-y-3">
                            <h2 className="text-xl font-semibold">Rooms</h2>
                            {listing.rooms.length === 0 ? (
                                <p className="text-muted-foreground">Room details coming soon.</p>
                            ) : (
                                <ul className="grid gap-3 sm:grid-cols-2">
                                    {listing.rooms.map((r) => (
                                        <li key={r.id} className="surface flex flex-col gap-2 p-4">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="font-semibold">Room {r.name}</p>
                                                    <p className="text-muted-foreground text-sm">
                                                        {rentModeLabels[r.rent_mode] ?? r.rent_mode} · {r.capacity}{' '}
                                                        {r.capacity === 1 ? 'place' : 'places'}
                                                        {r.floor && ` · Floor ${r.floor}`}
                                                    </p>
                                                </div>
                                                <Badge variant={r.available > 0 ? 'success' : 'muted'}>
                                                    {r.available > 0 ? `${r.available} free` : 'Full'}
                                                </Badge>
                                            </div>
                                            {r.amenities.length > 0 && <p className="text-muted-foreground text-xs">{r.amenities.join(' · ')}</p>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>

                    <aside className="lg:sticky lg:top-24 lg:self-start">
                        <div className="surface space-y-4 p-5">
                            <div>
                                <p className="font-mono text-3xl font-semibold">{listing.available}</p>
                                <p className="text-muted-foreground text-sm">
                                    of {listing.capacity} places available today · {listing.rooms.length} rooms
                                </p>
                            </div>
                            <div className="grid gap-2">
                                {company.phone && (
                                    <Button asChild size="lg">
                                        <a href={`tel:${company.phone.replace(/\s+/g, '')}`}>
                                            <Phone /> Call {company.phone}
                                        </a>
                                    </Button>
                                )}
                                {company.email && (
                                    <Button asChild size="lg" variant="outline">
                                        <a href={`mailto:${company.email}?subject=${encodeURIComponent(`Inquiry: ${listing.name}`)}`}>
                                            <Mail /> Email us
                                        </a>
                                    </Button>
                                )}
                                <Button asChild size="lg" variant="outline">
                                    <a href={mapUrl} target="_blank" rel="noopener noreferrer">
                                        <Navigation /> Directions
                                    </a>
                                </Button>
                            </div>
                            <p className="text-muted-foreground text-xs">Mention “{listing.name}” when you contact us.</p>
                        </div>
                    </aside>
                </div>
            </div>

            <Lightbox photos={photos} index={open} onChange={setOpen} name={listing.name} />
        </PublicLayout>
    );
}

/** Large cover + up to four thumbnails; "show all" opens the lightbox. Reserved aspect ratios avoid layout shift. */
function Gallery({ photos, name, onOpen }: { photos: PropertyPhoto[]; name: string; onOpen: (i: number) => void }) {
    if (photos.length === 0) {
        return (
            <div className="bg-muted text-muted-foreground grid aspect-[21/9] place-items-center rounded-2xl">
                <div className="flex flex-col items-center gap-2">
                    <Building2 className="size-10" aria-hidden />
                    <p className="text-sm">Photos coming soon</p>
                </div>
            </div>
        );
    }
    // Layout adapts to the photo count so there is never an empty cell:
    // 1 → one wide photo; 2 → side by side; 3 → cover + two stacked; 5+ → cover + four tiles.
    const thumbs = photos.length >= 5 ? photos.slice(1, 5) : photos.length >= 3 ? photos.slice(1, 3) : photos.slice(1, 2);
    const layout =
        photos.length >= 5
            ? 'md:grid-cols-4 md:grid-rows-2'
            : photos.length >= 3
              ? 'md:grid-cols-3 md:grid-rows-2'
              : photos.length === 2
                ? 'md:grid-cols-2'
                : '';
    const coverSpan =
        photos.length >= 3 ? 'md:col-span-2 md:row-span-2 md:aspect-auto md:min-h-[26rem]' : photos.length === 2 ? '' : 'md:aspect-[21/9]';
    const tile = (photo: PropertyPhoto, i: number, className: string, eager = false) => (
        <button
            key={photo.id}
            type="button"
            onClick={() => onOpen(i)}
            className={cn(
                'group bg-muted focus-visible:ring-ring relative overflow-hidden focus-visible:ring-2 focus-visible:outline-none',
                className,
            )}
            aria-label={`Open photo ${i + 1} of ${photos.length}${photo.caption ? `: ${photo.caption}` : ''}`}
        >
            <img
                src={photo.url}
                alt={photo.caption ?? `${name} photo ${i + 1}`}
                loading={eager ? 'eager' : 'lazy'}
                className="size-full object-cover transition-transform duration-500 group-hover:scale-105"
            />
        </button>
    );

    return (
        <div className="relative">
            <div className={cn('grid gap-2 overflow-hidden rounded-2xl', layout)}>
                {tile(photos[0], 0, cn('aspect-[4/3]', coverSpan), true)}
                {thumbs.map((photo, j) => tile(photo, j + 1, cn('hidden md:block', photos.length >= 3 ? 'md:aspect-auto' : 'aspect-[4/3]')))}
            </div>
            {photos.length > 1 && (
                <Button variant="secondary" size="sm" className="absolute right-3 bottom-3 shadow-md" onClick={() => onOpen(0)}>
                    <Expand /> Show all {photos.length} photos
                </Button>
            )}
        </div>
    );
}

function Lightbox({
    photos,
    index,
    onChange,
    name,
}: {
    photos: PropertyPhoto[];
    index: number | null;
    onChange: (i: number | null) => void;
    name: string;
}) {
    const go = (delta: number) => index !== null && onChange((index + delta + photos.length) % photos.length);
    useEffect(() => {
        if (index === null) return;
        const key = (e: KeyboardEvent) => {
            if (e.key === 'ArrowRight') go(1);
            if (e.key === 'ArrowLeft') go(-1);
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    });
    const photo = index !== null ? photos[index] : null;

    return (
        <Dialog open={photo !== null} onOpenChange={(open) => !open && onChange(null)}>
            <DialogContent className="max-w-5xl gap-3 border-0 bg-slate-950 p-3 text-white sm:max-w-5xl [&>button]:text-white">
                <DialogTitle className="sr-only">{name} photos</DialogTitle>
                <DialogDescription className="sr-only">Use the left and right arrow keys to move between photos.</DialogDescription>
                {photo && (
                    <>
                        <div className="relative grid place-items-center">
                            <img
                                src={photo.url}
                                alt={photo.caption ?? `${name} photo ${index! + 1}`}
                                className="max-h-[75vh] w-auto rounded-lg object-contain"
                            />
                            {photos.length > 1 && (
                                <>
                                    <Button
                                        size="icon"
                                        variant="secondary"
                                        className="absolute top-1/2 left-2 -translate-y-1/2 rounded-full opacity-90"
                                        onClick={() => go(-1)}
                                        aria-label="Previous photo"
                                    >
                                        <ChevronLeft />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="secondary"
                                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded-full opacity-90"
                                        onClick={() => go(1)}
                                        aria-label="Next photo"
                                    >
                                        <ChevronRight />
                                    </Button>
                                </>
                            )}
                        </div>
                        <div className="flex items-center justify-between gap-3 px-1 text-sm">
                            <span className="text-white/80">{photo.caption}</span>
                            <span className="font-mono text-white/60">
                                {index! + 1} / {photos.length}
                            </span>
                        </div>
                        {photos.length > 1 && (
                            <div className="no-scrollbar flex gap-2 overflow-x-auto pb-1">
                                {photos.map((p, i) => (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() => onChange(i)}
                                        aria-label={`Photo ${i + 1}`}
                                        aria-current={i === index}
                                        className={cn(
                                            'h-14 w-20 shrink-0 overflow-hidden rounded-md opacity-50 transition-opacity hover:opacity-100',
                                            i === index && 'ring-primary opacity-100 ring-2',
                                        )}
                                    >
                                        <img src={p.url} alt="" loading="lazy" className="size-full object-cover" />
                                    </button>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
