<?php

declare(strict_types=1);

namespace App\Helpers\Classes;

use App\Domains\Entity\Enums\EntityEnum;
use App\Models\User;
use App\Models\UserOrder;
use Throwable;

class OpenRouterFreeAccess
{
    /**
     * Free OpenRouter models that should stay available to free users.
     *
     * @return array<int, EntityEnum>
     */
    public static function modelEnums(): array
    {
        return [
            EntityEnum::OPENAI_GPT_OSS_20B_FREE,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function modelValues(): array
    {
        return array_map(static fn (EntityEnum $model): string => $model->value, self::modelEnums());
    }

    /**
     * @return array<int, string>
     */
    public static function modelSlugs(): array
    {
        return array_map(static fn (EntityEnum $model): string => $model->slug(), self::modelEnums());
    }

    public static function isEnabled(): bool
    {
        return (int) setting('open_router_status', 0) === 1 && ! empty(setting('open_router_api'));
    }

    public static function hasActivePlan(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return getCurrentActiveSubscription($user->id) !== null
            || getCurrentActiveSubscriptionYokkasa($user->id) !== null;
    }

    public static function canUseFor(?User $user): bool
    {
        return $user instanceof User
            && $user->isUser()
            && self::isEnabled()
            && ! self::hasActivePlan($user)
            && ! self::hasSuccessfulPaidOrder($user);
    }

    public static function hasSuccessfulPaidOrder(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return UserOrder::query()
            ->where('user_id', $user->id)
            ->whereNotNull('plan_id')
            ->where('price', '>', 0)
            ->where(static function ($query) {
                $query->where('status', 'Success')
                    ->orWhere('status', 'Approved')
                    ->orWhere('status', 'like', 'Success%');
            })
            ->exists();
    }

    public static function isFreeModel(EntityEnum|string|null $model): bool
    {
        if ($model === null) {
            return false;
        }

        try {
            $enum = $model instanceof EntityEnum ? $model : EntityEnum::fromSlug($model);
        } catch (Throwable) {
            return false;
        }

        return in_array($enum, self::modelEnums(), true);
    }

    public static function defaultModelValue(): string
    {
        $configured = setting('default_open_router_model', EntityEnum::OPENAI_GPT_OSS_20B_FREE->slug());

        try {
            $enum = EntityEnum::fromSlug((string) $configured);
            if (in_array($enum, self::modelEnums(), true)) {
                return $enum->value;
            }
        } catch (Throwable) {
        }

        return EntityEnum::OPENAI_GPT_OSS_20B_FREE->value;
    }

    public static function defaultModelSlug(): string
    {
        return self::defaultModelValue();
    }

    /**
     * Free OpenRouter models exposed to a no-plan user in the model picker.
     *
     * @return array<int, string>
     */
    public static function exposedModelValues(?User $user): array
    {
        return self::canUseFor($user) ? self::modelValues() : [];
    }
}
