<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Modules\CentralUi\app\Console\FetchProxmoxStatus;
use Modules\CentralUi\app\Console\ProxmoxInfo;
use Modules\CentralUi\app\Console\ProxmoxTemplateInfo;
use Modules\CentralUi\app\Console\ProxmoxVmInfo;
use Modules\CentralUi\app\Console\SayingsDuplicates;
use Modules\CentralUi\app\Console\WireguardDoctorCommand;
use Modules\CentralUi\app\Console\WireguardMemberListCommand;
use Modules\CentralUi\app\Console\WireguardMemberRemoveCommand;
use Modules\CentralUi\app\Console\WireguardMemberSetCommand;
use Modules\CentralUi\app\Http\Controllers\StatusFailController;
use Modules\CentralUi\app\Http\Middleware\GlobalStateRedirect;
use Modules\CentralUi\app\Models\ProxtopPropertyTemplate;
use Modules\CentralUi\app\Models\Saying;
use Modules\CentralUi\app\Models\User as ModuleUser;
use App\Models\User as AppUser;
use Modules\CentralUi\app\Services\Pfsense\PfsenseApi;
use Modules\CentralUi\app\Services\ProxmoxService;
use Modules\CentralUi\app\Services\Wireguard\DatabaseNetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\SystemBase\app\Providers\Base\ModuleBaseServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class CentralUiServiceProvider extends ModuleBaseServiceProvider
{
    /**
     * @var string $moduleName
     */
    protected string $moduleName = 'CentralUi';

    /**
     * @var string $moduleNameLower
     */
    protected string $moduleNameLower = 'central-ui';

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        $this->app->singleton(ProxmoxService::class);
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(ScheduleServiceProvider::class);

        // ---------------------------------------------------
        // User Bind:
        // Bind to the concrete base class
        // ONLY BIND AppUser! Do not every derived class!
        // Just use app(\App\Models\User) everywhere, nothing else!
        // ---------------------------------------------------
        $this->app->bind(AppUser::class, ModuleUser::class);
        // Shorthand
        $this->app->bind('user', ModuleUser::class);

        // Bind to the core framework interface for injection
        //$this->app->bind(Authenticatable::class, ModuleUser::class);

        $this->app->bind(PfsenseApi::class, fn() => PfsenseApi::fromConfig());
        // Not a singleton: long-running processes see changed networks on the next resolve.
        $this->app->bind(NetworkRepository::class, DatabaseNetworkRepository::class);
    }

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        parent::boot();

        // 1. Override the auth driver configuration dynamically
        Config::set('auth.providers.users.database.model', ModuleUser::class);

        // The desktop is the start page after login, unless the installation configured another one.
        if (config('website-base.login_redirect', '/') === '/') {
            Config::set('website-base.login_redirect', 'central-ui.desktop');
        }

        // 2. Enforce morph map to prevent data corruption in polymorphic tables
        Relation::morphMap([
            'users' => ModuleUser::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                FetchProxmoxStatus::class,
                SayingsDuplicates::class,
                ProxmoxInfo::class,
                ProxmoxTemplateInfo::class,
                ProxmoxVmInfo::class,
                WireguardMemberListCommand::class,
                WireguardMemberSetCommand::class,
                WireguardMemberRemoveCommand::class,
                WireguardDoctorCommand::class,
            ]);
        }

        // morph tags use name instead FQDN
        Relation::enforceMorphMap([
            'proxtop_property_template' => ProxtopPropertyTemplate::class,
            'saying'                    => Saying::class,
        ]);

        // 1. Resolve Router from Container without changing signature
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        // Injects the middleware at the end of the 'web' group
        $defaultMiddleware = ['web'];
        foreach ($defaultMiddleware as $middlewareGroup) {
            $router->pushMiddlewareToGroup($middlewareGroup, GlobalStateRedirect::class);
        }

        $this->registerDynamicRoutes();

        // Saying (Zitat)
        View::composer('layouts.master', function ($view) {
            $now = Carbon::now();
            $lastQuoteTime = Session::get('quote_timestamp');

            //Log::info("Quote timestamp: " . $lastQuoteTime->diffInSeconds($now));
            // new saying every 5 minutes
            //if (!$lastQuoteTime || $lastQuoteTime->diffInMinutes($now) >= 5) {
            if (!$lastQuoteTime || $lastQuoteTime->diffInSeconds($now) >= 30) {
                $quote = Saying::with([])
                    ->inRandomOrder()
                    ->first();

                Session::put('current_quote', $quote);
                Session::put('quote_timestamp', $now);

                // make $randomQuote public in master.blade.php
                $view->with('randomQuote', Session::get('current_quote'));
            }

            //// make $randomQuote public in master.blade.php
            //$view->with('randomQuote', Session::get('current_quote'));
        });
    }

    /**
     * Register routes dynamically based on environment and database state.
     */
    private function registerDynamicRoutes(): void
    {
        Route::middleware('web')
            ->get('/system-unavailable', [StatusFailController::class, 'index'])
            ->name('status.fail');
    }
}
