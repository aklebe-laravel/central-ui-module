<?php

namespace Modules\CentralUi\app\Http\Livewire\DataTable;

use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Modules\Acl\app\Models\AclResource;
use Modules\Acl\app\Services\UserService;
use Modules\CentralUi\app\Services\Wireguard\MemberConfigArchive;
use Modules\CentralUi\app\Services\Wireguard\NetworkRepository;
use Modules\CentralUi\app\Services\Wireguard\WireguardMemberService;
use Modules\WebsiteBase\app\Http\Livewire\DataTable\Base\DefaultDataTable;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * WireGuard members (peers on the pfSense), one row per member.
 * There is no eloquent model: the rows come from the pfSense REST API (see getFixCollection()).
 *
 * URL: /manage-data-all/WireguardMember
 */
class WireguardMember extends DefaultDataTable
{
    /**
     * Minimum restrictions to allow this component.
     */
    public const array aclResources = [AclResource::RES_DEVELOPER, AclResource::RES_ADMIN];

    /**
     * Rows are identified by the member name.
     *
     * @var string
     */
    public string $columnNameId = 'id';

    /**
     * Search, sorting and pagination for the API rows.
     *
     * @var bool
     */
    protected bool $processFixCollection = true;

    /**
     * Avoid the same API error message several times per render.
     *
     * @var bool
     */
    protected bool $sourceErrorReported = false;

    /**
     * @return void
     */
    protected function initBooted(): void
    {
        parent::initBooted();

        // a member cannot be duplicated (keys and host numbers are unique)
        unset($this->rowCommands['duplicate']);

        if ($this->canManage()) {
            $this->rowCommands = [
                'download' => 'central-ui::components.wireguard.download-button',
                ...$this->rowCommands,
            ];
        }
    }

    /**
     * @return void
     */
    protected function initSort(): void
    {
        $this->setSortAllCollections('name', 'asc');
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
     * @return array[]
     */
    public function getColumns(): array
    {
        return [
            [
                'name'       => 'name',
                'label'      => __('Member'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'text-left',
                'css_header' => 'w-[30%] truncate',
                'options'    => [
                    'has_open_link' => $this->canEdit(),
                ],
                'icon'       => 'user',
            ],
            [
                'name'       => 'networks_label',
                'label'      => __('Networks'),
                'searchable' => true,
                'sortable'   => true,
                'css_all'    => 'text-left show-md',
                'css_header' => 'w-[50%] truncate',
                'view'       => 'central-ui::components.wireguard.networks-column',
                'icon'       => 'network',
            ],
            [
                'name'     => 'has_configs',
                'label'    => __('Client configs'),
                'sortable' => true,
                'css_all'  => 'w-max text-center show-lg',
                'view'     => 'data-table::livewire.js-dt.tables.columns.bool-gray-green',
                'icon'     => 'file-archive',
            ],
        ];
    }

    /**
     * All members from the pfSense (cached for a few seconds by the service).
     *
     * @param  string  $collectionName
     *
     * @return SupportCollection|null
     */
    public function getFixCollection(string $collectionName): ?SupportCollection
    {
        try {
            $rows = app(WireguardMemberService::class)->overview();
        } catch (Throwable $e) {
            if (!$this->sourceErrorReported) {
                $this->sourceErrorReported = true;
                $this->addErrorMessage(__('Unable to load the WireGuard peers: :error', ['error' => $e->getMessage()]));
            }

            return collect();
        }

        return collect($rows)->map(fn(array $row) => [
            'id'             => $row['member'],
            'name'           => $row['member'],
            'networks'       => $row['networks'],
            // flat text for search and sorting
            'networks_label' => collect($row['networks'])
                ->map(fn(array $n) => $n['tunnel'].' '.$n['ip'])
                ->implode(', '),
            'has_configs'    => $row['has_configs'],
        ]);
    }

    /**
     * Removes the member from all configured networks (and its stored client configs).
     *
     * @param  mixed  $livewireId
     * @param  mixed  $itemId
     *
     * @return bool
     */
    #[On('delete-item')]
    public function deleteItem(mixed $livewireId, mixed $itemId): bool
    {
        if (!$this->checkLivewireId($livewireId)) {
            return false;
        }

        if (!$this->canManage()) {
            $this->addErrorMessage(__('Permission denied.'));

            return false;
        }

        $member = (string) $itemId;

        try {
            $result = app(WireguardMemberService::class)->remove($member, app(NetworkRepository::class)->all());
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->addErrorMessage($e->getMessage());

            return false;
        } finally {
            $this->forgetFixCollectionCache();
        }

        if ($result['removed'] === []) {
            $this->addWarningMessage(__("':name' was not found in any network.", ['name' => $member]));

            return false;
        }

        $this->addSuccessMessage(__("':name' deleted from: :networks", ['name' => $member, 'networks' => implode(', ', $result['removed'])]));

        return true;
    }

    /**
     * Livewire action: download the zip archive with all client configs of the member.
     *
     * @param  string  $member
     *
     * @return StreamedResponse|null
     */
    public function downloadConfig(string $member): ?StreamedResponse
    {
        if (!$this->canManage()) {
            $this->addErrorMessage(__('Permission denied.'));

            return null;
        }

        /** @var WireguardMemberService $service */
        $service = app(WireguardMemberService::class);
        if (!$service->isValidMember($member)) {
            $this->addErrorMessage(__('Invalid member name.'));

            return null;
        }

        if (!($response = app(MemberConfigArchive::class)->downloadResponse($member))) {
            $this->addWarningMessage(__('No client configs stored for :name. Renew the keys to create new ones.', ['name' => $member]));

            return null;
        }

        $service->info('WireGuard client configs downloaded', ['member' => $member]);

        // no re-render needed for a download
        $this->skipRender();

        return $response;
    }
}
