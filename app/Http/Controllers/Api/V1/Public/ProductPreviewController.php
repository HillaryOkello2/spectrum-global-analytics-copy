<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductPreviewResource;
use App\Models\Product;

/**
 * @group Public Catalogue
 *
 * Locked content preview: abstract + lock + subscribe prompt (FR-09, §14.6).
 */
class ProductPreviewController extends Controller
{
    public function __invoke(Product $product): ProductPreviewResource
    {
        abort_unless(
            $product->status === ProductStatus::Published && ! $product->is_hidden,
            404,
        );

        return new ProductPreviewResource($product->load('component'));
    }
}
