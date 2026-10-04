<?php

namespace App\Services;

use App\Exceptions\DolibarrRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DolibarrClient
{
    /**
     * Fetch a page of catalog products.
     *
     * Storefront rules (a product needs a cost to be priced, and out-of-stock
     * parts are hidden) are pushed into the ERP query rather than applied after
     * the fact. Filtering here keeps Dolibarr's own paging aligned with what the
     * storefront shows — otherwise a page of 50 could arrive almost empty and
     * `has_more` would be meaningless.
     *
     * @return array<int, array<string, mixed>>
     */
    public function products(
        int $limit = 24,
        int $page = 0,
        int $brandId = 0,
        string $search = '',
        bool $requireCost = true,
        bool $requireStock = true,
    ): array {
        $query = [
            'limit' => $limit,
            'page' => $page,
            'mode' => 1,
            'sortfield' => 't.rowid',
            'sortorder' => 'DESC',
            'includestockdata' => 1,
        ];

        if ($brandId > 0) {
            $query['category'] = $brandId;
        }

        $filters = [];

        if ($requireCost) {
            $filters[] = '(t.cost_price:>:0)';
        }

        if ($requireStock) {
            $filters[] = '(t.stock:>:0)';
        }

        if ($search !== '') {
            $term = self::escapeFilterValue($search);
            $filters[] = "((t.ref:like:'%".$term."%') or (t.label:like:'%".$term."%'))";
        }

        if ($filters !== []) {
            $query['sqlfilters'] = implode(' and ', $filters);
        }

        return $this->get('products', $query);
    }

    /**
     * Whether Dolibarr's own row for this product satisfies a raw-column
     * condition, checked directly against the ERP rather than against
     * anything this app has fetched.
     *
     * products() filters the catalog on the raw `t.cost_price`/`t.stock`
     * columns before PricingEngine's fallback fields (`cost`, `pmp`,
     * `stock_reel`) ever come into play. A product can pass every local
     * check — because it has a usable cost or stock through a fallback
     * field — and still never reach the storefront because Dolibarr's own
     * query drops it first. This is how the admin product page tells the
     * two apart instead of just calling every such product "visible."
     */
    public function matchesRawColumnFilter(int $id, string $condition): bool
    {
        try {
            $result = $this->get('products', [
                'limit' => 1,
                'sqlfilters' => "(t.rowid:=:{$id}) and ({$condition})",
            ]);
        } catch (DolibarrRequestException $exception) {
            if ($exception->upstreamStatus === 404) {
                return false;
            }

            throw $exception;
        }

        return $result !== [];
    }

    /**
     * Product categories, used as storefront brands.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brands(): array
    {
        return Cache::remember('dolibarr.brands', now()->addMinutes(15), fn (): array => $this->get('categories', [
            'type' => 'product',
            'limit' => 100,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function product(int $id): array
    {
        return $this->get('products/'.$id, ['includestockdata' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    public function productByRef(string $ref): array
    {
        return $this->get('products/ref/'.rawurlencode($ref), ['includestockdata' => 1]);
    }

    /**
     * The document-store path of a product's photo, relative to the product
     * module root, or null when the product has no photo.
     *
     * Photos are filed by product reference. Dolibarr also generates `_small`
     * and `_mini` thumbnails, which are what the catalog grid wants — sending
     * a 35 KB original for a card-sized image wastes most of the bytes.
     *
     * The lookup is cached because it costs an ERP round trip and the answer
     * changes only when someone uploads a photo.
     */
    public function productImagePath(string $ref, bool $thumbnail = true): ?string
    {
        $cacheKey = sprintf('dolibarr.image.%s.%s', $thumbnail ? 'small' : 'full', md5($ref));

        $path = Cache::remember($cacheKey, now()->addHours(6), function () use ($ref, $thumbnail): string {
            try {
                $files = $this->get('documents', ['modulepart' => 'product', 'ref' => $ref]);
            } catch (DolibarrRequestException) {
                // A product with no photo answers 404, which is not an error here.
                return '';
            }

            $original = null;

            foreach ($files as $file) {
                $name = (string) ($file['relativename'] ?? '');

                // Skip the thumbnails Dolibarr lists alongside the original.
                if ($name === '' || str_contains($name, 'thumbs/') || str_contains($name, 'thumbs\\')) {
                    continue;
                }

                if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $name) === 1) {
                    $original = $name;
                    break;
                }
            }

            if ($original === null) {
                return '';
            }

            if (! $thumbnail) {
                return $ref.'/'.$original;
            }

            // Dolibarr names thumbnails "<basename>_small.<ext>" beside the original.
            $extension = pathinfo($original, PATHINFO_EXTENSION);
            $base = pathinfo($original, PATHINFO_FILENAME);

            return sprintf('%s/thumbs/%s_small.%s', $ref, $base, $extension);
        });

        return $path === '' ? null : $path;
    }

    /**
     * Download a file from the document store.
     *
     * Dolibarr returns the bytes base64-encoded inside JSON rather than as a
     * binary body, so the content is decoded here.
     *
     * @return array{content: string, mime: string}|null
     */
    public function downloadDocument(string $modulePart, string $relativePath): ?array
    {
        try {
            $result = $this->get('documents/download', [
                'modulepart' => $modulePart,
                'original_file' => $relativePath,
            ]);
        } catch (DolibarrRequestException) {
            return null;
        }

        $content = base64_decode((string) ($result['content'] ?? ''), true);

        if ($content === false || $content === '') {
            return null;
        }

        return [
            'content' => $content,
            'mime' => (string) ($result['content-type'] ?? 'application/octet-stream'),
        ];
    }

    /**
     * Find an existing document by the client reference we stamped on it.
     *
     * This is the duplicate guard for the order push: the same reference can
     * never produce two documents. requirements.md §2.2 asks the ERP team to
     * confirm `sqlfilters` on `ref_client` is permitted on their instance.
     *
     * Returns 0 when nothing matches.
     */
    public function findByClientRef(string $endpoint, string $clientRef): int
    {
        $filter = "(t.ref_client:=:'".self::escapeFilterValue($clientRef)."')";

        try {
            $result = $this->get($endpoint, ['limit' => 1, 'sqlfilters' => $filter]);
        } catch (DolibarrRequestException $exception) {
            // Dolibarr answers an empty result set with a 404 rather than [].
            if ($exception->upstreamStatus === 404) {
                return 0;
            }

            throw $exception;
        }

        return (int) ($result[0]['id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOrder(array $payload): int
    {
        return $this->createDocument('orders', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createInvoice(array $payload): int
    {
        return $this->createDocument('invoices', $payload);
    }

    public function validateInvoice(int $invoiceId, int $warehouseId = 0): void
    {
        $this->post('invoices/'.$invoiceId.'/validate', $warehouseId > 0 ? ['idwarehouse' => $warehouseId] : []);
    }

    /**
     * One invoice, including the amount still owed (remaintopay).
     *
     * @return array<string, mixed>
     */
    public function invoice(int $invoiceId): array
    {
        return $this->get('invoices/'.$invoiceId);
    }

    /**
     * Pays the whole remaining balance of an invoice; returns the payment id.
     *
     * @param  array<string, mixed>  $payment
     */
    public function addInvoicePayment(int $invoiceId, array $payment): int
    {
        return (int) $this->post('invoices/'.$invoiceId.'/payments', $payment);
    }

    /**
     * The id of the customer third party whose customer code (code_client)
     * is exactly $code, or 0 when there is none.
     */
    public function findCustomerByCode(string $code): int
    {
        $filter = "(t.code_client:=:'".self::escapeFilterValue($code)."')";

        try {
            $result = $this->get('thirdparties', ['limit' => 1, 'mode' => 1, 'sqlfilters' => $filter]);
        } catch (DolibarrRequestException $exception) {
            if ($exception->upstreamStatus === 404) {
                return 0;
            }

            throw $exception;
        }

        return (int) ($result[0]['id'] ?? 0);
    }

    /**
     * The line markers already present on a document, used to avoid writing a
     * line twice when a push is retried.
     *
     * @return array<int, string>
     */
    public function documentLineMarkers(string $endpoint, int $documentId): array
    {
        $document = $this->get($endpoint.'/'.$documentId);
        $markers = [];

        foreach ((array) ($document['lines'] ?? []) as $line) {
            if (preg_match('/\[[A-Z0-9\-]+-ITEM-\d+\]/', (string) ($line['desc'] ?? ''), $found) === 1) {
                $markers[] = $found[0];
            }
        }

        return $markers;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function addDocumentLine(string $endpoint, int $documentId, array $line): int
    {
        $result = $this->post($endpoint.'/'.$documentId.'/lines', $line);

        return is_numeric($result) ? (int) $result : (int) ($result['id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException
     */
    private function createDocument(string $endpoint, array $payload): int
    {
        $result = $this->post($endpoint, $payload);
        $id = is_numeric($result) ? (int) $result : (int) ($result['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException(sprintf('Dolibarr returned no ID when creating a %s record.', $endpoint));
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>|int|string
     *
     * @throws DolibarrRequestException|RuntimeException
     */
    private function post(string $path, array $payload)
    {
        [$baseUrl, $apiKey] = $this->connection();

        $response = Http::acceptJson()
            ->withHeaders(['DOLAPIKEY' => $apiKey])
            ->timeout(max(1, (int) config('dolibarr.timeout')))
            ->post($baseUrl.'/'.ltrim($path, '/'), $payload);

        if (! $response->successful()) {
            throw new DolibarrRequestException($response->status());
        }

        $data = $response->json();

        if ($data === null) {
            throw new RuntimeException('Dolibarr returned an invalid response.');
        }

        return $data;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws RuntimeException
     */
    private function connection(): array
    {
        $baseUrl = rtrim((string) config('dolibarr.url'), '/');
        $apiKey = (string) config('dolibarr.key');

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Dolibarr API is not configured.');
        }

        return [$baseUrl, $apiKey];
    }

    /**
     * Strip characters that would break out of a quoted `sqlfilters` literal.
     *
     * Requests are validated before they reach here; this is the second line of
     * defence so no caller can inject filter syntax into the ERP query.
     */
    private static function escapeFilterValue(string $value): string
    {
        return str_replace(["'", '"', '\\', '(', ')', ':', '%'], '', $value);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        [$baseUrl, $apiKey] = $this->connection();

        $response = Http::acceptJson()
            ->withHeaders(['DOLAPIKEY' => $apiKey])
            ->timeout(max(1, (int) config('dolibarr.timeout')))
            ->get($baseUrl.'/'.ltrim($path, '/'), $query);

        if (! $response->successful()) {
            throw new DolibarrRequestException($response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Dolibarr returned an invalid response.');
        }

        return $data;
    }
}
