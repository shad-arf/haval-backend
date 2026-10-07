<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
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

    public function test_a_missing_photo_answer_is_cached_by_the_browser(): void
    {
        Http::fake(['erp.test/*' => Http::response(['error' => 'not found'], 404)]);

        $this->get('/api/products/ref/NO-PHOTO/image')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function erpProduct(array $overrides = []): array
    {
        return array_merge([
            'id' => '19902', 'ref' => 'DO-BS012', 'label' => 'Bushing', 'status' => '1',
            'cost_price' => 6.66, 'stock_reel' => 80,
        ], $overrides);
    }

    public function test_the_listing_advertises_an_image_url_for_a_product_with_a_photo(): void
    {
        Http::fake([
            'erp.test/*documents*' => Http::response([['relativename' => 'DO-BS012.jpg']]),
            'erp.test/*products*' => Http::response([$this->erpProduct()]),
        ]);

        $this->getJson('/api/products?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', route('products.image', ['ref' => 'DO-BS012']));
    }

    /**
     * The storefront shows its placeholder straight away instead of asking
     * for an image that can only 404.
     */
    public function test_the_listing_gives_no_image_url_for_a_product_without_a_photo(): void
    {
        Http::fake([
            'erp.test/*documents*' => Http::response(['error' => 'not found'], 404),
            'erp.test/*products*' => Http::response([$this->erpProduct()]),
        ]);

        $this->getJson('/api/products?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', null)
            ->assertJsonPath('data.0.image_full_url', null);
    }

    public function test_products_with_a_photo_lead_the_page_in_their_original_order(): void
    {
        Http::fake([
            'erp.test/*documents*ref=PHOTO-1*' => Http::response([['relativename' => 'a.jpg']]),
            'erp.test/*documents*ref=PHOTO-2*' => Http::response([['relativename' => 'b.png']]),
            'erp.test/*documents*' => Http::response(['error' => 'not found'], 404),
            'erp.test/*products*' => Http::response([
                $this->erpProduct(['id' => '1', 'ref' => 'BARE-1']),
                $this->erpProduct(['id' => '2', 'ref' => 'PHOTO-1']),
                $this->erpProduct(['id' => '3', 'ref' => 'BARE-2']),
                $this->erpProduct(['id' => '4', 'ref' => 'PHOTO-2']),
            ]),
        ]);

        $refs = array_column($this->getJson('/api/products?limit=4')->assertOk()->json('data'), 'ref');

        $this->assertSame(['PHOTO-1', 'PHOTO-2', 'BARE-1', 'BARE-2'], $refs);
    }

    /**
     * The photo check shares the image endpoint's cache, so a second listing
     * and the image requests that follow it do not ask the ERP again.
     */
    public function test_photo_lookups_are_cached_between_listings_and_images(): void
    {
        Http::fake([
            'erp.test/*documents/download*' => Http::response(['content-type' => 'image/jpeg', 'content' => $this->fakeImage()]),
            'erp.test/*documents*' => Http::response([['relativename' => 'DO-BS012.jpg']]),
            'erp.test/*products*' => Http::response([$this->erpProduct()]),
        ]);

        $this->getJson('/api/products?limit=1')->assertOk();
        $this->getJson('/api/products?limit=1&page=1')->assertOk();
        $this->get('/api/products/ref/DO-BS012/image')->assertOk();

        Http::assertSentCount(4); // two catalog pages, one photo lookup, one download
    }

    /**
     * If the ERP cannot be reached for the photo check, the listing still
     * loads and the storefront falls back on the image request itself.
     */
    public function test_a_failed_photo_lookup_still_advertises_the_image(): void
    {
        Http::fake([
            'erp.test/*documents*' => fn () => throw new ConnectionException('timed out'),
            'erp.test/*products*' => Http::response([$this->erpProduct()]),
        ]);

        $this->getJson('/api/products?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', route('products.image', ['ref' => 'DO-BS012']));
    }
}
