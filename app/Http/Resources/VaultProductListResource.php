<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Vault listing row (FR-43): the public listing entry plus the workflow state
 * only staff see — status, the hidden flag, and how many times it has been read.
 *
 * The same status/hidden pair is still published under the collection's
 * `meta.statuses`, keyed by publicId, for clients written against that shape.
 */
class VaultProductListResource extends ProductListResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'status' => $this->status->value,
            'isHidden' => $this->is_hidden,
            'readsCount' => (int) $this->reads_count,
        ];
    }
}
