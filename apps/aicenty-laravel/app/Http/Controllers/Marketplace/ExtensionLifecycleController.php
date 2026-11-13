<?php

namespace App\Http\Controllers\Marketplace;

use App\Domains\Marketplace\Services\ExtensionInstallService;
use App\Domains\Marketplace\Services\ExtensionUninstallService;
use App\Http\Controllers\Controller;

class ExtensionLifecycleController extends Controller
{
    public function install(string $slug, ExtensionInstallService $service): array
    {
        return $service->install($slug);
    }

    public function uninstall(string $slug, ExtensionUninstallService $service): array
    {
        return $service->uninstall($slug);
    }
}
