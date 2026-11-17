<?php

namespace Tests\Feature;

use Tests\TestCase;

/** A shared link (Facebook, Messenger, Viber...) shows a card with the logo image, the name and a description. */
class LinkPreviewTest extends TestCase
{
    public function test_shared_links_carry_open_graph_tags_with_a_full_image_url(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $image = asset('branding/og-image.jpg');
        $this->assertStringStartsWith('http', $image);
        foreach ([
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="'.e(config('app.name')).'">',
            '<meta property="og:image" content="'.$image.'">',
            '<meta property="og:image:width" content="1200">',
            '<meta property="og:image:height" content="630">',
            '<meta name="twitter:card" content="summary_large_image">',
        ] as $tag) {
            $this->assertStringContainsString($tag, $html);
        }
        // og:url must be the page itself. Pointing at "/" (which redirects to /login) made Facebook loop and show no card.
        $this->assertStringContainsString('<meta property="og:url" content="'.url('/login').'">', $html);
        $this->assertMatchesRegularExpression('/<meta property="og:title" content="[^"]+">/', $html);
        $this->assertMatchesRegularExpression('/<meta property="og:description" content="[^"]+">/', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]+">/', $html);
        $this->assertFileExists(public_path('branding/og-image.jpg'));
        [$w, $h] = getimagesize(public_path('branding/og-image.jpg'));
        $this->assertSame([1200, 630], [$w, $h]);
    }
}
