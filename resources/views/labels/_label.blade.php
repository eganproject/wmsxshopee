{{-- Satu label. $label null = sel kosong (lewati stiker yang sudah terpakai di lembar A4). --}}
@if (! $label)
    <div class="label"></div>
@elseif ($isProduct)
    @php
        $withQr = $label['qr'] !== null;
        $withBars = $symbol !== 'qr';
        $showValue = $withBars && $label['barcode'];
    @endphp
    <div class="label">
        <div @class(['row', 'top' => $withQr && $withBars, 'fill' => ! $withBars])>
            @if ($withQr)
                <div class="qr">{!! $label['qr'] !!}</div>
            @endif
            <div class="text">
                @if ($flag('show_name'))
                    <div @class(['title clamp', 'long' => $symbol === 'qr'])>{{ $label['title'] }}</div>
                @endif
                @if ($flag('show_variant') && $label['subtitle'])
                    <div class="sub clamp">{{ $label['subtitle'] }}</div>
                @endif
                @if ($flag('show_sku'))
                    <div class="sku mono" data-fit>{{ $label['sku'] }}</div>
                @endif
                @if (! $withBars && $label['value'] !== $label['sku'])
                    <div class="mono" data-fit>{{ $label['value'] }}</div>
                @endif
            </div>
        </div>
        @if ($withBars)
            @if ($label['barcode'])
                <div class="bars">{!! $label['barcode'] !!}</div>
                <div class="value mono" data-fit>{{ $label['value'] }}</div>
            @else
                <div class="invalid">Barcode "{{ $label['value'] }}" tidak bisa dibuat</div>
            @endif
        @endif
    </div>
@else
    @php
        $withQr = $label['qr'] !== null;
        $withBars = $symbol !== 'qr';
    @endphp
    <div class="label">
        <div @class(['row', 'top' => $withQr && $withBars, 'fill' => ! $withBars || ! $withQr])>
            @if ($withQr)
                <div class="qr">{!! $label['qr'] !!}</div>
            @endif
            <div @class(['text', 'center' => ! $withQr])>
                @if ($flag('show_path'))
                    <div class="path">{{ $label['path'] }}</div>
                @endif
                <div class="code mono" data-fit>{{ $label['code'] }}</div>
                @if ($flag('show_path') && $label['detail'])
                    <div class="path">{{ $label['detail'] }}</div>
                @endif
            </div>
        </div>
        @if ($withBars)
            @if ($label['barcode'])
                <div class="bars" @style(['flex: 0 0 34%' => ! $withQr])>{!! $label['barcode'] !!}</div>
            @else
                <div class="invalid">Barcode "{{ $label['value'] }}" tidak bisa dibuat</div>
            @endif
        @endif
    </div>
@endif
