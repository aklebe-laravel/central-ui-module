<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Acl\app\Models\AclResource;
use Modules\CentralUi\app\Http\Middleware\GlobalStateRedirect;
use Modules\WebsiteBase\app\Models\User;

class StatusFailController extends Controller
{
    public function index(): View
    {
        /** @var User $user */
        $user = app('website_base_settings')->currentUser();

        // If no setup needed anymore, we need not check user is admin because the
        // setup form have to be no permissions itself.
        if (GlobalStateRedirect::checkState()) {
            if (!$user->hasAclResource(AclResource::RES_ADMIN)) {
                return view('content-pages.access-denied');
            }
        }

        // now show the form ...
        $livewireForm = app('system_base')->findLivewire('SystemSetup', 'livewire-forms');
            if ($user->canLogin()) {
                return view('website-base::page', [
                    'title'                       => __('Setup'),
                    'contentView'                 => 'components.system-setup',
                    'modelName'                   => 'User',
                    'formObjectId'                => $user->getKey(),
                    'livewireForm'                => $livewireForm,
                    'objectInstanceDefaultValues' => [
                        'user_id' => $user->getKey(),
                    ],
                ]);
            }

        // @todo: 404 User not found, or puppet, or invalid (disabled/deleted/...)
        throw new \Exception('System Setup Form failed ...');
    }

}