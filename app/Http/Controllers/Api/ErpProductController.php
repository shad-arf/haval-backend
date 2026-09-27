<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DolibarrRequestException;
use App\Http\Controllers\Controller;
use App\Models\ProductOverride;
use App\Services\DolibarrClient;
use App\Services\PricingEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ErpProductController extends Controller
{
    public function __construct(
        private readonly DolibarrClient $dolibarr,
        private readonly PricingEngine $pricing,
    ) {}

    public function health(): JsonResponse
    {
        return $this->respond(function (): array {
            $this->dolibarr->products(1, 0, requireCost: false, requireStock: false);

            return ['connected' => true, 'service' => 'dolibarr'];
        });
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:0'],
            'brand' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:80', 'regex:/^[\p{L}\p{N} _.\-]+$/u'],
        ]);

        $limit = (int) ($input['limit'] ?? 24);
        $page = (int) ($input['page'] ?? 0);
        $brandId = (int) ($input['brand'] ?? 0);
        $search = trim((string) ($input['q'] ?? ''));

        return $this->respond(function () use ($limit, $page, $brandId, $search): array {
            $products = $this->dolibarr->products($limit, $page, $brandId, $search);

            // The ERP query already excludes unpriceable and out-of-stock parts,
            // but a product can still fail the rules locally when the ERP
            // reports per-warehouse stock that disagrees with its cached total.
            $catalog = [];

            foreach ($products as $product) {
                $entry = $this->catalogProduct($product);

                if ($entry !== null) {
                    $catalog[] = $entry;
                }
            }

            return [
                'data' => $catalog,
                'page' => $page,
                'limit' => $limit,
                'has_more' => count($products) === $limit,
                'currency' => 'IQD',
            ];
        });
    }

    public function brands(): JsonResponse
    {
        return $this->respond(function (): array {
            $brands = $this->dolibarr->brands();

            return [
                'data' => array_map(static fn (array $brand): array => [
                    'id' => (int) $brand['id'],
                    'name' => (string) $brand['label'],
                ], $brands),
            ];
        });
    }

    public function show(int $id): JsonResponse
    {
        return $this->respondProduct(fn (): array => $this->dolibarr->product($id));
    }

    public function byRef(string $ref): JsonResponse
    {
        return $this->respondProduct(fn (): array => $this->dolibarr->productByRef($ref));
    }

    /**
     * Shape one ERP product for the storefront, or null when it may not be sold.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>|null
     */
    private function catalogProduct(array $product): ?array
    {
        $sourceCost = $this->pricing->sourceCost($product);
        $quantity = $this->pricing->stockQuantity($product);

        if (! $this->pricing->isPublishable($sourceCost, $quantity)) {
            return null;
        }

        $override = ProductOverride::where('erp_product_id', (int) ($product['id'] ?? 0))->first();
        if ($override?->is_hidden) {
            return null;
        }

        $price = $this->pricing->sellingPrice($this->pricing->costInUsd($sourceCost), $override?->profit_percentage);
        $ref = (string) ($product['ref'] ?? '');
        $description = trim(strip_tags((string) ($product['description'] ?? '')));
        $shortDescription = trim(strip_tags((string) ($product['note_public'] ?? '')));

        return [
            'id' => (int) ($product['id'] ?? 0),
            'ref' => $ref,
            'name' => $override?->display_name ?: self::displayName($ref, (string) ($product['label'] ?? ''), $shortDescription, $description),
            'description' => $override?->description ?? $description,
            'short_description' => $shortDescription,
            'price' => $price,
            'currency' => 'IQD',
            'stock_quantity' => $quantity,
            'stock_status' => $this->pricing->stockStatus($quantity),
            'is_sellable' => (int) ($product['status'] ?? 0) === 1 && ($quantity === null || $quantity > 0),

            /**
             * Always present. Many parts have no photo, so the storefront
             * falls back to its placeholder when this 404s — cheaper than
             * asking the ERP about every product just to build a listing.
             */
            'image_url' => $ref === '' ? null : route('products.image', ['ref' => $ref]),
            'image_full_url' => $ref === '' ? null : route('products.image', ['ref' => $ref, 'size' => 'full']),
        ];
    }

    /**
     * Pick the best title for a part.
     *
     * Dolibarr's `label` is usually a duplicate of the part reference rather
     * than a real name, so fall back to the English name in `note_public` and
     * then the description before showing a bare code.
     */
    private static function displayName(string $ref, string $label, string $shortDescription, string $description): string
    {
        $label = trim($label);

        if ($label !== '' && $label !== $ref) {
            return $label;
        }

        foreach ([$shortDescription, $description, $ref, $label] as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $ref;
    }

    /**
     * Respond with a single product, treating a hidden product as not found.
     *
     * @param  callable(): array<string, mixed>  $fetch
     */
    private function respondProduct(callable $fetch): JsonResponse
    {
        return $this->respond(function () use ($fetch): array|JsonResponse {
            $product = $this->catalogProduct($fetch());

            if ($product === null) {
                return response()->json(['message' => 'Product not found.'], 404);
            }

            return $product;
        });
    }

    /**
     * @param  callable(): (array<mixed>|JsonResponse)  $callback
     */
    private function respond(callable $callback): JsonResponse
    {
        try {
            $result = $callback();

            return $result instanceof JsonResponse ? $result : response()->json($result);
        } catch (DolibarrRequestException $exception) {
            $status = $exception->upstreamStatus === 404 ? 404 : 502;

            return response()->json([
                'message' => $status === 404 ? 'Product not found.' : 'The catalog request failed.',
            ], $status);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json(['message' => 'The catalog is unavailable.'], 503);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'The catalog is unavailable.'], 503);
        }
    }
}
