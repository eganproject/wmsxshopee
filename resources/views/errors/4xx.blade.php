@include('errors.layout', [
    'code' => $exception->getStatusCode(),
    'icon' => 'heroicon-o-exclamation-circle',
    'title' => 'Permintaan Tidak Bisa Diproses',
    'message' => 'Permintaan ini tidak bisa diproses. Kembali ke halaman sebelumnya lalu coba lagi.',
])
