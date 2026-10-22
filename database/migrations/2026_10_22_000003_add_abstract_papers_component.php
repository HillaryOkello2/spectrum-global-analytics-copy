<?php

use App\Models\Component;
use Database\Seeders\ComponentSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Put the Abstract Papers component into a catalogue that already exists.
     *
     * A data migration rather than "re-run ComponentSeeder in production": the
     * seeder rewrites all nine components' prompts, providers, cadences and
     * queue names back to their seeded values, which is far more than adding a
     * row, and more than anyone wants to risk against live data. This adds the
     * one row and touches nothing else.
     *
     * The definition itself stays in ComponentSeeder, so a fresh install and an
     * existing database end up with the same component.
     *
     * Idempotent (updateOrCreate on `code`), so it is safe if a database has
     * already been seeded with it.
     */
    public function up(): void
    {
        ComponentSeeder::seedAbstractPapers();
    }

    /**
     * Removes the component but never the products — it owns none. Abstract
     * Papers has no products of its own; it presents other components'.
     */
    public function down(): void
    {
        Component::where('code', ComponentSeeder::ABSTRACT_PAPERS['code'])
            ->where('is_virtual', true)
            ->delete();
    }
};
