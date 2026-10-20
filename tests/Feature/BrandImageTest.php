<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrandImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
    }

    public function test_brand_listing_includes_a_public_image_url_without_exposing_erp_credentials(): void
    {
        Http::fake(['erp.test/*' => Http::response([['id' => 123, 'label' => 'Brand']])]);

        $this->getJson('/api/brands')->assertOk()->assertExactJson([
            'data' => [['id' => 123, 'name' => 'Brand', 'image_url' => '/api/brands/123/image']],
        ]);
    }

    public function test_it_downloads_the_original_category_photo_from_the_correct_directory(): void
    {
        Http::fake([
            'erp.test/*documents/download*' => Http::response([
                'content' => base64_encode('brand-photo'), 'content-type' => 'image/png',
            ]),
            'erp.test/*documents*' => Http::response([
                ['relativename' => 'thumbs/logo_small.png'],
                ['relativename' => 'catalog.pdf'],
                ['relativename' => 'logo.png'],
            ]),
        ]);

        $this->get('/api/brands/123/image')->assertOk()->assertContent('brand-photo')
            ->assertHeader('Content-Type', 'image/png');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'documents/download')
            && $request['modulepart'] === 'category'
            && $request['original_file'] === '3/2/123/photos/logo.png');
    }

    public function test_a_brand_without_a_photo_returns_404(): void
    {
        Http::fake(['erp.test/*' => Http::response([], 404)]);
        $this->get('/api/brands/1/image')->assertNotFound();
    }

    public function test_it_rejects_non_images_and_unsafe_document_paths(): void
    {
        Http::fake(['erp.test/*' => Http::response([
            ['relativename' => '../private.png'], ['relativename' => 'brand.svg'],
        ])]);
        $this->get('/api/brands/2/image')->assertNotFound();
        Http::assertSentCount(1);
    }
}
