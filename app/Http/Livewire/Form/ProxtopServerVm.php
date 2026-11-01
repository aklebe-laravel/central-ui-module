<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use Illuminate\Support\Facades\Auth;
use Modules\Form\app\Http\Livewire\Form\Base\ModelBase;
use Modules\SystemBase\app\Services\SystemService;
use Modules\WebsiteBase\app\Services\WebsiteBaseFormService;

class ProxtopServerVm extends ModelBase
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
        'propertyTemplates',
    ];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'VM';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'VMs';

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
                                    'id'                   => [
                                        'html_element' => 'hidden',
                                        'label'        => __('ID'),
                                        'validator'    => [
                                            'nullable',
                                            'integer',
                                        ],
                                    ],
                                    'is_enabled'           => [
                                        'html_element' => 'switch',
                                        'label'        => __('Enabled'),
                                        'description'  => __('Enabled or disabled displaying.'),
                                        'validator'    => [
                                            'nullable',
                                            'bool',
                                        ],
                                        'css_group'    => 'form-group-responsive-full',
                                    ],
                                    'name'                 => [
                                        'html_element' => 'text',
                                        'readonly'     => true,
                                        'label'        => __('Name'),
                                        'description'  => __('Name'),
                                    ],
                                    'name_intern'          => [
                                        'html_element' => 'text',
                                        'label'        => __('Internal Name'),
                                        'description'  => __('Leave empty to use name'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'host'                 => [
                                        'html_element' => 'text',
                                        'label'        => __('Host'),
                                        'description'  => __('The vm host ip (or name)'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'url'                  => [
                                        'html_element' => 'text',
                                        'label'        => __('URL'),
                                        'description'  => __('Url to frontend or empty'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                    ],
                                    'media_item.final_url' => [
                                        'html_element' => 'image',
                                        'label'        => __('Image'),
                                        'description'  => __('Image'),
                                        'css_group'    => '',
                                    ],
                                    'media_item_id'        => app(WebsiteBaseFormService::class)->getFormElementImagePickerMediaItems(scope: 'images'),
                                    'description'          => [
                                        'html_element' => 'textarea',
                                        'label'        => __('Description'),
                                        'description'  => __('Detailed description'),
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
                        [
                            'tab'     => [
                                'label' => __('Property Templates'),
                            ],
                            'content' => [
                                'form_elements' => [
                                    'propertyTemplates' => [
                                        'html_element' => 'element-dt-split-default',
                                        'label'        => __('Property Templates'),
                                        'description'  => __('Property Templates'),
                                        'css_group'    => 'form-group-responsive-full',
                                        'options'      => [
                                            'table'         => 'central-ui::data-table.proxtop-property-template',
                                            'table_options' => [
                                                'hasCommands' => $defaultSettings['can_manage'],
                                                'editable'    => $defaultSettings['can_manage'],
                                                'canAddRow'   => $defaultSettings['can_manage'],
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
