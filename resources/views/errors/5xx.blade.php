@include('errors.layout', [
    'code' => $exception->getStatusCode(),
    'icon' => 'heroicon-o-exclamation-triangle',
    'title' => 'Terjadi Kesalahan',
    'message' => 'Ada masalah di server. Coba lagi beberapa saat lagi, dan hubungi administrator jika terus terjadi.',
])
