<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Stores, orders and removes a property's photos. Files live on the "public" disk under property-photos/{property id}/. */
class PropertyPhotoService
{
    public const MAX_PHOTOS = 20;

    private const MAX_EDGE = 1600;

    /**
     * @param  list<UploadedFile>  $files
     * @return list<PropertyPhoto>
     */
    public function add(Property $property, array $files, User $by): array
    {
        $stored = [];
        $paths = [];

        try {
            DB::transaction(function () use ($property, $files, $by, &$stored, &$paths) {
                // New photos go after the existing ones; on a property with none, the first upload becomes the cover (sort 0).
                $base = $property->photos()->exists() ? (int) $property->photos()->max('sort') + 1 : 0;
                foreach (array_values($files) as $i => $file) {
                    [$path, $width, $height] = $this->store($property, $file);
                    $paths[] = $path;
                    $stored[] = PropertyPhoto::create([
                        'property_id' => $property->id, 'path' => $path,
                        'sort' => $base + $i,
                        'width' => $width, 'height' => $height, 'uploaded_by' => $by->id,
                    ]);
                }
                Audit::log('property.photos_added', $property, ['photo_ids' => array_map(fn ($p) => $p->id, $stored)]);
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($paths); // no orphan files when the database write fails

            throw $e;
        }

        return $stored;
    }

    /** Moves a photo to the front so it becomes the cover. */
    public function makeCover(PropertyPhoto $photo): void
    {
        DB::transaction(function () use ($photo) {
            $others = PropertyPhoto::where('property_id', $photo->property_id)->whereKeyNot($photo->id)->orderBy('sort')->orderBy('id')->lockForUpdate()->get();
            $photo->update(['sort' => 0]);
            foreach ($others->values() as $i => $other) {
                $other->update(['sort' => $i + 1]);
            }
            Audit::log('property.photo_cover', $photo->property, ['photo_id' => $photo->id]);
        });
    }

    public function updateCaption(PropertyPhoto $photo, ?string $caption): void
    {
        $old = $photo->caption;
        $photo->update(['caption' => $caption]);
        Audit::log('property.photo_captioned', $photo->property, ['photo_id' => $photo->id, 'old' => $old, 'new' => $caption]);
    }

    public function delete(PropertyPhoto $photo): void
    {
        DB::transaction(function () use ($photo) {
            Audit::log('property.photo_deleted', $photo->property, ['photo_id' => $photo->id, 'path' => $photo->path]);
            $photo->delete();
        });
        Storage::disk('public')->delete($photo->path);
    }

    /**
     * Downscales to at most 1600px on the long edge and re-encodes as WebP (strips EXIF, incl. GPS).
     * Falls back to the original file when GD cannot read it.
     *
     * @return array{0:string, 1:?int, 2:?int}
     */
    private function store(Property $property, UploadedFile $file): array
    {
        $dir = "property-photos/{$property->id}";
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false || ! function_exists('imagewebp')) {
            $path = $file->storeAs($dir, Str::uuid().'.'.$file->extension(), 'public');
            $size = @getimagesize($file->getRealPath()) ?: [null, null];

            return [$path, $size[0], $size[1]];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagewebp($canvas, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        $path = "{$dir}/".Str::uuid().'.webp';
        Storage::disk('public')->put($path, $bytes);

        return [$path, $newWidth, $newHeight];
    }
}
