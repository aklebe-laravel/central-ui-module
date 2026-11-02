<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use Illuminate\Support\Facades\Auth;
use Modules\CentralUi\app\Events\LocalDesktopUpdated;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\Form\app\Http\Livewire\Form\Base\ModelBase;
use Modules\SystemBase\app\Models\JsonViewResponse;
use Modules\SystemBase\app\Services\SystemService;
use Modules\WebsiteBase\app\Services\WebsiteBaseFormService;

class ProxtopObserveServerItem extends ModelBase
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
        'user',
    ];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'Observe Item';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'Observe Items';

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
            'is_enabled' => true,
            'position'   => 1000.0,
            'user_id'    => $this->getOwnerUserId(),
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

        // prepare options with
        // vms already observed ...
        $currentVmIds = \Modules\CentralUi\app\Models\ProxtopObserveServerItem::with([])
            ->where('user_id', auth()->id())
            ->pluck('vm_id')
            ->toArray();
        // but keep the current one ...
        $currentVmIds = array_map('intval', array_filter($currentVmIds, 'is_numeric'));
        $currentVmIds = array_filter($currentVmIds, function ($item) {
            return $item !== (int) $this->dataSource->vm_id;
        });
        // to build the list without this filter ...
        // One sortBy() with multiple comparisons: chained sortBy() calls would make the last one the primary sort.
        // strnatcmp() sorts extern_vm_id naturally ("99" before "100").
        $c = ProxtopServerVm::getVisibleForUser()
            ->whereNotIn('id', $currentVmIds)
            ->sortBy([
                fn(ProxtopServerVm $a, ProxtopServerVm $b) => strcmp((string) $a->vm_type, (string) $b->vm_type),
                fn(ProxtopServerVm $a, ProxtopServerVm $b) => strnatcmp((string) $a->extern_vm_id, (string) $b->extern_vm_id),
            ])
            ->all();
        $vmOptions = $systemService->toHtmlSelectOptions($c,
            [
                'extern_vm_id',
                'vm_type',
                'name',
            ],
            'id',
            $systemService->selectOptionsSimple[$systemService::selectValueNoChoice]);

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
                                    'user_id'       => [
                                        'html_element' => 'hidden',
                                        'validator'    => ['required', 'integer'],
                                    ],
                                    'is_enabled'    => [
                                        'html_element' => 'switch',
                                        'label'        => __('Enabled'),
                                        'description'  => __('Enabled or disabled displaying.'),
                                        'validator'    => [
                                            'nullable',
                                            'bool',
                                        ],
                                        'css_group'    => 'form-group-responsive-full',
                                    ],
                                    'vm_id'         => [
                                        'html_element' => 'select',
                                        'label'        => __('VM'),
                                        // get all vms not already observed
                                        'options'      => $vmOptions,
                                        'description'  => __('The connected Machine'),
                                        'validator'    => [
                                            'nullable',
                                            'integer',
                                        ],
                                        'css_group'    => '',
                                    ],
                                    'name_intern'   => [
                                        'html_element' => 'text',
                                        'label'        => __('Internal Name'),
                                        'description'  => __('Leave empty to use name of the vm'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'Max:255',
                                        ],
                                        'css_group'    => '',
                                    ],
                                    'position'      => [
                                        'html_element' => 'number',
                                        'label'        => __('Position'),
                                        'description'  => __('Order in listings. Automatically calculated.'),
                                        'validator'    => ['nullable', 'numeric'],
                                    ],
                                    'media_item_id' => app(WebsiteBaseFormService::class)->getFormElementImagePickerMediaItems(),
                                    'description'   => [
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
                        //'acl_groups' => [
                        //    'disabled' => !$this->dataSource->getKey(),
                        //    'tab'      => [
                        //        'label' => __('Acl Groups'),
                        //    ],
                        //    'content'  => [
                        //        'form_elements' => [
                        //            'id' => [
                        //                'html_element' => 'hidden',
                        //                'label'        => __('ID'),
                        //                'validator'    => [
                        //                    'nullable',
                        //                    'integer',
                        //                ],
                        //            ],
                        //        ],
                        //    ],
                        //],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return JsonViewResponse
     */
    protected function saveFormData(): JsonViewResponse
    {
        $result = parent::saveFormData();
        if (!$result->hasErrors()) {

            // update desktops
            broadcast(new LocalDesktopUpdated());
        }

        return $result;
    }

}
