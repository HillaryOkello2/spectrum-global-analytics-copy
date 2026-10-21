<?php

use App\Enums\Frequency;
use App\Models\Component;
use App\Models\SubscriptionTier;
use App\Models\TierAllocation;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('seeds the nine components of the client prompt pack', function (): void {
    expect(Component::generating()->orderBy('sort_order')->pluck('code')->all())
        ->toBe(['DB', 'WH', 'MF', 'CC', 'ES', 'BS', 'RP', 'WP', 'HM']);
});

it('seeds Abstract Papers as a virtual component that generates nothing', function (): void {
    $abstracts = Component::where('code', 'AP')->firstOrFail();

    // It carries the other nine's abstracts, so it has no prompt of its own,
    // no model assigned, and sorts last in the catalogue.
    expect($abstracts->isVirtual())->toBeTrue()
        ->and($abstracts->name)->toBe('Abstract Papers')
        ->and($abstracts->prompt_template)->toBeNull()
        ->and($abstracts->topic_prompt)->toBeNull()
        ->and($abstracts->assigned_llm_provider_id)->toBeNull()
        ->and($abstracts->generation_frequency)->toBeNull()
        ->and($abstracts->canCommissionTopics())->toBeFalse()
        ->and($abstracts->generatesOwnTopics())->toBeFalse()
        ->and($abstracts->sort_order)->toBe(Component::generating()->max('sort_order') + 1);
});

it('gives every component a prompt, a topic prompt and the vetting protocol', function (): void {
    // The nine that write. Abstract Papers has no prompt by design.
    Component::generating()->get()->each(function (Component $component): void {
        expect($component->prompt_template)->not->toBeEmpty()
            ->and($component->topic_prompt)->not->toBeEmpty()
            // The client's SGA-QCP-v2 audit protocol, seeded for all nine.
            ->and($component->qa_prompt_template)->toContain('QUALITY CONTROL')
            ->and($component->variables)->not->toBeEmpty()
            ->and($component->canCommissionTopics())->toBeTrue();
    });
});

it('stamps the reference codes the client prompts specify', function (): void {
    // Three components' document reference differs from their catalogue code.
    expect(Component::pluck('ref_code', 'code')->all())
        ->toMatchArray(['BS' => 'BK', 'CC' => 'CB', 'HM' => 'CS', 'DB' => 'DB']);
});

it('schedules only the pulse products for unattended generation', function (): void {
    $recurring = Component::whereNotNull('generation_frequency')
        ->pluck('generation_frequency', 'code')->all();

    expect($recurring)->toBe([
        'DB' => Frequency::Daily,
        'WH' => Frequency::Weekly,
        'MF' => Frequency::Monthly,
    ]);

    // CC is monthly in the client's title but was not named in the meeting
    // notes, so it stays admin-triggered until they confirm.
    expect(Component::where('code', 'CC')->value('generation_frequency'))->toBeNull();
});

it('gives each component its own generation queue', function (): void {
    expect(Component::generating()->pluck('queue_name')->all())
        ->toBe(['llm-db', 'llm-wh', 'llm-mf', 'llm-cc', 'llm-es', 'llm-bs', 'llm-rp', 'llm-wp', 'llm-hm'])
        ->and(Component::generating()->pluck('queue_name')->unique())->toHaveCount(9);
});

it('allocates every tier against every generating component', function (): void {
    expect(SubscriptionTier::count())->toBe(4)
        ->and(TierAllocation::count())->toBe(36);

    // No component may be missing from a tier, or entitlement falls through.
    SubscriptionTier::all()->each(
        fn (SubscriptionTier $tier) => expect($tier->allocations()->count())->toBe(9),
    );

    // Abstract Papers is the exception, and deliberately: it holds abstracts,
    // which are public to every visitor, so there is nothing to unlock.
    expect(TierAllocation::whereHas('component', fn ($q) => $q->where('code', 'AP'))->count())->toBe(0);
});

it('keeps every queue name in the horizon supervisor', function (): void {
    // A component whose queue no supervisor listens to would silently never
    // generate.
    $watched = config('horizon.defaults.supervisor-llm.queue');

    Component::generating()->pluck('queue_name')->each(
        fn (string $queue) => expect($watched)->toContain($queue),
    );
});
