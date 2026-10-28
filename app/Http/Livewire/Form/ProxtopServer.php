<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use Illuminate\Support\Facades\Auth;
use Modules\Form\app\Http\Livewire\Form\Base\ModelBase;
use Modules\SystemBase\app\Services\SystemService;

class ProxtopServer extends ModelBase
{
    const array aclResources = [];

    /**
     * Relations commonly built in with(...)
     * Also used for:
     * - blacklist for properties to clean up the object if needed
     * - onAfterUpdateItem() to sync relations
     *
     * @var array[]
     */
    public array $objectRelations = [];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'Server';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'Servers';

    /**
     * @return bool
     */
    public function isOwnUser(): bool
    {
        return $this->dataSource && ($this->getOwnerUserId() == Auth::id());
    }

    /**
     * Should be overwritten to decide the current object is owned by user
     * canEdit() can call canManage() but don't call canEdit() in canManage()!
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return ($this->isOwnUser() || $this->canManage());
    }

    /**
     * @return array
     */
    public function makeObjectInstanceDefaultValues(): array
    {
        return app('system_base')->arrayMergeRecursiveDistinct(parent::makeObjectInstanceDefaultValues(), [
            'api_allowed'   => true,
            'datacenter_id' => 1, // @todo: datacenter?
        ]);
    }

    /**
     *
     * @return array
     */
    public function getFormElements(): array
    {
        $parentFormData = parent::getFormElements();

        $defaultSettings = $this->getDefaultFormSettingsByPermission();
        /** @var SystemService $systemService */
        $systemService = app('system_base');

        return [
            ... $parentFormData,
            'title'        => $this->makeFormTitle($this->dataSource, 'id'),
            'tab_controls' => [
                'base_item' => [
                    'tab_pages' => [
                        'common' => [
                            'tab'     => [
                                'label' => __('Common'),
                            ],
                            'content' => [
                                //'visible' => $this->canEdit(),
                                'form_elements' => [
                                    'id'            => [
                                        'html_element' => 'hidden',
                                        'label'        => __('ID'),
                                        'validator'    => [
                                            'nullable',
                                            'integer',
                                        ],
                                    ],
                                    'api_allowed'   => [
                                        'html_element' => 'switch',
                                        'label'        => __('API Allowed'),
                                        'description'  => __('Disable will avoid any api request and their VMs.'),
                                        'validator'    => [
                                            'nullable',
                                            'bool',
                                        ],
                                    ],
                                    'datacenter_id' => [
                                        'html_element' => 'number_int',
                                        'label'        => __('Datacenter ID'),
                                        'description'  => __('Related Datacenter'),
                                        'validator'    => ['nullable', 'int'],
                                    ],
                                    'host'          => [
                                        'html_element' => 'text',
                                        'label'        => __('Host'),
                                        'description'  => __('IP or Host like my-domain.de'),
                                        'css_group'    => '',
                                        'validator'    => [
                                            'required',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'user'          => [
                                        'html_element' => 'text',
                                        'label'        => __('User'),
                                        'description'  => __('Auth user for this server like for Proxmox: root@pam'),
                                        'css_group'    => '',
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'token_name'    => [
                                        'html_element' => 'text',
                                        'label'        => __('Tokenname'),
                                        'description'  => __('Token name was configured in Proxmox'),
                                        'css_group'    => '',
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'token_secret'  => [
                                        'html_element' => 'textarea',
                                        'label'        => __('Token Secret'),
                                        'description'  => __('Token secret was configured in Proxmox'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'description'   => [
                                        'html_element' => 'textarea',
                                        'label'        => __('Description'),
                                        'description'  => __('Detailed of this server'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:30000',
                                        ],
                                        'css_group'    => 'form-group-responsive-full',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

}
