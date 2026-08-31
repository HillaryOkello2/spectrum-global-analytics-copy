<?php

namespace App\Http\Controllers\Api\V1\Subscriber;

use App\Enums\EntitlementLevel;
use App\Enums\ProductStatus;
use App\Exceptions\Domain\QuotaExhaustedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductPreviewResource;
use App\Http\Resources\ProductRedactedResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Analytics\ReadTracker;
use App\Services\Entitlement\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Subscriber Portal
 *
 * Entitlement-gated product view (FR-18, FR-32). Three levels of content:
 * the full document within the subscriber's tier allocation, the separately
 * proofread redacted document where the tier withholds the full one but a
 * redaction was approved, and otherwise the abstract alone. Metered exhaustion
 * with no redaction available is still a 403.
 *
 * A successful read at either content level is recorded by ReadTracker; a
 * locked preview is not, since nothing was actually read.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ReadTracker $reads,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        abort_unless(
            $product->status === ProductStatus::Published && ! $product->is_hidden,
            404,
        );

        $product->load('component')->loadCount('ratings')->loadAvg('ratings', 'stars');

        $result = $this->entitlements->check($request->user(), $product);

        if ($result->level === EntitlementLevel::MeteredExhausted) {
            throw new QuotaExhaustedException($result->used, $result->limit);
        }

        if ($result->grantsFullAccess()) {
            $this->reads->record($product, $request->user());

            return (new ProductResource($product))
                ->additional(['meta' => $result->meta()])
                ->response();
        }

        // Tier withholds the full document, but an approved redaction exists.
        if ($result->grantsRedactedAccess()) {
            $this->reads->record($product, $request->user());

            return (new ProductRedactedResource($product))
                ->additional(['meta' => [
                    ...$result->meta(),
                    'reason' => $result->level->value,
                ]])
                ->response();
        }

        return (new ProductPreviewResource($product))
            ->additional(['meta' => [
                ...$result->meta(),
                'reason' => $result->level->value,
            ]])
            ->response();
    }
}
