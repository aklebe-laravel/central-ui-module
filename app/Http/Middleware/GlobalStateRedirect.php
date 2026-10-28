<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\CentralUi\app\Models\ProxtopServer;
use Modules\SystemBase\app\Services\CacheService;
use Symfony\Component\HttpFoundation\Response;

class GlobalStateRedirect
{
    /**
     * Check (cached) whether setup was made and continue if it's true.
     * Otherwise, the setup form will present.
     *
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isDbActive = static::checkState();

        //Log::debug(__METHOD__, [$isAppUrlActive, $isDbActive]);

        // 3. Logic: If false, force StatusFailController
        if (!$isDbActive) {
            // Avoid infinite redirect loop
            if ($request->routeIs('status.fail')) {
                return $next($request);
            }

            return redirect()->route('status.fail');
        }

        return $next($request);
    }

    /**
     * @return bool true : everthing is fine, no setup needed
     */
    public static function checkState() : bool
    {
        // Check DB (Cached for 1 hour to prevent I/O load)
        return app(CacheService::class)->rememberUseConfig('global_state_active', 'system-base.cache.default_ttl_big', function () {
            return ProxtopServer::count() > 0;
        });
    }
}