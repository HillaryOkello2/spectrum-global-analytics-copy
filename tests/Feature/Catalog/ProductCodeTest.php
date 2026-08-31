<?php

use App\Models\Component;
use App\Models\Product;
use App\Services\Catalog\ProductCodeService;
use Illuminate\Database\QueryException;

function allocate(Component $component): string
{
    return app(ProductCodeService::class)->allocate($component);
}

it('builds a code in the client DOCUMENT_REF format', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'DB', 'ref_code' => 'DB']);

    expect(allocate($component))->toBe('SGA.DB.001.08.26');
});

it('stamps the ref_code, not the catalogue code', function (): void {
    $this->travelTo('2026-08-15');

    // Three components' reference tokens differ from their catalogue code —
    // the document prints the reference, so that is what the code must carry.
    $component = Component::factory()->create(['code' => 'BS', 'ref_code' => 'BK']);

    expect(allocate($component))->toBe('SGA.BK.001.08.26');
});

it('increments the sequence within a component and month', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'DB', 'ref_code' => 'DB']);

    Product::factory()->for($component)->create(['code' => allocate($component)]);
    Product::factory()->for($component)->create(['code' => allocate($component)]);

    expect(allocate($component))->toBe('SGA.DB.003.08.26');
});

it('restarts the sequence in a new month', function (): void {
    $component = Component::factory()->create(['code' => 'DB', 'ref_code' => 'DB']);

    $this->travelTo('2026-08-15');
    Product::factory()->for($component)->create(['code' => allocate($component)]);

    $this->travelTo('2026-09-01');
    expect(allocate($component))->toBe('SGA.DB.001.09.26');
});

it('keeps sequences separate per component', function (): void {
    $this->travelTo('2026-08-15');

    $first = Component::factory()->create(['code' => 'DB', 'ref_code' => 'DB']);
    $second = Component::factory()->create(['code' => 'WH', 'ref_code' => 'WH']);

    Product::factory()->for($first)->create(['code' => allocate($first)]);
    Product::factory()->for($first)->create(['code' => allocate($first)]);

    expect(allocate($second))->toBe('SGA.WH.001.08.26');
});

it('orders past the padding width correctly', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'DB', 'ref_code' => 'DB']);

    // 99 sorts above 100 as a plain string; the allocator must not be fooled.
    Product::factory()->for($component)->create(['code' => 'SGA.DB.099.08.26']);
    Product::factory()->for($component)->create(['code' => 'SGA.DB.100.08.26']);

    expect(allocate($component))->toBe('SGA.DB.101.08.26');
});

it('does not count another month when sequencing', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'DB', 'ref_code' => 'DB']);

    // Same component, previous month, high sequence — must not carry over.
    Product::factory()->for($component)->create(['code' => 'SGA.DB.412.07.26']);

    expect(allocate($component))->toBe('SGA.DB.001.08.26');
});

it('rejects a duplicate code at the database level', function (): void {
    $component = Component::factory()->create();

    Product::factory()->for($component)->create(['code' => 'SGA.DB.001.08.26']);

    expect(fn () => Product::factory()->for($component)->create(['code' => 'SGA.DB.001.08.26']))
        ->toThrow(QueryException::class);
});
