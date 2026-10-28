<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

class AclResource extends \Modules\Acl\app\Models\AclResource
{
    const string RES_PROXMOX_OBSERVER = 'server.proxmox.observer';
}