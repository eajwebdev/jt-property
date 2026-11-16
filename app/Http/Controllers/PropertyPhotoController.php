<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Services\PropertyPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PropertyPhotoController extends Controller
{
    public function __construct(private PropertyPhotoService $photos) {}

    /**
     * Public for listed properties (the landing page uses it); otherwise only for signed-in users with access.
     * Served by Laravel so no storage symlink is needed and unlisted photos stay private.
     */
    public function show(Request $request, PropertyPhoto $photo): StreamedResponse
    {
        $property = $photo->property;
        $public = $property->show_on_website && $property->archived_at === null;
        abort_unless($public || ($request->user()?->canAccessProperty($property) ?? false), 404);
        abort_unless(Storage::disk('public')->exists($photo->path), 404);

        return Storage::disk('public')->response($photo->path, null, [
            'Cache-Control' => ($public ? 'public' : 'private').', max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Property $property): RedirectResponse
    {
        Gate::authorize('update', $property);
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.PropertyPhotoService::MAX_PHOTOS],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000'],
        ], [
            'photos.*.max' => 'Each photo must be 8 MB or smaller.',
            'photos.*.mimes' => 'Photos must be JPG, PNG or WebP.',
            'photos.*.dimensions' => 'Photos must be between 200 and 8000 pixels on each side.',
        ]);

        $room = PropertyPhotoService::MAX_PHOTOS - $property->photos()->count();
        $files = $request->file('photos');
        if (count($files) > $room) {
            throw ValidationException::withMessages(['photos' => 'A property can have up to '.PropertyPhotoService::MAX_PHOTOS." photos. You can add {$room} more."]);
        }

        $added = $this->photos->add($property, $files, $request->user());

        return back()->with('success', count($added) === 1 ? 'Photo added.' : count($added).' photos added.');
    }

    public function update(Request $request, PropertyPhoto $photo): RedirectResponse
    {
        Gate::authorize('update', $photo->property);
        $data = $request->validate(['caption' => ['nullable', 'string', 'max:120'], 'cover' => ['sometimes', 'boolean']]);

        if ($request->boolean('cover')) {
            $this->photos->makeCover($photo);

            return back()->with('success', 'Cover photo changed.');
        }
        $this->photos->updateCaption($photo, $data['caption'] ?? null);

        return back()->with('success', 'Caption saved.');
    }

    public function destroy(PropertyPhoto $photo): RedirectResponse
    {
        Gate::authorize('update', $photo->property);
        $this->photos->delete($photo);

        return back()->with('success', 'Photo removed.');
    }
}
