<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Http\Livewire;

use Modules\SystemBase\app\Http\Livewire\BaseComponent;

class UpdateBubble extends BaseComponent
{
    public string $lastTimestamp;
    public string $label;
    public string $channel;
    public string $event;
    public string $identity;
    public bool $minimalistic = false;

    public function mount(string $label = 'API Status', string $channel = '', string $event = '', string $identity = ''): void
    {
        $this->label = $label;
        $this->channel = $channel;
        $this->event = $event;
        $this->identity = $identity;
        $this->lastTimestamp = now()->format('H:i:s');
    }

    public function getListeners(): array
    {
        if (empty($this->channel) || empty($this->event)) {
            return [];
        }

        return ["echo:{$this->channel},{$this->event}" => 'handleUpdate'];
    }

    public function handleUpdate(array $payload = []): void
    {
        if ($this->identity && ($payload['triggerId'] ?? null) !== $this->identity) {
            return;
        }
        $this->lastTimestamp = now()->format('H:i:s');
        $this->dispatch('pulse-reset', id: $this->identity);
    }

    public function render()
    {
        return view('central-ui::livewire.update-bubble');
    }
}