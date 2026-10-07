<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DolibarrClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Serves product photos out of the Dolibarr document store.
 *
 * Photos are filed there by product reference. The browser is never given a
 * Dolibarr URL — that would need the ERP API key — so the bytes are proxied
 * through here instead.
 */
class ProductImageController extends Controller
{
    /** Photos change rarely, so they are cached hard at both hops. */
    private const CACHE_SECONDS = 604800;

    public function __construct(private readonly DolibarrClient $dolibarr) {}

    public function show(Request $request, string $ref): SymfonyResponse
    {
        // The reference becomes a path segment in the ERP request, so anything
        // that could climb out of the product directory is rejected outright.
        if (preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $ref) !== 1 || str_contains($ref, '..')) {
            return response()->noContent(400);
        }

        $thumbnail = $request->query('size') !== 'full';
        $cacheKey = sprintf('product-image.%s.%s', $thumbnail ? 'small' : 'full', md5($ref));

        $image = Cache::remember($cacheKey, now()->addHours(6), function () use ($ref, $thumbnail): ?array {
            $path = $this->dolibarr->productImagePath($ref, $thumbnail);

            if ($path === null) {
                return null;
            }

            $file = $this->dolibarr->downloadDocument('product', $path);

            // Older products predate thumbnail generation; fall back to the
            // original rather than showing nothing.
            if ($file === null && $thumbnail) {
                $full = $this->dolibarr->productImagePath($ref, false);
                $file = $full !== null ? $this->dolibarr->downloadDocument('product', $full) : null;
            }

            return $file;
        });

        if ($image === null) {
            // No photo is a normal state for many parts, not an error. The
            // storefront falls back to its reference placeholder. The answer
            // is cached briefly so the browser stops asking on every page,
            // yet a newly uploaded photo still shows up within the hour.
            return response()->noContent(404)->setPublic()->setMaxAge(3600);
        }

        return new Response($image['content'], 200, [
            'Content-Type' => $image['mime'],
            'Content-Length' => (string) strlen($image['content']),
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }
}
