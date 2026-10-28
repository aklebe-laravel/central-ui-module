<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use Modules\Acl\app\Models\AclResource;
use Modules\Form\app\Http\Livewire\Form\Base\ModelBase;

/**
 * A WireGuard network (= one tunnel on the pfSense).
 *
 * Changes only affect new or renewed client configs. Existing peers on the pfSense are not touched:
 * - deleting a network: its peers stay on the pfSense, Proxtop just stops managing them
 * - changing prefix or first host: existing peers keep their old IP addresses
 */
class ProxtopWireguardNetwork extends ModelBase
{
    /**
     * Restrictions to allow this component.
     */
    const array aclResources = [AclResource::RES_DEVELOPER, AclResource::RES_ADMIN];

    /**
     * Pattern of tunnel and group names.
     */
    const string NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Pattern of the ip prefix like "198.51.100.".
     */
    const string PREFIX_PATTERN = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.$/';

    /**
     * Relations commonly built in with(...)
     *
     * @var array[]
     */
    public array $objectRelations = [];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'WireGuard Network';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'WireGuard Networks';

    /**
     * @return array
     */
    public function makeObjectInstanceDefaultValues(): array
    {
        return app('system_base')->arrayMergeRecursiveDistinct(parent::makeObjectInstanceDefaultValues(), [
            'position'   => 1000,
            'first_host' => 2,
            'mask'       => 24,
            'mtu'        => 1280,
        ]);
    }

    /**
     * @return array
     */
    public function getFormElements(): array
    {
        $parentFormData = parent::getFormElements();

        // ignore the current row for the unique check (NULL for a new row)
        $ignoreId = $this->dataSource?->getKey() ?? 'NULL';

        return [
            ... $parentFormData,
            'title'        => $this->makeFormTitle($this->dataSource, 'tunnel'),
            'tab_controls' => [
                'base_item' => [
                    'tab_pages' => [
                        'common' => [
                            'tab'     => [
                                'label' => __('Common'),
                            ],
                            'content' => [
                                'form_elements' => [
                                    'id'          => [
                                        'html_element' => 'hidden',
                                        'label'        => __('ID'),
                                        'validator'    => [
                                            'nullable',
                                            'integer',
                                        ],
                                    ],
                                    'tunnel'      => [
                                        'html_element' => 'text',
                                        'label'        => __('Tunnel'),
                                        'description'  => __('Tunnel name on the pfSense, e.g. tun_wg1.'),
                                        'validator'    => [
                                            'required',
                                            'string',
                                            'max:32',
                                            'regex:'.self::NAME_PATTERN,
                                            'unique:proxtop_wireguard_networks,tunnel,'.$ignoreId,
                                        ],
                                    ],
                                    'host_group'  => [
                                        'html_element' => 'text',
                                        'label'        => __('Host group'),
                                        'description'  => __('Networks with the same group name share their host numbers (last IP octet). Empty: the network assigns its numbers on its own.'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'max:50',
                                            'regex:'.self::NAME_PATTERN,
                                        ],
                                    ],
                                    'position'    => [
                                        'html_element' => 'number',
                                        'label'        => __('Position'),
                                        'description'  => __('Sort order in lists and forms.'),
                                        'validator'    => [
                                            'nullable',
                                            'numeric',
                                        ],
                                    ],
                                    'description' => [
                                        'html_element' => 'textarea',
                                        'label'        => __('Description'),
                                        'validator'    => [
                                            'nullable',
                                            'string',
                                            'max:255',
                                        ],
                                        'css_group'    => 'form-group-responsive-full',
                                    ],
                                ],
                            ],
                        ],
                        'client' => [
                            'tab'     => [
                                'label' => __('Client config'),
                            ],
                            'content' => [
                                'form_elements' => [
                                    'prefix'      => [
                                        'html_element' => 'text',
                                        'label'        => __('Prefix'),
                                        'description'  => __('Client IP prefix including the last dot, e.g. 198.51.100. Existing peers keep their IP addresses.'),
                                        'validator'    => [
                                            'required',
                                            'string',
                                            'max:16',
                                            'regex:'.self::PREFIX_PATTERN,
                                        ],
                                    ],
                                    'first_host'  => [
                                        'html_element' => 'number_int',
                                        'label'        => __('First host'),
                                        'description'  => __('First host number to assign. Usually 2, because .1 is the pfSense itself.'),
                                        'validator'    => [
                                            'required',
                                            'integer',
                                            'min:1',
                                            'max:254',
                                        ],
                                    ],
                                    'mask'        => [
                                        'html_element' => 'number_int',
                                        'label'        => __('Mask'),
                                        'description'  => __('Network mask of the client address, usually 24.'),
                                        'validator'    => [
                                            'required',
                                            'integer',
                                            'min:8',
                                            'max:32',
                                        ],
                                    ],
                                    'endpoint'    => [
                                        'html_element' => 'text',
                                        'label'        => __('Endpoint'),
                                        'description'  => __('Host and port the clients connect to, e.g. vpn.example.org:51821.'),
                                        'validator'    => [
                                            'required',
                                            'string',
                                            'max:255',
                                        ],
                                    ],
                                    'allowed_ips' => [
                                        'html_element' => 'text',
                                        'label'        => __('Allowed IPs'),
                                        'description'  => __('Routed through the tunnel, e.g. 192.0.2.0/24, 198.51.100.0/24 or 0.0.0.0/0 for everything.'),
                                        'validator'    => [
                                            'required',
                                            'string',
                                            'max:1000',
                                        ],
                                    ],
                                    'dns'         => [
                                        'html_element' => 'text',
                                        'label'        => __('DNS'),
                                        'description'  => __('Comma separated DNS servers, e.g. 192.0.2.4, 192.0.2.5.'),
                                        'validator'    => [
                                            'required',
                                            'string',
                                            'max:255',
                                        ],
                                    ],
                                    'mtu'         => [
                                        'html_element' => 'number_int',
                                        'label'        => __('MTU'),
                                        'description'  => __('Usually 1280.'),
                                        'validator'    => [
                                            'required',
                                            'integer',
                                            'min:576',
                                            'max:9000',
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
