@php
    $value ??= '?';
    $class ??= '';
    $icon ??= 'circle-question-mark';
    $lucideIcon = "lucide-{$icon}";
    $popupTitle ??= __('Unknown');
    $popupContent ??= '';
    $popupComponentView ??= '';
    $popupCustomData ??= [];
    $stableId ??= 'simple-text-default-1';
@endphp
<div class="inline-block text-[10px] px-1 badge badge-info rounded-full {{ $class }}">
    <div class="flex items-center">
        <x-dynamic-component :component="$lucideIcon" class="inline-block h-3 w-3 mr-1"/> {{ $value }}
    </div>
    <x-website-base::popup :title="$popupTitle" :content="$popupContent" :componentView="$popupComponentView" :customData="$popupCustomData" :stableId="$stableId"/>
</div>