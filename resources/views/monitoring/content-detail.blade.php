<x-layouts.app title="Content detail">
    <x-page-header title="Content detail" :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Content' => null]" />
    <livewire:monitoring.content-detail :content-item="\App\Modules\Monitoring\Models\ContentItem::query()->findOrFail(request()->route('contentItem'))" />
</x-layouts.app>
