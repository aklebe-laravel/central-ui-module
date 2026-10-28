@php
    use Modules\CentralUi\app\Http\Livewire\VmDesktop;
    use Modules\Form\app\Http\Livewire\Form\Base\NativeObjectBase;

    /**
     * @var NativeObjectBase $form_instance
     * @var array $data
     */

    $data['options'] = array_map(fn($label) => __($label), VmDesktop::detailOptions());
@endphp
<div>
    @include('form::components.form.select')
</div>
