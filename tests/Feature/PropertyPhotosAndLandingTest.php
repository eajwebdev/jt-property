<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Models\ActivityLog;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PropertyPhotosAndLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_owner_uploads_photos_which_are_resized_to_webp_and_audited(): void
    {
        $owner = User::factory()->owner()->create();
        $property = Property::factory()->create();

        $this->actingAs($owner)->post(route('properties.photos.store', $property), [
            'photos' => [UploadedFile::fake()->image('front.jpg', 3200, 2400), UploadedFile::fake()->image('room.png', 800, 600)],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $photos = $property->photos()->get();
        $this->assertCount(2, $photos);
        $this->assertSame([0, 1], $photos->pluck('sort')->all());
        $this->assertSame([1600, 1200], [$photos[0]->width, $photos[0]->height]); // long edge capped at 1600
        $this->assertSame([800, 600], [$photos[1]->width, $photos[1]->height]); // small photos are not upscaled
        $this->assertStringEndsWith('.webp', $photos[0]->path);
        Storage::disk('public')->assertExists($photos->pluck('path')->all());
        $this->assertTrue(ActivityLog::where('action', 'property.photos_added')->exists());
    }

    public function test_cover_caption_and_delete(): void
    {
        $owner = User::factory()->owner()->create();
        $property = Property::factory()->create();
        $this->actingAs($owner)->post(route('properties.photos.store', $property), ['photos' => [
            UploadedFile::fake()->image('a.jpg', 400, 300), UploadedFile::fake()->image('b.jpg', 400, 300), UploadedFile::fake()->image('c.jpg', 400, 300),
        ]]);
        [$a, $b, $c] = $property->photos()->get()->all();

        $this->patch(route('property-photos.update', $c), ['cover' => true])->assertRedirect();
        $this->assertSame([$c->id, $a->id, $b->id], $property->photos()->pluck('id')->all());

        $this->patch(route('property-photos.update', $a), ['caption' => 'Shared kitchen'])->assertRedirect();
        $this->assertSame('Shared kitchen', $a->fresh()->caption);

        $this->delete(route('property-photos.destroy', $b))->assertRedirect();
        $this->assertModelMissing($b);
        Storage::disk('public')->assertMissing($b->path);
        $this->assertTrue(ActivityLog::where('action', 'property.photo_deleted')->exists());
    }

    public function test_uploads_are_validated_and_limited(): void
    {
        $owner = User::factory()->owner()->create();
        $property = Property::factory()->create();
        $this->actingAs($owner);

        $this->post(route('properties.photos.store', $property), ['photos' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')]])
            ->assertSessionHasErrors('photos.0');
        $this->post(route('properties.photos.store', $property), ['photos' => [UploadedFile::fake()->image('tiny.jpg', 50, 50)]])
            ->assertSessionHasErrors('photos.0');

        PropertyPhoto::insert(collect(range(1, 20))->map(fn ($i) => ['property_id' => $property->id, 'path' => "x/{$i}.webp", 'sort' => $i, 'created_at' => now(), 'updated_at' => now()])->all());
        $this->post(route('properties.photos.store', $property), ['photos' => [UploadedFile::fake()->image('one-more.jpg', 400, 300)]])
            ->assertSessionHasErrors('photos');
    }

    public function test_only_the_owner_manages_photos(): void
    {
        $property = Property::factory()->create();
        $staff = User::factory()->create();
        $staff->properties()->attach($property);
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->post(route('properties.photos.store', $property), ['photos' => [UploadedFile::fake()->image('a.jpg', 400, 300)]]);
        $photo = $property->photos()->first();

        $this->actingAs($staff)->post(route('properties.photos.store', $property), ['photos' => [UploadedFile::fake()->image('b.jpg', 400, 300)]])->assertForbidden();
        $this->actingAs($staff)->patch(route('property-photos.update', $photo), ['cover' => true])->assertForbidden();
        $this->actingAs($staff)->delete(route('property-photos.destroy', $photo))->assertForbidden();
        $this->post('/logout');
        $this->post(route('properties.photos.store', $property), ['photos' => [UploadedFile::fake()->image('c.jpg', 400, 300)]])->assertRedirect(route('login'));
    }

    public function test_unlisted_photos_are_private_and_listed_ones_public(): void
    {
        $owner = User::factory()->owner()->create();
        $property = Property::factory()->create(['show_on_website' => false]);
        $this->actingAs($owner)->post(route('properties.photos.store', $property), ['photos' => [UploadedFile::fake()->image('a.jpg', 400, 300)]]);
        $photo = $property->photos()->first();

        $this->get(route('property-photos.show', $photo))->assertOk(); // the owner can see it
        $this->post('/logout');
        $this->get(route('property-photos.show', $photo))->assertNotFound(); // a visitor cannot

        $property->update(['show_on_website' => true]);
        $this->get(route('property-photos.show', $photo))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=604800, public');
    }

    public function test_landing_lists_only_published_properties_without_tenant_details(): void
    {
        $listed = Property::factory()->create(['name' => 'Creekside House', 'code' => 'CRK', 'show_on_website' => true, 'amenities' => 'Wifi, Water; Laundry']);
        Property::factory()->create(['name' => 'Secret Annex', 'show_on_website' => false]);
        Property::factory()->create(['name' => 'Old Place', 'show_on_website' => true, 'archived_at' => now()]);
        $unit = Unit::factory()->for($listed)->create(['code' => 'R1', 'capacity' => 4, 'status' => UnitStatus::Available]);
        Unit::factory()->for($listed)->create(['code' => 'R2', 'capacity' => 2, 'status' => UnitStatus::Unavailable]);
        $tenant = Tenant::factory()->create(['property_id' => $listed->id, 'full_name' => 'Private Person']);
        Occupancy::create(['tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'move_in_date' => today()->subMonth(), 'is_payer' => true]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->has('listings', 1)
            ->where('listings.0.name', 'Creekside House')
            ->where('listings.0.amenities', ['Wifi', 'Water', 'Laundry'])
            ->where('listings.0.rooms', 1) // unavailable rooms are not advertised
            ->where('listings.0.capacity', 4)
            ->where('listings.0.available', 3));

        $page = $this->get(route('listings.show', 'CRK'));
        $page->assertOk()->assertInertia(fn (Assert $p) => $p->component('listing')->where('listing.rooms.0.available', 3)->has('listing.rooms', 1));
        $this->assertStringNotContainsString('Private Person', $page->getContent());

        $hidden = Property::where('name', 'Secret Annex')->value('code');
        $this->get(route('listings.show', $hidden))->assertNotFound();
    }
}
