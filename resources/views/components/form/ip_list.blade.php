@php
    use Illuminate\Http\Resources\Json\JsonResource;
    use Modules\SystemBase\app\Services\ModuleService;
    use Modules\WebsiteBase\app\Http\Livewire\Form\UserProfile;
    use Modules\Form\app\Http\Livewire\Form\Base\NativeObjectBase;

    /**
     * @var NativeObjectBase $form_instance
     * @var array $data
     */

    //$data['value'] = implode(',', data_get($data['value'],'*.name', []));
    //dump($form_instance->dataTransfer);
    //dump($data['value']);
@endphp
<div>
    @include('form::components.form.text')
</div>