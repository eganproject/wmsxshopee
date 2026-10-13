@include('errors.layout', [
    'code' => 429,
    'icon' => 'heroicon-o-hand-raised',
    'title' => 'Terlalu Banyak Permintaan',
    'message' => 'Anda mengirim terlalu banyak permintaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.',
])
