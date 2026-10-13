<x-layouts.app :title="'Edit '.$order->number">
    <x-slot:breadcrumb>
        <x-heroicon-o-home class="size-4" />
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.index') }}" class="hover:text-slate-700">Pesanan</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <a href="{{ route('sales.orders.show', $order) }}" class="hover:text-slate-700">{{ $order->number }}</a>
        <x-heroicon-o-chevron-right class="size-3.5" />
        <span class="font-medium text-slate-700">Edit</span>
    </x-slot:breadcrumb>

    <x-page-header :title="'Edit '.$order->number" description="Draft masih bisa diubah sampai dikonfirmasi." icon="heroicon-o-pencil-square">
        <a href="{{ route('sales.orders.show', $order) }}" class="btn-secondary"><x-heroicon-o-arrow-left class="size-5" /> Kembali</a>
    </x-page-header>

    <form method="POST" action="{{ route('sales.orders.update', $order) }}" data-dirty-guard>
        @csrf
        @method('PUT')
        @include('sales.orders._form')
        @include('sales.orders._actions')
    </form>
</x-layouts.app>
