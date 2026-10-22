<?php

use App\Console\Commands\DiagnoseGenerationCommand;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the QA gate writes exist in the schema.
 *
 * This is the test that was missing on 2026-10-20. `qa_verdict` and `qa_round`
 * were added by editing `create_generation_tasks_table` in place, which already
 * had its name in the `migrations` table everywhere it had run. Fresh installs
 * — including every test run — got the columns and passed; production did not,
 * `migrate` reported "Nothing to migrate", and every audit died on
 * `Unknown column 'qa_verdict'` after the model had been paid to write it.
 *
 * A test against a freshly built schema cannot catch that on its own, since the
 * fresh build is exactly the case that works. What it can catch is the drift
 * check itself going stale — naming a column that does not exist, or missing
 * one that was added the same bad way since. A check that cries wolf during an
 * incident is worse than no check.
 */
it('names only columns that really exist', function (): void {
    $wrong = [];

    foreach (DiagnoseGenerationCommand::REQUIRED_COLUMNS as $table => $columns) {
        if (! Schema::hasTable($table)) {
            $wrong[] = "{$table} (no such table)";

            continue;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $wrong[] = "{$table}.{$column}";
            }
        }
    }

    expect($wrong)->toBe([], 'generation:diagnose names columns that do not exist: '.implode(', ', $wrong));
});

it('reports a clean bill of health on a correctly migrated database', function (): void {
    $this->artisan('generation:diagnose')
        ->doesntExpectOutputToContain('Schema drift')
        ->assertSuccessful();
});

it('covers every column the QA gate writes', function (): void {
    // The specific pair that broke production. Named explicitly rather than
    // left to the loop above, so deleting them from the list fails loudly.
    expect(DiagnoseGenerationCommand::REQUIRED_COLUMNS['generation_tasks'])
        ->toContain('qa_verdict')
        ->toContain('qa_round');

    expect(Schema::hasColumn('generation_tasks', 'qa_verdict'))->toBeTrue()
        ->and(Schema::hasColumn('generation_tasks', 'qa_round'))->toBeTrue();
});

it('adds the QA columns through a migration of their own, not an edit to the original', function (): void {
    // The create migration must not carry them: it had already run everywhere
    // by the time the QA gate was built, so a column written there reaches
    // fresh installs and nobody else.
    $create = file_get_contents(
        database_path('migrations/2026_07_14_080006_create_generation_tasks_table.php'),
    );

    $repair = database_path('migrations/2026_10_20_000003_add_qa_verdict_to_generation_tasks_table.php');

    expect($create)->not->toContain("\$table->string('qa_verdict'")
        ->and($create)->not->toContain("\$table->unsignedTinyInteger('qa_round')")
        ->and(is_file($repair))->toBeTrue()
        ->and(file_get_contents($repair))->toContain('qa_verdict')->toContain('qa_round');
});
