<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plan = $this->activePlan();
        $personalBalance = (float) $this->shared_credits;
        $teamBalance = 0.0;

        if ((bool) setting('shared_credit_system_enabled') && $this->isSharedCreditUser()) {
            $team = $this->myTeam;
            if ($team?->exists && $team->isSharedCreditTeam()) {
                $memberStat = $this->teamMember;
                $canUseTeamPool = (int) $team->user_id === (int) $this->id
                    || (
                        isset($memberStat)
                        && (int) $memberStat->team_id === (int) $team->id
                        && $memberStat->status === 'active'
                    );

                if ($canUseTeamPool) {
                    $teamBalance = (float) $team->shared_credits;
                }
            }
        }

        return [
            'balance'            => $personalBalance + $teamBalance,
            'personal_balance'   => $personalBalance,
            'team_pool_balance'  => $teamBalance,
            'credit_system_type' => $this->credit_system_type,
            'auto_topup'         => (bool) $this->shared_credit_auto_topup,
            'plan'               => $plan ? [
                'name'            => $plan->name,
                'credits_amount'  => (float) $plan->shared_credits_amount,
            ] : null,
        ];
    }
}
