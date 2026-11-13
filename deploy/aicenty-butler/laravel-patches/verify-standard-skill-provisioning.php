<?php

declare(strict_types=1);

use App\Services\ButlerAutomationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = DB::connection('butler_pgsql');
$tenantId = (string) Str::uuid();
$agentId = (string) Str::uuid();
$slugs = [
    'document-to-pdf',
    'images-to-pdf',
    'quangia-image-generation',
    'quangia-mobile-messaging',
];

$db->beginTransaction();
try {
    $db->table('tenants')->insert([
        'id' => $tenantId,
        'name' => 'Internal Provisioning Simulation',
        'slug' => 'internal-provisioning-simulation-' . substr($tenantId, 0, 8),
        'status' => 'active',
        'settings' => json_encode(['internal_simulation' => true], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $master = $db->table('agents')
        ->where('tenant_id', '0193a5b0-7000-7000-8000-000000000001')
        ->where('agent_key', 'aicenty-master-butler')
        ->whereNull('deleted_at')
        ->first();
    if (!$master) {
        throw new RuntimeException('Master Butler template is missing.');
    }

    $agent = (array) $master;
    $agent['id'] = $agentId;
    $agent['tenant_id'] = $tenantId;
    $agent['display_name'] = 'Internal Simulation Butler';
    $agent['owner_id'] = 'internal-simulation';
    $agent['created_at'] = now();
    $agent['updated_at'] = now();
    unset($agent['tsv']);
    $db->table('agents')->insert($agent);

    $service = $app->make(ButlerAutomationService::class);
    $firstPins = $service->ensureStandardButlerSkillsForAgent($tenantId, $agentId);
    $firstGrants = $db->table('skill_agent_grants as grant')
        ->join('skills as skill', 'skill.id', '=', 'grant.skill_id')
        ->where('grant.agent_id', $agentId)
        ->whereIn('skill.slug', $slugs)
        ->orderBy('skill.slug')
        ->pluck('grant.id', 'skill.slug')
        ->all();

    $pdfSkillId = $db->table('skills')
        ->where('slug', 'document-to-pdf')
        ->where('is_system', true)
        ->value('id');
    $db->table('skill_agent_grants')
        ->where('skill_id', $pdfSkillId)
        ->where('agent_id', $agentId)
        ->update(['pinned_version' => 0]);

    $secondPins = $service->ensureStandardButlerSkillsForAgent($tenantId, $agentId);
    $secondGrants = $db->table('skill_agent_grants as grant')
        ->join('skills as skill', 'skill.id', '=', 'grant.skill_id')
        ->where('grant.agent_id', $agentId)
        ->whereIn('skill.slug', $slugs)
        ->orderBy('skill.slug')
        ->get(['grant.id', 'skill.slug', 'grant.pinned_version']);

    $idsStable = $secondGrants->every(
        fn ($grant): bool => ($firstGrants[$grant->slug] ?? null) === $grant->id
    );
    $pinsRepaired = $secondGrants->every(
        fn ($grant): bool => (int) $grant->pinned_version === (int) $secondPins[$grant->slug]
    );
    $tenantCatalogCount = $db->table('skills')
        ->where('tenant_id', $tenantId)
        ->whereIn('slug', ['quangia-image-generation', 'quangia-mobile-messaging'])
        ->count();

    if (count($firstPins) !== 4 || count($secondPins) !== 4 || $secondGrants->count() !== 4) {
        throw new RuntimeException('Expected exactly four standard skill grants.');
    }
    if (!$idsStable || !$pinsRepaired || $tenantCatalogCount !== 2) {
        throw new RuntimeException('Idempotency or tenant catalog verification failed.');
    }

    echo json_encode([
        'result' => 'PASS',
        'first_pins' => $firstPins,
        'second_pins' => $secondPins,
        'grant_ids_stable' => $idsStable,
        'pins_repaired' => $pinsRepaired,
        'tenant_catalog_count' => $tenantCatalogCount,
        'grant_count' => $secondGrants->count(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $db->rollBack();
}

if ($db->table('tenants')->where('id', $tenantId)->exists()) {
    throw new RuntimeException('Simulation tenant persisted after rollback.');
}
echo json_encode(['rollback_verified' => true], JSON_UNESCAPED_SLASHES) . PHP_EOL;
