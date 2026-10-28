<?php

namespace Modules\CentralUi\app\Http\Livewire\Form;

use InvalidArgumentException;
use Modules\Acl\app\Models\AclResource;
use Modules\Acl\app\Services\UserService;
use Modules\CentralUi\app\Services\Wireguard\MemberConfigArchive;
use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\WireguardMemberService;
use Modules\CentralUi\app\Services\Wireguard\WireguardNetwork;
use Modules\Form\app\Http\Livewire\Form\Base\NativeObjectBase;
use Modules\SystemBase\app\Models\JsonViewResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Create or change a WireGuard member (the peers of one name in the configured networks).
 * Same behaviour as the artisan commands wg:member:set and wg:member:remove.
 *
 * - checked networks: missing peers are created (same host number inside a shared host group)
 * - unchecked networks: existing peers are deleted
 * - "renew keys": the keys of all checked networks are renewed (old client configs stop working)
 *
 * The form object id is the member name (null for a new member).
 */
class WireguardMember extends NativeObjectBase
{
    /**
     * Restrictions to allow this component.
     */
    const array aclResources = [AclResource::RES_DEVELOPER, AclResource::RES_ADMIN];

    /**
     * Singular
     *
     * @var string
     */
    public string $objectFrontendLabel = 'WireGuard Member';

    /**
     * Plural
     *
     * @var string
     */
    public string $objectsFrontendLabel = 'WireGuard Members';

    /**
     * Deleting is done in the data table (removes the member from all networks).
     *
     * @var array|string[]
     */
    public array $formActionButtons = [
        'cancel'   => 'form::components.form.actions.defaults.cancel',
        'download' => 'central-ui::components.wireguard.form-download',
        'accept'   => 'form::components.form.actions.defaults.accept',
    ];

    /**
     * @param $id
     *
     * @return array
     */
    public function loadDataSource($id): array
    {
        $member = (string) ($id ?? '');

        // new member: preselect all networks of the shared host groups (like "all" in the commands)
        if ($member === '') {
            try {
                $defaultNetworks = array_map(fn(WireguardNetwork $n) => $n->tunnel, app(NetworkRepository::class)->inSharedGroups());
            } catch (InvalidArgumentException $e) {
                $this->addErrorMessage($e->getMessage());
                $defaultNetworks = [];
            }

            return [
                'id'          => null,
                'name'        => '',
                'networks'    => $defaultNetworks,
                'renew'       => false,
                'peers'       => [],
                'has_configs' => false,
            ];
        }

        try {
            $row = app(WireguardMemberService::class)->member($member);
        } catch (Throwable $e) {
            $this->addErrorMessage(__('Unable to load the WireGuard peers: :error', ['error' => $e->getMessage()]));
            $this->closeForm();

            return [];
        }

        if ($row === null) {
            $this->addErrorMessage(__(':name not found.', ['name' => $member]));
            $this->closeForm();

            return [];
        }

        return [
            'id'          => $member,
            'name'        => $member,
            'networks'    => array_column($row['networks'], 'tunnel'),
            'renew'       => false,
            'peers'       => $row['networks'],
            'has_configs' => $row['has_configs'],
        ];
    }

    /**
     * VPN access is more sensitive than other data, so the class restrictions are required here.
     *
     * @return bool
     */
    public function canManage(): bool
    {
        /** @var UserService $userService */
        $userService = app(UserService::class);

        return (bool) $userService->currentUser()
            ?->hasAclResource(static::aclResources);
    }

    /**
     * @return bool
     */
    public function canEdit(): bool
    {
        return $this->canManage();
    }

    /**
     * @param  mixed   $dataSource
     * @param  string  $displayKey
     *
     * @return string
     */
    protected function makeFormTitle(mixed $dataSource, string $displayKey): string
    {
        if ($name = data_get($dataSource, 'id')) {
            return sprintf(__("Change %s: %s"), __($this->objectFrontendLabel), $name);
        }

        return sprintf(__("Create %s"), __($this->objectFrontendLabel));
    }

    /**
     * @return JsonViewResponse
     */
    protected function saveFormData(): JsonViewResponse
    {
        $jsonResponse = new JsonViewResponse();

        if (!$this->canManage()) {
            $jsonResponse->setErrorMessage(__('Permission denied.'));

            return $jsonResponse;
        }

        if (!($validated = $this->validateForm())) {
            $jsonResponse->setErrorMessage(__('Unable to load data or validation error.'));

            return $jsonResponse;
        }

        $existing = (string) ($this->formObjectId ?? '');
        $member = ($existing !== '') ? $existing : trim((string) data_get($validated, 'name', ''));
        $selected = array_values(array_unique(array_map('strval', (array) data_get($validated, 'networks', []))));
        $renew = (bool) data_get($validated, 'renew', false);

        /** @var WireguardMemberService $service */
        $service = app(WireguardMemberService::class);
        /** @var NetworkRepository $repository */
        $repository = app(NetworkRepository::class);

        $messages = [];

        try {
            $service->assertValidMember($member);

            // current state from the pfSense, not from the cache
            $current = $service->member($member, true);

            if ($existing === '') {
                if ($current !== null) {
                    throw new RuntimeException(__("':name' already exists. Open it in the list to change it.", ['name' => $member]));
                }
                if ($selected === []) {
                    throw new RuntimeException(__('Select at least one network.'));
                }
            }

            $currentTunnels = $current ? array_column($current['networks'], 'tunnel') : [];
            $toRemove = array_values(array_diff($currentTunnels, $selected));

            if ($selected !== []) {
                $changed = $service->set($member, array_map(fn(string $t) => $repository->find($t), $selected), $renew);

                foreach ($changed as $change) {
                    $messages[] = $change->created ? __(':network: created (:ip)', ['network' => $change->tunnel, 'ip' => $change->ip]) : __(':network: key renewed (:ip)', ['network' => $change->tunnel, 'ip' => $change->ip]);
                }
            }

            if ($toRemove !== []) {
                $result = $service->remove($member, array_map(fn(string $t) => $repository->find($t), $toRemove));

                foreach ($result['removed'] as $tunnel) {
                    $messages[] = __(':network: deleted', ['network' => $tunnel]);
                }
            }
        } catch (RuntimeException|InvalidArgumentException $e) {
            $jsonResponse->setErrorMessage($e->getMessage());

            return $jsonResponse;
        } finally {
            // reload the data on the next access
            unset($this->dataSource);
        }

        $jsonResponse->setMessage($messages ? sprintf('%s: %s', $member, implode(', ', $messages)) : __('Nothing to do.'));

        return $jsonResponse;
    }

    /**
     * Livewire action: download the zip archive with all client configs of the opened member.
     *
     * @return StreamedResponse|null
     */
    public function downloadConfig(): ?StreamedResponse
    {
        $member = (string) ($this->formObjectId ?? '');

        if (!$this->canManage() || !app(WireguardMemberService::class)->isValidMember($member)) {
            $this->addErrorMessage(__('Permission denied.'));

            return null;
        }

        if (!($response = app(MemberConfigArchive::class)->downloadResponse($member))) {
            $this->addWarningMessage(__('No client configs stored for :name. Renew the keys to create new ones.', ['name' => $member]));

            return null;
        }

        app(WireguardMemberService::class)->info('WireGuard client configs downloaded', ['member' => $member]);

        $this->skipRender();

        return $response;
    }

    /**
     * @return array
     */
    public function getFormElements(): array
    {
        $parentFormData = parent::getFormElements();

        $isNew = !data_get($this->dataSource, 'id');

        return [
            ... $parentFormData,
            'title'         => $this->makeFormTitle($this->dataSource, 'id'),
            'description'   => __('The client configs (zip) contain private keys. They are only created when a peer is created or its key is renewed.'),
            'form_elements' => [
                'id'       => [
                    'html_element' => 'hidden',
                    'label'        => __('ID'),
                    'validator'    => [
                        'nullable',
                        'string',
                    ],
                ],
                'name'     => [
                    'html_element' => 'text',
                    'label'        => __('Member'),
                    'description'  => $isNew ? __('Letters, digits, "-" and "_" (max. 63 characters). Becomes the peer description on the pfSense.') : __('The name cannot be changed. Delete the member and create a new one instead.'),
                    'read_only'    => !$isNew,
                    'validator'    => [
                        'required',
                        'string',
                        'max:63',
                        'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                    ],
                    'css_group'    => 'form-group-responsive-full',
                ],
                'networks' => [
                    'html_element'    => 'multi_select',
                    'label'           => __('Networks'),
                    'description'     => __('Checked networks get a peer, unchecked networks lose it. Inside a shared host group the member gets the same host number.'),
                    'options'         => $this->getNetworkOptions(),
                    'validator'       => [
                        'nullable',
                        'array',
                    ],
                    'validator_multi' => [
                        'networks.*' => [
                            'string',
                            'in:'.implode(',', array_keys($this->getNetworkOptions())),
                        ],
                    ],
                    'css_group'       => 'form-group-responsive-full',
                ],
                'renew'    => [
                    'html_element' => 'switch',
                    'label'        => __('Renew keys'),
                    'description'  => __('Renews the keys of all checked networks. The current client configs stop working.'),
                    'visible'      => !$isNew,
                    'validator'    => [
                        'nullable',
                        'bool',
                    ],
                ],
                'peers'    => [
                    'html_element' => 'html',
                    'label'        => __('Current peers'),
                    'visible'      => !$isNew,
                    'value'        => $this->getPeersHtml(),
                    'css_group'    => 'form-group-responsive-full',
                ],
            ],
        ];
    }

    /**
     * @return array<string, string> tunnel => label
     */
    protected function getNetworkOptions(): array
    {
        try {
            $repository = app(NetworkRepository::class);
            $options = [];

            foreach ($repository->all() as $network) {
                $group = array_map(fn(WireguardNetwork $n) => $n->tunnel, $repository->hostGroup($network));
                $options[$network->tunnel] = sprintf('%s (%sx)%s',
                    $network->tunnel,
                    $network->prefix,
                    count($group) > 1 ? ' - '.__('group').': '.implode(', ', $group) : '');
            }

            return $options;
        } catch (InvalidArgumentException $e) {
            $this->addErrorMessage($e->getMessage());

            return [];
        }
    }

    /**
     * Read only list of the current peers.
     *
     * @return string
     */
    protected function getPeersHtml(): string
    {
        $peers = data_get($this->dataSource, 'peers', []);

        if (!$peers) {
            return '<span class="decent">-</span>';
        }

        $html = '';
        foreach ($peers as $peer) {
            $html .= sprintf('<div><strong>%s</strong> %s</div>', e($peer['tunnel']), e($peer['ip']));
        }

        $html .= sprintf('<div class="decent mt-2">%s</div>',
            e(data_get($this->dataSource, 'has_configs') ? __('Client configs are stored.') : __('No client configs stored. Renew the keys to create new ones.')));

        return $html;
    }
}
