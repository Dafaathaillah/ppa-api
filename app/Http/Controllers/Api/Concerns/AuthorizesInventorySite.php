<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

trait AuthorizesInventorySite
{
    private const CROSS_SITE_ROLES = ['ict_ho', 'ict_developer'];

    private function authorizedSite(Request $request, ?string $requestedSite = null): string
    {
        $userSite = strtoupper(trim((string) $request->user()->site));
        $site = strtoupper(trim((string) ($requestedSite ?: $userSite)));

        abort_if($userSite === '' || $site === '', 422, 'Site user tidak valid.');
        abort_if(
            $site !== $userSite && ! in_array($request->user()->role, self::CROSS_SITE_ROLES, true),
            403,
            'Anda tidak memiliki akses ke inventory site tersebut.'
        );

        return $site;
    }

    private function canAccessOtherSites(Request $request): bool
    {
        return in_array($request->user()->role, self::CROSS_SITE_ROLES, true);
    }
}
