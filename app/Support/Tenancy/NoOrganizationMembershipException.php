<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown by TenantContextResolver when the authenticated user holds no
 * active organization membership at all. Distinct from
 * TenantContextNotSetException (a programming error — context was never
 * established) — this is a real, user-facing 403: the account genuinely
 * has nowhere to operate. Should be unreachable in practice since every
 * User gets an owner membership on creation (see User::booted()), but
 * handled explicitly rather than assumed impossible.
 */
class NoOrganizationMembershipException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This account is not a member of any organization.');
    }
}
