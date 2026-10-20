<?php

namespace App\Http\Controllers\Api\V1\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * @group Admin Portal
 */
class UnhideProductController extends Controller
{
    public function __invoke(Request $request, Product $product): ProductListResource
    {
        $product->update(['is_hidden' => false]);

        Audit::of($product)->by($request->user())
            ->change('is_hidden', true, false, 'Hidden from subscribers')
            ->log('product unhidden');

        return new ProductListResource($product);
    }
}
