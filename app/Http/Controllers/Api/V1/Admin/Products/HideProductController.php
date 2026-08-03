<?php

namespace App\Http\Controllers\Api\V1\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * @group Admin Portal
 *
 * Vault Hide action: immediately removes a Product from subscriber visibility,
 * enforced at the data layer (FR-43, §20 Content Control).
 */
class HideProductController extends Controller
{
    public function __invoke(Request $request, Product $product): ProductListResource
    {
        $product->update(['is_hidden' => true]);

        activity()->causedBy($request->user())->performedOn($product)->log('product hidden from subscribers');

        return new ProductListResource($product);
    }
}
