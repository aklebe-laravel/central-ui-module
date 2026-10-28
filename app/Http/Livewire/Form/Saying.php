<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use Modules\Form\app\Http\Livewire\Form\Base\ModelBase;
use Modules\SystemBase\app\Services\SystemService;
use Modules\WebsiteBase\app\Services\WebsiteBaseFormService;

class Saying extends ModelBase
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
        'tags2',
    ];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'Saying';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'Sayings';

    ///**
    // * @return array
    // */
    //public function makeObjectInstanceDefaultValues(): array
    //{
    //    return app('system_base')->arrayMergeRecursiveDistinct(parent::makeObjectInstanceDefaultValues(), [
    //        'is_enabled' => true,
    //        'position' => 1000.0,
    //        'user_id'  => $this->getOwnerUserId(),
    //    ]);
    //}

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
                                    'id'          => [
                                        'html_element' => 'hidden',
                                        'label'        => __('ID'),
                                        'validator'    => [
                                            'nullable',
                                            'integer',
                                        ],
                                    ],
                                    'content' => [
                                        'html_element' => 'textarea',
                                        'label'        => __('Saying'),
                                        'description'  => __('Saying'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:30000',
                                        ],
                                        'css_group'    => 'form-group-responsive-full',
                                    ],
                                    'author' => [
                                        'html_element' => 'text',
                                        'label'        => __('Author'),
                                        'description'  => __('Author'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                        'css_group'    => '',
                                    ],
                                    'tag_names'   => app(WebsiteBaseFormService::class)->getFormElementTags(),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

}
