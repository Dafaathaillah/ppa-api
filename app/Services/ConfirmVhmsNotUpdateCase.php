<?php

namespace App\Services;

use App\Models\User;
use App\Models\VhmsNotUpdateCase;
use App\Models\VhmsUnitConditionPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConfirmVhmsNotUpdateCase
{
    public function handle(VhmsNotUpdateCase $case, array $data, User $actor): VhmsNotUpdateCase
    {
        return DB::transaction(function () use ($case, $data, $actor): VhmsNotUpdateCase {
            $case = VhmsNotUpdateCase::query()->lockForUpdate()->findOrFail($case->id);
            $condition = $data['operational_condition'];
            $include = (bool) $data['include_in_breakdown'];
            $conditionStartedAt = Carbon::parse(
                $data['condition_started_at'],
                $data['client_timezone'] ?? config('app.timezone')
            )->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

            VhmsUnitConditionPeriod::query()
                ->where('list_unit_id', $case->list_unit_id)->whereNull('ended_at')->lockForUpdate()
                ->update(['ended_at' => $conditionStartedAt, 'updated_by' => $actor->name]);

            VhmsUnitConditionPeriod::create([
                'list_unit_id' => $case->list_unit_id, 'vhms_not_update_case_id' => $case->id,
                'condition' => $condition, 'started_at' => $conditionStartedAt,
                'note' => $data['confirmation_note'] ?? null, 'created_by' => $actor->name, 'updated_by' => $actor->name,
            ]);

            $case->update([
                'workflow_status' => $include ? VhmsNotUpdateCase::IN_HANDLING : VhmsNotUpdateCase::CONFIRMED,
                'operational_condition' => $condition, 'include_in_breakdown' => $include,
                'confirmation_note' => $data['confirmation_note'] ?? null,
                'initial_root_cause_problem_id' => $data['root_cause_problem_id'] ?? null,
                'confirmed_by' => $actor->name, 'confirmed_at' => now(),
            ]);

            return $case->refresh();
        });
    }
}
