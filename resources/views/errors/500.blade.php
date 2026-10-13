@include('errors.layout', [
    'code' => 500,
    'icon' => 'heroicon-o-exclamation-triangle',
    'title' => 'Terjadi Kesalahan',
    'message' => 'Ada masalah di server saat memproses permintaan Anda. Data yang sedang diproses tidak tersimpan. Coba lagi, dan hubungi administrator jika terus terjadi.',
])
