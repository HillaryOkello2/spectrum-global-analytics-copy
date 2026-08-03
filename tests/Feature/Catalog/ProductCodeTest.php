<?php

use App\Enums\Frequency;
use App\Models\Component;
use App\Models\Product;
use App\Models\Topic;
use App\Services\Catalog\ProductCodeService;

function allocate(Component $component, ?Topic $topic = null): string
{
    return app(ProductCodeService::class)->allocate($component, $topic);
}

it('builds a code from the component, period and sequence', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'A4']);
    $topic = Topic::factory()->for($component)->create(['frequency' => Frequency::Daily]);

    expect(allocate($component, $topic))->toBe('SGA.A4.2026-08.001');
});

it('uses a quarter for quarterly series', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'A7']);
    $topic = Topic::factory()->for($component)->create(['frequency' => Frequency::Quarterly]);

    expect(allocate($component, $topic))->toBe('SGA.A7.2026-Q3.001');
});

it('increments the sequence within a component and period', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'A4']);

    Product::factory()->for($component)->create(['code' => allocate($component)]);
    Product::factory()->for($component)->create(['code' => allocate($component)]);

    expect(allocate($component))->toBe('SGA.A4.2026-08.003');
});

it('restarts the sequence in a new period', function (): void {
    $component = Component::factory()->create(['code' => 'A4']);

    $this->travelTo('2026-08-15');
    Product::factory()->for($component)->create(['code' => allocate($component)]);

    $this->travelTo('2026-09-01');
    expect(allocate($component))->toBe('SGA.A4.2026-09.001');
});

it('keeps sequences separate per component', function (): void {
    $this->travelTo('2026-08-15');

    $first = Component::factory()->create(['code' => 'A1']);
    $second = Component::factory()->create(['code' => 'A2']);

    Product::factory()->for($first)->create(['code' => allocate($first)]);
    Product::factory()->for($first)->create(['code' => allocate($first)]);

    expect(allocate($second))->toBe('SGA.A2.2026-08.001');
});

it('orders past the padding width correctly', function (): void {
    $this->travelTo('2026-08-15');

    $component = Component::factory()->create(['code' => 'A4']);

    // 99 sorts above 100 as a plain string; the allocator must not be fooled.
    Product::factory()->for($component)->create(['code' => 'SGA.A4.2026-08.099']);
    Product::factory()->for($component)->create(['code' => 'SGA.A4.2026-08.100']);

    expect(allocate($component))->toBe('SGA.A4.2026-08.101');
});

it('rejects a duplicate code at the database level', function (): void {
    $component = Component::factory()->create();

    Product::factory()->for($component)->create(['code' => 'SGA.A4.2026-08.001']);

    expect(fn () => Product::factory()->for($component)->create(['code' => 'SGA.A4.2026-08.001']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
