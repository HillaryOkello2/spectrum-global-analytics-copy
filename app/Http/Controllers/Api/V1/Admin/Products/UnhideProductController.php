<?php

namespace App\Http\Controllers\Api\V1\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * @group Admin Portal
 */
class UnhideProductController extends Controller
{
    public function __invoke(Request $request, Product $product): ProductListResource
    {
        $product->update(['is_hidden' => false]);

        activity()->causedBy($request->user())->performedOn($product)->log('product unhidden');

        return new ProductListResource($product);
    }
}
