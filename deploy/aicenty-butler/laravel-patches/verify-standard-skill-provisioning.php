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
$simPhone = '090' . substr(preg_replace('/\D/', '', $tenantId), 0, 7);
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
        'settings' => json_encode([
            'internal_simulation' => true,
            'family_phone' => $simPhone,
        ], JSON_THROW_ON_ERROR),
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
    $agentOtherConfig = json_decode((string) $db->table('agents')
        ->where('id', $agentId)
        ->value('other_config'), true, 512, JSON_THROW_ON_ERROR);
    $missingPromptPins = array_diff($slugs, $agentOtherConfig['pinned_skills'] ?? []);
    $requiredToolsEnabled = $db->table('builtin_tools')
        ->whereIn('name', ['create_image', 'send_file', 'skill_search', 'use_skill'])
        ->where('enabled', true)
        ->count() === 4;

    $service->registerDedicatedSimChannel($tenantId, $simPhone);
    $firstChannel = $db->table('channel_instances')
        ->where('tenant_id', $tenantId)
        ->where('name', 'sim_' . $simPhone)
        ->first();
    $service->registerDedicatedSimChannel($tenantId, $simPhone);
    $secondChannel = $db->table('channel_instances')
        ->where('tenant_id', $tenantId)
        ->where('name', 'sim_' . $simPhone)
        ->first();
    $channelConfig = json_decode((string) $secondChannel->config, true, 512, JSON_THROW_ON_ERROR);
    $channelIdStable = $firstChannel->id === $secondChannel->id;
    $channelStandard = ($channelConfig['auto_accept_verified_friend_requests'] ?? false) === true
        && ($channelConfig['require_mention'] ?? false) === true
        && ($channelConfig['dm_policy'] ?? null) === 'open'
        && ($channelConfig['group_policy'] ?? null) === 'open';

    if (count($firstPins) !== 4 || count($secondPins) !== 4 || $secondGrants->count() !== 4) {
        throw new RuntimeException('Expected exactly four standard skill grants.');
    }
    if (
        !$idsStable
        || !$pinsRepaired
        || $tenantCatalogCount !== 2
        || $missingPromptPins !== []
        || ($agentOtherConfig['allow_image_generation'] ?? false) !== true
        || !$requiredToolsEnabled
        || !$channelIdStable
        || !$channelStandard
    ) {
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
        'prompt_pins_complete' => $missingPromptPins === [],
        'image_generation_allowed' => $agentOtherConfig['allow_image_generation'] ?? false,
        'required_tools_enabled' => $requiredToolsEnabled,
        'channel_id_stable' => $channelIdStable,
        'channel_standard' => $channelStandard,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $db->rollBack();
}

if ($db->table('tenants')->where('id', $tenantId)->exists()) {
    throw new RuntimeException('Simulation tenant persisted after rollback.');
}
echo json_encode(['rollback_verified' => true], JSON_UNESCAPED_SLASHES) . PHP_EOL;
