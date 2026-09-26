<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * min/max are now derived from the shared choice key and is_required (see
     * AddonGroup::booted). Bring every existing group in line, keeping what
     * each one already meant to customers:
     *  - a group that was already "choose one" (max 1) but has no key gets its
     *    own key, so it stays choose-one instead of turning unlimited;
     *  - a keyed group is max 1; an un-keyed group is unlimited (0);
     *  - required groups need one pick.
     */
    public function up(): void
    {
        DB::table('addon_groups')->orderBy('id')->each(function ($group): void {
            $key = $group->exclusive_key;

            if (! filled($key) && (int) $group->max_select === 1) {
                $key = substr(Str::slug($group->name), 0, 50).'-'.$group->id;
            }

            DB::table('addon_groups')->where('id', $group->id)->update([
                'exclusive_key' => filled($key) ? $key : null,
                'max_select' => filled($key) ? 1 : 0,
                'min_select' => $group->is_required ? 1 : 0,
            ]);
        });
    }

    public function down(): void
    {
        // Irreversible on purpose: the previous limits were free-form numbers.
    }
};
