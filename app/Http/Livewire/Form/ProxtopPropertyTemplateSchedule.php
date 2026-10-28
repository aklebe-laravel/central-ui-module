<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use Illuminate\Support\Facades\Auth;
use Modules\Form\app\Http\Livewire\Form\Base\ModelBase;
use Modules\SystemBase\app\Services\SystemService;

class ProxtopPropertyTemplateSchedule extends ModelBase
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
        //'user',
        //'vm',
    ];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'Property Template Schedule';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'Property Template Schedules';

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
                                    'id'              => [
                                        'html_element' => 'hidden',
                                        'label'        => __('ID'),
                                        'validator'    => [
                                            'nullable',
                                            'integer',
                                        ],
                                    ],
                                    'is_enabled'      => [
                                        'html_element' => 'switch',
                                        'label'        => __('Enabled'),
                                        'description'  => __('Enabled or disabled for scheduling and automatism.'),
                                        'validator'    => [
                                            'nullable',
                                            'bool',
                                        ],
                                    ],
                                    'template_id'     => [
                                        'html_element' => 'select',
                                        'label'        => __('Template'),
                                        //'description'  => __(''),
                                        'css_group'    => '',
                                        'options'      => $systemService->toHtmlSelectOptions(\Modules\CentralUi\app\Models\ProxtopPropertyTemplate::with([])->orderBy('id', 'ASC')->get(),
                                            [
                                                'id',
                                                'name',
                                            ],
                                            'id',),

                                    ],
                                    'command_code'    => [
                                        'html_element' => 'select',
                                        'label'        => __('Command Code'),
                                        //'description'  => __(''),
                                        'css_group'    => '',
                                    ],
                                    'cron_expression' => [
                                        'html_element' => 'text',
                                        'label'        => __('Cron expression'),
                                        //'description'  => __(''),
                                        'css_group'    => '',
                                    ],
                                    'duration'        => [
                                        'html_element' => 'number',
                                        'label'        => __('Duration'),
                                        'css_group'    => '',
                                    ],
                                    'starts_at_date'  => [
                                        //'html_element' => 'datetime-local',
                                        'html_element' => 'date',
                                        'label'        => __('Starts at date'),
                                        'css_group'    => '',
                                    ],
                                    'ends_at_date'    => [
                                        //'html_element' => 'datetime-local',
                                        'html_element' => 'date',
                                        'label'        => __('Ends at date'),
                                        'css_group'    => '',
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
