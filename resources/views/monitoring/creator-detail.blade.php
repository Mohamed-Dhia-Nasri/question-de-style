<x-layouts.app title="Creator detail">
    <x-page-header title="Creator detail" :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Creators' => route('monitoring.creators.index'), 'Detail' => null]" />
    <livewire:monitoring.creator-detail :creator="\App\Modules\CRM\Models\Creator::query()->findOrFail(request()->route('creator'))" />
</x-layouts.app>
