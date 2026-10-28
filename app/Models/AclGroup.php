<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Models;

class AclGroup extends \Modules\Acl\app\Models\AclGroup
{
    const string GROUP_PROXMOX_OBSERVERS = 'Server Proxmox Observers';
}