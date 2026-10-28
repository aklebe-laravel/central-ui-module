<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use Illuminate\Support\Facades\Auth;
use Modules\Form\app\Http\Livewire\Form\Base\ModelBase;
use Modules\SystemBase\app\Services\SystemService;
use Modules\WebsiteBase\app\Services\WebsiteBaseFormService;

class ProxtopPropertyTemplate extends ModelBase
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
    public array $objectRelations = [
        'vms',
        'tags2',
        'schedules',
    ];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'Property Template';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'Property Templates';

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
            'is_enabled' => false,
            'options'    => null,
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
                        'common'    => [
                            'tab'     => [
                                'label' => __('Common'),
                            ],
                            'content' => [
                                //'visible' => $this->canEdit(),
                                'form_elements' => [
                                    'id'                 => [
                                        'html_element' => 'hidden',
                                        'label'        => __('ID'),
                                        'validator'    => [
                                            'nullable',
                                            'integer',
                                        ],
                                    ],
                                    'is_enabled'         => [
                                        'html_element' => 'switch',
                                        'label'        => __('Enabled'),
                                        'description'  => __('Enabled or disabled for scheduling and automatism.'),
                                        'validator'    => [
                                            'bool',
                                        ],
                                        'css_group'    => 'form-group-responsive-full',
                                    ],
                                    'name'               => [
                                        'html_element' => 'text',
                                        'label'        => __('Name'),
                                        'description'  => __('Name'),
                                        'css_group'    => '',
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'description'        => [
                                        'html_element' => 'text',
                                        'label'        => __('Description'),
                                        'description'  => __('Detailed description'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'acl_res_view'       => app(WebsiteBaseFormService::class)->getFormElementAclResource([
                                        'label'       => __('ACL View'),
                                        'description' => __('Permissions to view the vm'),
                                    ], 'code', true),
                                    'acl_res_shutdown'   => app(WebsiteBaseFormService::class)->getFormElementAclResource([
                                        'label'       => __('ACL Shutdown'),
                                        'description' => __('Permissions to shutdown the vm'),
                                    ], 'code', true),
                                    'acl_res_start'      => app(WebsiteBaseFormService::class)->getFormElementAclResource([
                                        'label'       => __('ACL Start'),
                                        'description' => __('Permissions to start the vm'),
                                    ], 'code', true),
                                    'acl_res_edit'       => app(WebsiteBaseFormService::class)->getFormElementAclResource([
                                        'label'       => __('ACL Edit'),
                                        'description' => __('Permissions to edit the vm'),
                                    ], 'code', true),
                                    'net_in_out_ratio'   => [
                                        'html_element' => 'number',
                                        'label'        => __('Net In/Out Ratio'),
                                        'description'  => __('Net In/Out Ratio. Like 3.0 for 3:1 for in:out'),
                                        'validator'    => [
                                            'nullable',
                                            'numeric',
                                        ],
                                    ],
                                    'net_traffic_idle'   => [
                                        'html_element' => 'number',
                                        'label'        => __('Net Traffic Idle'),
                                        'validator'    => [
                                            'integer',
                                            'min:0',
                                        ],
                                    ],
                                    'net_traffic_limit'  => [
                                        'html_element' => 'number',
                                        'label'        => __('Net Traffic Limit'),
                                        'validator'    => [
                                            'integer',
                                            'min:0',
                                        ],
                                    ],
                                    'disk_traffic_idle'  => [
                                        'html_element' => 'number',
                                        'label'        => __('Disk Traffic Idle'),
                                        'validator'    => [
                                            'integer',
                                            'min:0',
                                        ],
                                    ],
                                    'disk_traffic_limit' => [
                                        'html_element' => 'number',
                                        'label'        => __('Disk Traffic Limit'),
                                        'validator'    => [
                                            'integer',
                                            'min:0',
                                        ],
                                    ],
                                    'cpu_idle'           => [
                                        'html_element' => 'number',
                                        'label'        => __('CPU Idle'),
                                        'validator'    => [
                                            'integer',
                                            'min:0',
                                        ],
                                    ],
                                    'cpu_limit'          => [
                                        'html_element' => 'number',
                                        'label'        => __('CPU Limit'),
                                        'validator'    => [
                                            'integer',
                                            'min:0',
                                        ],
                                    ],
                                    'cpu_calc_tolerance' => [
                                        'html_element' => 'number',
                                        'label'        => __('CPU Calculation Tolerance'),
                                        'validator'    => [
                                            'integer',
                                            'min:0',
                                        ],
                                    ],
                                    'tag_names'          => app(WebsiteBaseFormService::class)->getFormElementTags(),
                                    'options'            => [
                                        'html_element' => 'textarea',
                                        'label'        => __('Options'),
                                        'validator'    => [
                                            'nullable',
                                            'array',
                                            //'Max:4000',
                                        ],
                                        'css_group'    => 'form-group-responsive-full',
                                    ],
                                ],
                            ],
                        ],
                        'schedules' => [
                            // don't show if creating a new object ...
                            'disabled' => !$this->dataSource->getKey(),
                            'tab'      => [
                                'label' => __('Schedules'),
                            ],
                            'content'  => [
                                'form_elements' => [
                                    'schedules' => [
                                        'html_element' => $defaultSettings['element_dt'],
                                        'label'        => __('Schedules'),
                                        'description'  => __('Schedules linked to this Template'),
                                        'css_group'    => 'form-group-responsive-full',
                                        'options'      => [
                                            'form'          => 'central-ui::form.proxtop-property-template-schedule',
                                            'table'         => 'central-ui::data-table.proxtop-property-template-schedule',
                                            'table_options' => [
                                                'hasCommands' => $defaultSettings['can_manage'],
                                                'editable'    => $defaultSettings['can_manage'],
                                                'canAddRow'   => $defaultSettings['can_manage'],
                                                'removable'   => $defaultSettings['can_manage'],
                                            ],
                                        ],
                                        'validator'    => [
                                            'nullable',
                                            'array',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'vms'       => [
                            // don't show if creating a new object ...
                            'disabled' => !$this->dataSource->getKey(),
                            'tab'      => [
                                'label' => __('VMs'),
                            ],
                            'content'  => [
                                'form_elements' => [
                                    'vms' => [
                                        'html_element' => $defaultSettings['element_dt'],
                                        'label'        => __('VMs'),
                                        'description'  => __('VMs linked to this Template'),
                                        'css_group'    => 'form-group-responsive-full',
                                        'options'      => [
                                            'form'          => 'central-ui::form.proxtop-server-vm',
                                            'table'         => 'central-ui::data-table.proxtop-server-vm',
                                            'table_options' => [
                                                'hasCommands' => $defaultSettings['can_manage'],
                                                'editable'    => $defaultSettings['can_manage'],
                                                'canAddRow'   => $defaultSettings['can_manage'],
                                                'removable'   => $defaultSettings['can_manage'],
                                            ],
                                        ],
                                        'validator'    => [
                                            'nullable',
                                            'array',
                                        ],
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