<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ButlerIdentityRegistryService
{
    public function find(string $externalUserId): ?object
    {
        $externalUserId = trim($externalUserId);
        if ($externalUserId === '') {
            return null;
        }

        return DB::connection('butler_pgsql')
            ->table('butler_identity_bindings')
            ->where('external_user_id', $externalUserId)
            ->where('status', 'active')
            ->first();
    }

    public function assertOwnerProvisioningAllowed(string $externalUserId): ?object
    {
        $binding = $this->find($externalUserId);
        if (!$binding) {
            return null;
        }
        if ($binding->role !== 'owner') {
            throw new RuntimeException(
                "Zalo identity {$externalUserId} is already bound as {$binding->role}; owner provisioning is blocked."
            );
        }

        return $binding;
    }

    public function bind(
        string $externalUserId,
        string $tenantId,
        string $agentId,
        ?string $handoverId,
        string $role,
        string $displayName,
        string $familyName,
        string $butlerName,
        string $source
    ): void {
        $externalUserId = trim($externalUserId);
        if ($externalUserId === '') {
            return;
        }
        if (!preg_match('/^[0-9]{9,20}$/', $externalUserId)
            && !preg_match('/^phone_[0-9]{9,20}$/', $externalUserId)
            && !str_starts_with($externalUserId, 'group:')) {
            throw new RuntimeException('Only a verified Zalo UID, phone alias, or group identity may be bound.');
        }

        DB::connection('butler_pgsql')->select(
            <<<'SQL'
SELECT public.butler_register_identity(
    ?::text, ?::uuid, ?::uuid, ?::uuid, ?::text,
    ?::text, ?::text, ?::text, ?::text
)
SQL,
            [
                $externalUserId,
                $tenantId,
                $agentId,
                $handoverId,
                $role,
                $displayName,
                $familyName,
                $butlerName,
                $source,
            ]
        );
    }
}
