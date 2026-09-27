<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
    }

    private function fakeImage(): string
    {
        return base64_encode("\xFF\xD8\xFF\xE0fake-jpeg-bytes");
    }

    public function test_it_streams_a_product_photo_from_the_erp(): void
    {
        Http::fake([
            'erp.test/*documents/download*' => Http::response([
                'filename' => '02.LB.629_small.jpg',
                'content-type' => 'image/jpeg',
                'content' => $this->fakeImage(),
            ]),
            'erp.test/*documents*' => Http::response([
                ['relativename' => '02.LB.629.jpg'],
            ]),
        ]);

        $response = $this->get('/api/products/ref/02.LB.629/image');

        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(base64_decode($this->fakeImage()), $response->getContent());
    }

    /**
     * Photos are cached hard because they change only when someone uploads one,
     * and a catalog grid asks for fifty at a time.
     */
    public function test_photos_are_sent_with_a_long_cache_lifetime(): void
    {
        Http::fake([
            'erp.test/*documents/download*' => Http::response(['content-type' => 'image/jpeg', 'content' => $this->fakeImage()]),
            'erp.test/*documents*' => Http::response([['relativename' => 'A.jpg']]),
        ]);

        $this->get('/api/products/ref/A/image')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=604800, public');
    }

    /**
     * Roughly half the catalog has no photo. That is a normal state, and the
     * storefront falls back to its reference placeholder.
     */
    public function test_a_product_without_a_photo_answers_404(): void
    {
        Http::fake(['erp.test/*' => Http::response(['error' => 'not found'], 404)]);

        $this->get('/api/products/ref/NO-PHOTO/image')->assertNotFound();
    }

    public function test_a_product_whose_files_are_not_images_answers_404(): void
    {
        Http::fake(['erp.test/*documents*' => Http::response([['relativename' => 'datasheet.pdf']])]);

        $this->get('/api/products/ref/DOCS-ONLY/image')->assertNotFound();
    }

    /**
     * The reference becomes a path segment in the ERP request, so it must not
     * be able to climb out of the product directory.
     */
    public function test_a_traversing_reference_is_rejected_without_calling_the_erp(): void
    {
        Http::fake();

        $this->get('/api/products/ref/'.urlencode('../../conf').'/image')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_listing_advertises_an_image_url_for_every_product(): void
    {
        Http::fake(['erp.test/*' => Http::response([[
            'id' => '19902', 'ref' => 'DO-BS012', 'label' => 'Bushing', 'status' => '1',
            'cost_price' => 6.66, 'stock_reel' => 80,
        ]])]);

        $this->getJson('/api/products?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', route('products.image', ['ref' => 'DO-BS012']));
    }
}
