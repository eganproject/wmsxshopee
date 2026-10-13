@include('errors.layout', [
    'code' => 403,
    'icon' => 'heroicon-o-lock-closed',
    'title' => 'Akses Ditolak',
    'message' => $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.'
        ? $exception->getMessage()
        : 'Anda tidak memiliki izin untuk membuka halaman ini. Hubungi administrator jika membutuhkan akses.',
])
