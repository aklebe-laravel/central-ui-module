<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
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
     * Minimum seconds between two sayings in the layout
     */
    public const int QUOTE_INTERVAL_SECONDS = 30;

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

        // user model of this module, see SystemBaseServiceProvider::register()
        $this->app->bind(AppUser::class, ModuleUser::class);

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

        // The desktop is the start page after login, unless the installation configured another one.
        if (config('website-base.login_redirect', '/') === '/') {
            Config::set('website-base.login_redirect', 'central-ui.desktop');
        }

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

        // morph types use names instead of class names
        Relation::enforceMorphMap([
            'proxtop_property_template' => ProxtopPropertyTemplate::class,
            'saying'                    => Saying::class,
        ]);

        // append the middleware to the 'web' group
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', GlobalStateRedirect::class);

        // A random saying pops up in the layout at most every QUOTE_INTERVAL_SECONDS,
        // page loads in between show none. Only the time is kept in the session.
        View::composer('layouts.master', function ($view) {
            $now = time();
            if ($now - (int) Session::get('quote_shown_at', 0) < self::QUOTE_INTERVAL_SECONDS) {
                return;
            }

            if ($quote = Saying::query()
                ->inRandomOrder()
                ->first()
            ) {
                Session::put('quote_shown_at', $now);
                $view->with('randomQuote', $quote);
            }
        });
    }
}
