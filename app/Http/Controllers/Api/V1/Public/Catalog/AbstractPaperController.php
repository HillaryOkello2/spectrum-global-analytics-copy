<?php

namespace App\Http\Controllers\Api\V1\Public\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\AbstractPaperResource;
use App\Services\Catalog\AbstractLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Public Catalogue
 *
 * Abstract Papers: the abstract of every published document in the catalogue,
 * newest first, whichever component wrote it.
 *
 * Each entry carries the `product` id of the document it was lifted from —
 * that is what a "read the full paper" control opens, through
 * GET /products/{product}, which applies the caller's tier as usual. This
 * listing itself is public and returns no bodies.
 *
 * `?component=DB` narrows it to one product line, `?search=` matches title or
 * abstract.
 */
class AbstractPaperController extends Controller
{
    public function __construct(
        private readonly AbstractLibrary $library,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->library->query();

        if (filled($component = $request->query('component'))) {
            $query->whereHas('component', fn (Builder $q) => $q
                ->where('public_id', $component)
                ->orWhere('code', $component));
        }

        if (filled($search = $request->query('search'))) {
            // Across the abstract as well as the title: in a list of abstracts,
            // searching only titles would ignore the text on the page.
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('abstract', 'like', "%{$search}%"));
        }

        return AbstractPaperResource::collection($query->paginate(20));
    }
}
