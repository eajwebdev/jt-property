import { AvailabilityBadge, type ListingCard } from '@/components/listing-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import PublicLayout, { type Company } from '@/layouts/public-layout';
import { propertyTypeLabels } from '@/lib/property-labels';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BedDouble, Building2, Camera, DoorOpen, MapPin, Phone, ShieldCheck, Sparkles, Wallet } from 'lucide-react';

interface Props {
    company: Company;
    listings: ListingCard[];
}

export default function Welcome({ company, listings }: Props) {
    const name = company.name || 'JT Property';
    const available = listings.reduce((sum, l) => sum + l.available, 0);
    const hero = listings.find((l) => l.cover)?.cover;

    return (
        <PublicLayout company={company}>
            <Head title={`${name} · Rooms for rent`}>
                <meta name="description" content={`See our boarding houses, photos and available places at ${name}.`} />
            </Head>

            {/* Hero: real numbers only (places free now), primary CTA to the listings. */}
            <section className="relative isolate overflow-hidden">
                {hero ? (
                    <img src={hero.url} alt="" className="absolute inset-0 -z-20 size-full object-cover" fetchPriority="high" />
                ) : (
                    <div className="from-brand to-primary absolute inset-0 -z-20 bg-gradient-to-br" aria-hidden />
                )}
                <div className="absolute inset-0 -z-10 bg-gradient-to-r from-slate-950/85 via-slate-950/60 to-slate-950/20" aria-hidden />
                <div className="mx-auto flex min-h-[28rem] max-w-6xl flex-col justify-center gap-6 px-4 py-20 text-white md:min-h-[32rem] md:px-6">
                    <Badge className="w-fit border-white/20 bg-white/10 text-white backdrop-blur">
                        <Sparkles className="size-3" aria-hidden />{' '}
                        {available > 0 ? `${available} ${available === 1 ? 'place' : 'places'} available now` : 'Join the waiting list'}
                    </Badge>
                    <h1 className="max-w-2xl text-4xl leading-tight font-bold tracking-tight md:text-5xl">A comfortable, safe place to stay.</h1>
                    <p className="max-w-xl text-lg text-white/85">
                        Look around {name}: see the rooms, what is included, and where there is space. Then call or message us to visit.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button asChild size="lg" className="h-12 px-6 text-base">
                            <a href="#places">
                                See available places <ArrowRight />
                            </a>
                        </Button>
                        {company.phone && (
                            <Button
                                asChild
                                size="lg"
                                variant="outline"
                                className="h-12 border-white/30 bg-white/10 px-6 text-base text-white backdrop-blur hover:bg-white/20 hover:text-white"
                            >
                                <a href={`tel:${company.phone.replace(/\s+/g, '')}`}>
                                    <Phone /> {company.phone}
                                </a>
                            </Button>
                        )}
                    </div>
                </div>
            </section>

            <section id="places" className="mx-auto max-w-6xl scroll-mt-20 space-y-6 px-4 pt-16 md:px-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-3xl font-bold tracking-tight">Our places</h2>
                        <p className="text-muted-foreground mt-1">Photos, what is included and how many places are free today.</p>
                    </div>
                </div>

                {listings.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed px-6 py-16 text-center">
                        <Building2 className="text-muted-foreground size-10" aria-hidden />
                        <p className="text-lg font-semibold">Listings coming soon</p>
                        <p className="text-muted-foreground max-w-md text-sm">
                            We are preparing photos of our rooms. Contact us below to ask about available places.
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {listings.map((l) => (
                            <li key={l.code}>
                                <article className="surface group hover:shadow-lift focus-within:ring-ring relative flex h-full flex-col overflow-hidden transition-shadow duration-300 focus-within:ring-2">
                                    <div className="bg-muted relative aspect-[4/3] overflow-hidden">
                                        {l.cover ? (
                                            <img
                                                src={l.cover.url}
                                                alt={l.cover.caption ?? `${l.name} photo`}
                                                loading="lazy"
                                                className="size-full object-cover transition-transform duration-500 group-hover:scale-105"
                                            />
                                        ) : (
                                            <div className="text-muted-foreground grid size-full place-items-center">
                                                <Building2 className="size-10" aria-hidden />
                                            </div>
                                        )}
                                        <AvailabilityBadge available={l.available} className="absolute top-3 left-3" />
                                        {l.photo_count > 1 && (
                                            <span className="absolute right-3 bottom-3 flex items-center gap-1 rounded-full bg-black/60 px-2 py-0.5 text-xs text-white backdrop-blur">
                                                <Camera className="size-3" aria-hidden /> {l.photo_count}
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-1 flex-col gap-3 p-5">
                                        <div>
                                            <p className="text-primary text-xs font-semibold tracking-wide uppercase">
                                                {propertyTypeLabels[l.type] ?? l.type}
                                            </p>
                                            <h3 className="mt-1 text-lg font-semibold">
                                                <Link
                                                    href={route('listings.show', l.code)}
                                                    className="after:absolute after:inset-0 focus-visible:outline-none"
                                                >
                                                    {l.name}
                                                </Link>
                                            </h3>
                                            <p className="text-muted-foreground mt-1 flex items-start gap-1 text-sm">
                                                <MapPin className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                                                <span className="line-clamp-2">{l.address}</span>
                                            </p>
                                        </div>
                                        {l.amenities.length > 0 && (
                                            <ul className="flex flex-wrap gap-1.5" aria-label="Included">
                                                {l.amenities.slice(0, 4).map((a) => (
                                                    <li key={a} className="bg-muted rounded-full px-2.5 py-0.5 text-xs">
                                                        {a}
                                                    </li>
                                                ))}
                                                {l.amenities.length > 4 && (
                                                    <li className="text-muted-foreground px-1 text-xs">+{l.amenities.length - 4} more</li>
                                                )}
                                            </ul>
                                        )}
                                        <div className="text-muted-foreground mt-auto flex items-center justify-between border-t pt-3 text-sm">
                                            <span className="flex items-center gap-1.5">
                                                <DoorOpen className="size-4" aria-hidden /> {l.rooms} rooms · {l.capacity} places
                                            </span>
                                            <span className="text-primary flex items-center gap-1 font-medium">
                                                View <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" aria-hidden />
                                            </span>
                                        </div>
                                    </div>
                                </article>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="mx-auto max-w-6xl px-4 pt-16 md:px-6" aria-labelledby="why">
                <h2 id="why" className="text-3xl font-bold tracking-tight">
                    Why stay with us
                </h2>
                <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        { icon: BedDouble, title: 'Rooms and bedspaces', text: 'Whole rooms or a bed in a shared room, whichever suits you.' },
                        { icon: Wallet, title: 'Clear monthly bills', text: 'One statement each month: rent, electricity and any extras, itemised.' },
                        { icon: ShieldCheck, title: 'Looked after', text: 'A direct contact for questions, repairs and anything about your stay.' },
                        { icon: Phone, title: 'Easy to reach', text: 'Call or message to ask questions or schedule a visit before you move in.' },
                    ].map((f) => (
                        <li key={f.title} className="surface p-5">
                            <span className="bg-primary/10 text-primary grid size-10 place-items-center rounded-xl" aria-hidden>
                                <f.icon className="size-5" />
                            </span>
                            <h3 className="mt-4 font-semibold">{f.title}</h3>
                            <p className="text-muted-foreground mt-1 text-sm">{f.text}</p>
                        </li>
                    ))}
                </ul>
            </section>
        </PublicLayout>
    );
}
