@php
    $value ??= false;
    $class ??= '';
    $icon ??= 'circle-question-mark';
    $lucideIcon = "lucide-{$icon}";
    $popupTitle ??= __('Unknown');
    $popupContent ??= '';
    $popupComponentView ??= '';
    $popupCustomData ??= [];
    $stableId ??= 'simple-status-default-1';
@endphp
<div class="w-max mx-auto text-[10px] px-1 badge {{ ($value) ? 'badge-muted' : 'badge-warning' }} rounded-full {{ $class }}">
    <div class="flex items-center">
        <x-dynamic-component :component="$lucideIcon" class="inline-block h-3 w-3"/>
    </div>
    <x-website-base::popup :title="$popupTitle" :content="$popupContent" :componentView="$popupComponentView" :customData="$popupCustomData" :stableId="$stableId"/>
</div>
