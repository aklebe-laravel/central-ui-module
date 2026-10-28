@php
    $value ??= 0;
    $class ??= '';
    $icon ??= 'circle-question-mark';
    $lucideIcon = "lucide-{$icon}";
    $min ??= 25;
    $max ??= 75;
    $popupTitle ??= __('Unknown');
    $popupContent ??= '';
    $popupComponentView ??= '';
    $popupCustomData ??= [];
    $stableId ??= 'simple-number-default-1';
@endphp
<div class="w-max mx-auto text-[10px] px-1 badge {{ ($value < $min) ? 'badge-muted' : (($value < $max) ? 'badge-success' : 'badge-error') }} rounded-full {{ $class }}">
    <div class="flex items-center">
        <x-dynamic-component :component="$lucideIcon" class="inline-block h-3 w-3 mr-1"/> {{ $value }}%
    </div>
    <x-website-base::popup :title="$popupTitle" :content="$popupContent" :componentView="$popupComponentView" :customData="$popupCustomData" :stableId="$stableId"/>
</div>