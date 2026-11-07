<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frontend design-token keys (component-1 … component-12). Pinned to the
     * known codes so colours never shift when another component is added.
     */
    private const COLOURS = [
        'DB' => 'component-1',
        'WH' => 'component-2',
        'MF' => 'component-3',
        'CC' => 'component-4',
        'ES' => 'component-5',
        'BS' => 'component-6',
        'RP' => 'component-7',
        'WP' => 'component-8',
        'HM' => 'component-9',
        'AP' => 'component-10',
    ];

    public function up(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->string('color', 32)->nullable()->after('sort_order');
            $table->unique('color');
        });

        foreach (self::COLOURS as $code => $color) {
            DB::table('components')->where('code', $code)->update(['color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropUnique(['color']);
            $table->dropColumn('color');
        });
    }
};
