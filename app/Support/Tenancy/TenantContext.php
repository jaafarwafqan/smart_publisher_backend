<?php

namespace App\Support\Tenancy;

/**
 * The single source of truth for "which organization is this code operating
 * on right now." Bound as a singleton, but that singleton is re-created
 * fresh per HTTP request/queue job/console invocation by the framework's own
 * container lifecycle (php-fpm / artisan serve process one request per
 * worker at a time; queue:work resolves a fresh container per job under
 * `--force`/default config) — nothing here persists context across requests
 * on its own. What matters is that nothing sets it *implicitly*:
 *
 * - HTTP requests: ResolveTenantContext middleware sets it after verifying
 *   the authenticated user actually holds a membership in the requested org.
 * - Jobs: must call TenantContext::run($organizationId, ...) explicitly at
 *   the top of handle(), using an organization_id captured on the job's own
 *   payload at dispatch time — never assumed from ambient state.
 * - Console commands: same — must resolve and set explicitly per unit of
 *   work (see ProcessScheduledPostsJob, which iterates due posts across all
 *   organizations and sets the context once per post before dispatching).
 *
 * OrganizationScope throws TenantContextNotSetException rather than
 * silently returning zero/all rows when nothing has been set — a missing
 * call site fails loudly during development instead of leaking silently.
 */
class TenantContext
{
    private ?int $organizationId = null;

    public function set(int $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function has(): bool
    {
        return $this->organizationId !== null;
    }

    public function get(): int
    {
        if ($this->organizationId === null) {
            throw new TenantContextNotSetException;
        }

        return $this->organizationId;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }

    /**
     * Runs $callback with the context temporarily set to $organizationId,
     * restoring whatever was set before (including "nothing") afterward —
     * safe to nest. This is the only sanctioned way jobs/commands should
     * touch tenant-scoped models.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(int $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }
}
