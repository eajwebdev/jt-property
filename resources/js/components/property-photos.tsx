import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type PropertyPhoto } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { ImagePlus, Images, LoaderCircle, Pencil, Star, Trash2, UploadCloud } from 'lucide-react';
import { type DragEvent, useRef, useState } from 'react';

interface Props {
    propertyId: number;
    photos: PropertyPhoto[];
    max: number;
    canManage: boolean;
    listed: boolean;
}

/** Photo gallery on the property page. Owners upload (drag & drop or pick), choose the cover, caption and delete. */
export default function PropertyPhotos({ propertyId, photos, max, canManage, listed }: Props) {
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [editing, setEditing] = useState<PropertyPhoto | null>(null);
    const [deleting, setDeleting] = useState<PropertyPhoto | null>(null);
    const upload = useForm<{ photos: File[] }>({ photos: [] });
    const left = max - photos.length;

    const send = (files: FileList | File[]) => {
        const list = Array.from(files).filter((f) => f.type.startsWith('image/'));
        if (list.length === 0) return;
        upload.transform(() => ({ photos: list }));
        upload.post(route('properties.photos.store', propertyId), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (input.current) input.current.value = '';
            },
        });
    };
    const drop = (e: DragEvent) => {
        e.preventDefault();
        setDragging(false);
        if (canManage && left > 0) send(e.dataTransfer.files);
    };
    const errors = Object.entries(upload.errors).map(([, message]) => message);

    return (
        <section className="surface space-y-4 p-5" aria-labelledby="photos-title">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="photos-title" className="flex items-center gap-2 text-base font-semibold">
                        <Images className="text-muted-foreground size-4" aria-hidden /> Photos
                        <span className="text-muted-foreground font-mono text-xs font-normal">
                            {photos.length}/{max}
                        </span>
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        {listed
                            ? 'Shown on the public website. The first photo is the cover.'
                            : 'Turn on "Show on the public website" in Edit property to publish them.'}
                    </p>
                </div>
                {canManage && left > 0 && (
                    <Button variant="outline" onClick={() => input.current?.click()} disabled={upload.processing}>
                        {upload.processing ? <LoaderCircle className="animate-spin" /> : <ImagePlus />} Add photos
                    </Button>
                )}
            </div>

            {canManage && (
                <input
                    ref={input}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    className="sr-only"
                    aria-label="Choose photos to upload"
                    onChange={(e) => e.target.files && send(e.target.files)}
                />
            )}

            {upload.progress && (
                <div
                    className="bg-muted h-1.5 overflow-hidden rounded-full"
                    role="progressbar"
                    aria-valuenow={upload.progress.percentage}
                    aria-label="Upload progress"
                >
                    <div className="bg-primary h-full transition-[width]" style={{ width: `${upload.progress.percentage ?? 0}%` }} />
                </div>
            )}
            {errors.length > 0 && (
                <div className="space-y-1">
                    {[...new Set(errors)].map((message) => (
                        <InputError key={message} message={message} />
                    ))}
                </div>
            )}

            {photos.length === 0 ? (
                <div
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={drop}
                    className={cn(
                        'flex flex-col items-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors',
                        dragging && 'border-primary bg-primary/5',
                    )}
                >
                    <UploadCloud className="text-muted-foreground size-8" aria-hidden />
                    <p className="font-medium">{canManage ? 'Drop photos here' : 'No photos yet'}</p>
                    {canManage && (
                        <>
                            <p className="text-muted-foreground text-sm">
                                JPG, PNG or WebP, up to 8 MB each. Large photos are resized automatically.
                            </p>
                            <Button variant="outline" size="sm" onClick={() => input.current?.click()}>
                                Choose photos
                            </Button>
                        </>
                    )}
                </div>
            ) : (
                <ul
                    className={cn('grid grid-cols-2 gap-3 rounded-xl sm:grid-cols-3 lg:grid-cols-4', dragging && 'ring-primary ring-2 ring-offset-4')}
                    onDragOver={(e) => {
                        if (!canManage) return;
                        e.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={drop}
                >
                    {photos.map((photo, i) => (
                        <li key={photo.id} className="group bg-muted relative aspect-[4/3] overflow-hidden rounded-xl border">
                            <img
                                src={photo.url}
                                alt={photo.caption ?? `Photo ${i + 1}`}
                                loading="lazy"
                                className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
                            />
                            {i === 0 && (
                                <Badge className="absolute top-2 left-2 shadow-sm">
                                    <Star className="size-3 fill-current" aria-hidden /> Cover
                                </Badge>
                            )}
                            {photo.caption && (
                                <p className="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/70 to-transparent px-2.5 pt-6 pb-2 text-xs text-white">
                                    {photo.caption}
                                </p>
                            )}
                            {canManage && (
                                <div className="absolute top-2 right-2 flex gap-1 opacity-100 transition-opacity sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100">
                                    {i !== 0 && (
                                        <Button
                                            size="icon"
                                            variant="secondary"
                                            className="size-8 shadow-sm"
                                            aria-label="Make cover photo"
                                            title="Make cover photo"
                                            onClick={() =>
                                                router.patch(route('property-photos.update', photo.id), { cover: true }, { preserveScroll: true })
                                            }
                                        >
                                            <Star />
                                        </Button>
                                    )}
                                    <Button
                                        size="icon"
                                        variant="secondary"
                                        className="size-8 shadow-sm"
                                        aria-label="Edit caption"
                                        title="Edit caption"
                                        onClick={() => setEditing(photo)}
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="secondary"
                                        className="hover:bg-destructive hover:text-destructive-foreground size-8 shadow-sm"
                                        aria-label="Delete photo"
                                        title="Delete photo"
                                        onClick={() => setDeleting(photo)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                    {canManage && left > 0 && (
                        <li>
                            <button
                                type="button"
                                onClick={() => input.current?.click()}
                                className="text-muted-foreground hover:border-primary hover:text-primary flex aspect-[4/3] w-full flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed text-sm transition-colors"
                            >
                                <ImagePlus className="size-6" aria-hidden />
                                Add or drop photos
                            </button>
                        </li>
                    )}
                </ul>
            )}

            <CaptionDialog photo={editing} onClose={() => setEditing(null)} />
            <Dialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this photo?</DialogTitle>
                        <DialogDescription>It is removed from the property page and the public website. This cannot be undone.</DialogDescription>
                    </DialogHeader>
                    {deleting && <img src={deleting.url} alt="" className="aspect-[4/3] w-full rounded-lg object-cover" />}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleting(null)}>
                            Keep it
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                deleting &&
                                router.delete(route('property-photos.destroy', deleting.id), {
                                    preserveScroll: true,
                                    onSuccess: () => setDeleting(null),
                                })
                            }
                        >
                            <Trash2 /> Delete photo
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function CaptionDialog({ photo, onClose }: { photo: PropertyPhoto | null; onClose: () => void }) {
    return (
        <Dialog open={photo !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>{photo && <CaptionForm photo={photo} onClose={onClose} />}</DialogContent>
        </Dialog>
    );
}

function CaptionForm({ photo, onClose }: { photo: PropertyPhoto; onClose: () => void }) {
    const { data, setData, patch, processing, errors } = useForm({ caption: photo.caption ?? '' });
    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                patch(route('property-photos.update', photo.id), { preserveScroll: true, onSuccess: onClose });
            }}
        >
            <DialogHeader>
                <DialogTitle>Photo caption</DialogTitle>
                <DialogDescription>A short label visitors see, like "Room 3 · double deck" or "Shared kitchen".</DialogDescription>
            </DialogHeader>
            <img src={photo.url} alt="" className="aspect-[4/3] w-full rounded-lg object-cover" />
            <div className="grid gap-2">
                <Label htmlFor="caption">Caption</Label>
                <Input id="caption" value={data.caption} maxLength={120} onChange={(e) => setData('caption', e.target.value)} autoFocus />
                <InputError message={errors.caption} />
            </div>
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button disabled={processing}>Save caption</Button>
            </DialogFooter>
        </form>
    );
}
