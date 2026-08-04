<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown by OrganizationScope when a tenant-owned model is queried with no
 * active TenantContext. Deliberately fails loud instead of silently
 * returning zero rows or (worse) all rows — every code path touching a
 * tenant-owned model (controllers via middleware, jobs/commands via
 * explicit TenantContext::run()) must establish context first. Seeing this
 * exception means a leak was just prevented, not caused.
 */
class TenantContextNotSetException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'No active organization context is set. Tenant-scoped models '.
            'cannot be queried until TenantContext::run()/set() has been '.
            'called explicitly — this is never inherited implicitly across '.
            'requests, jobs, or console commands.'
        );
    }
}
