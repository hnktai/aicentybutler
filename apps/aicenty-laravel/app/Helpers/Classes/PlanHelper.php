<?php

namespace App\Helpers\Classes;

use App\Models\Plan;
use App\Services\Common\MenuService;
use Illuminate\Support\Facades\Auth;

class PlanHelper
{
    public static function userPlanAiModel(): ?string
    {
        return self::userPlan()?->default_ai_model;
    }

    public static function userPlan()
    {
        if (Auth::check()) {
            $userId = Auth::id();
            $subscription = getCurrentActiveSubscription($userId);
            if ($subscription) {
                return Plan::getCache(
                    static function () use ($subscription) {
                        return Plan::query()->where('id', $subscription->plan_id)->first();
                    },
                    '_user_plan_' . $userId . '_' . $subscription->plan_id
                );
            }
        }

        return null;
    }

    private static function planControlledMenuKeys(): array
    {
        static $keys = null;

        if ($keys !== null) {
            return $keys;
        }

        $dataAiTools = array_map(static function ($item) {
            return $item['key'];
        }, MenuService::planAiToolsMenu());

        $dataFeature = array_map(static function ($item) {
            return $item['key'];
        }, MenuService::planFeatureMenu());

        return $keys = array_values(array_unique(array_merge(
            $dataAiTools,
            $dataFeature,
            ['ext_chat_bot_agent']
        )));
    }

    public static function menuRequiresUpgradeForNoPlan($plan, ?string $key = null): bool
    {
        if (Auth::user()?->isAdmin()) {
            return false;
        }

        if ($plan || ! $key) {
            return false;
        }

        return in_array($key, self::planControlledMenuKeys(), true);
    }

    public static function planMenuCheck($plan, ?string $key = null): bool
    {
        if (Auth::user()?->isAdmin()) {
            return true;
        }

        if ($key === 'ext_chat_bot_agent' && class_exists(\App\Extensions\Chatbot\System\Helpers\ChatbotHelper::class)) {
            return \App\Extensions\Chatbot\System\Helpers\ChatbotHelper::planAllowsHumanAgent($plan);
        }

        if (! $plan) {
            return true;
        }

        if (! $key) {
            return true;
        }

        $data = self::planControlledMenuKeys();

        if (! in_array($key, $data, true)) {
            return true;
        }

        $plan_ai_tools = (array) $plan->plan_ai_tools;

        $plan_features = (array) $plan->plan_features;

        $checkArray = array_merge($plan_ai_tools, $plan_features);

        if ($checkArray) {
            return array_key_exists($key, $checkArray) && $checkArray[$key];
        }

        return false;
    }
}
