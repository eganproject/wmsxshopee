<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat log aktivitas. Perubahan data dicatat otomatis lewat trait
 * LogsActivity; class ini dipakai langsung untuk aktivitas yang tidak lewat
 * event model (login, hak akses role) dan untuk {@see track()}, yang
 * menggabungkan perubahan header + baris dokumen menjadi satu entri.
 */
class Activity
{
    /** Kolom yang tidak pernah dicatat. */
    private const IGNORED = ['id', 'created_at', 'updated_at', 'remember_token', 'last_login_at', 'created_by', 'sort_order'];

    /** Kolom rahasia: dicatat bahwa berubah, tapi nilainya disamarkan. */
    private const SECRET = ['password'];

    /** Label kolom yang sering muncul; sisanya memakai Str::headline. */
    private const LABELS = [
        'code' => 'Kode',
        'name' => 'Nama',
        'number' => 'Nomor',
        'status' => 'Status',
        'is_active' => 'Aktif',
        'all_warehouses' => 'Semua Gudang',
        'description' => 'Keterangan',
        'notes' => 'Catatan',
        'address' => 'Alamat',
        'city' => 'Kota',
        'phone' => 'Telepon',
        'email' => 'Email',
        'username' => 'Username',
        'password' => 'Password',
        'contact_name' => 'Nama Kontak',
        'category' => 'Kategori',
        'brand' => 'Merek',
        'warehouse_id' => 'Gudang',
        'warehouse_zone_id' => 'Zona',
        'warehouse_rack_id' => 'Rak',
        'supplier_id' => 'Supplier',
        'courier_id' => 'Kurir',
        'unit_id' => 'Satuan',
        'stock_movement_id' => 'Mutasi Stok',
        'receipt_date' => 'Tanggal Terima',
        'order_date' => 'Tanggal Pesanan',
        'supplier_reference' => 'No. PO / Surat Jalan',
        'attachment_path' => 'Lampiran',
        'channel_name' => 'Channel',
        'external_number' => 'No. Pesanan Channel',
        'customer_name' => 'Nama Pembeli',
        'customer_phone' => 'Telepon Pembeli',
        'shipping_address' => 'Alamat Kirim',
        'courier_service' => 'Layanan Kurir',
        'tracking_number' => 'No. Resi',
        'shipping_fee' => 'Ongkir',
        'cancel_reason' => 'Alasan Batal',
        'qc_skip_checked_at' => 'Dicek (Terlewat QC)',
        'qc_skip_checked_by' => 'Dicek Oleh (Terlewat QC)',
        'qc_skip_note' => 'Catatan Cek Terlewat QC',
        'reason' => 'Alasan',
        'rejection_reason' => 'Alasan Ditolak',
        'adjustment_date' => 'Tanggal Penyesuaian',
        'return_date' => 'Tanggal Retur',
        'source' => 'Sumber',
        'sales_order_id' => 'Pesanan',
        'sender_name' => 'Nama Pengirim',
        'sender_phone' => 'Telepon Pengirim',
        'external_return_number' => 'No. Retur Channel',
        'levels' => 'Jumlah Level',
        'level' => 'Level',
        'position' => 'Posisi',
        'type' => 'Jenis',
        'weight' => 'Berat',
        'price' => 'Harga',
        'cost_price' => 'Harga Modal',
        'barcode' => 'Barcode',
        'sku' => 'SKU',
        'payment_term_days' => 'Termin (hari)',
        'reference' => 'Referensi',
        'movement_date' => 'Tanggal Mutasi',
        'document_date' => 'Tanggal Dokumen',
        'recipient' => 'Penerima',
    ];

    /** @var array<int, true> Model yang sedang dicatat oleh track(), dikunci per objek. */
    private static array $muted = [];

    private static int $disabled = 0;

    /**
     * Catat satu aktivitas. Kegagalan mencatat log tidak boleh
     * menggagalkan aksi user, jadi error ditelan dan dilaporkan saja.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(
        string $event,
        string $module,
        string $description,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?User $user = null,
    ): ?ActivityLog {
        if (self::$disabled > 0) {
            return null;
        }

        try {
            $user ??= Auth::user();
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            return ActivityLog::create([
                'user_id' => $user?->getKey(),
                'user_name' => $user?->name,
                'module' => $module,
                'event' => $event,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject ? self::label($subject) : null,
                'description' => Str::limit($description, 250),
                'properties' => $old || $new ? ['old' => $old, 'new' => $new] : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Jalankan perubahan pada $subject (termasuk baris anak seperti item
     * dokumen atau varian produk) dan catat hasilnya sebagai satu entri
     * "Diubah" dengan nilai sebelum & sesudah. Event model untuk $subject
     * sendiri diredam selama callback berjalan supaya tidak tercatat dobel.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function track(Model $subject, Closure $callback): mixed
    {
        $before = self::snapshot($subject);
        $key = spl_object_id($subject);
        self::$muted[$key] = true;

        try {
            $result = $callback();
        } finally {
            unset(self::$muted[$key]);
        }

        $subject->refresh();
        [$old, $new] = self::diff($before, self::snapshot($subject));

        if ($old || $new) {
            self::record('updated', self::module($subject), self::name($subject).' '.self::label($subject).' diubah', $subject, $old, $new);
        }

        return $result;
    }

    /**
     * Jalankan callback tanpa mencatat log apa pun, cth. pembuatan lokasi bin
     * massal yang cukup dicatat sebagai satu ringkasan.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutLogging(Closure $callback): mixed
    {
        self::$disabled++;

        try {
            return $callback();
        } finally {
            self::$disabled--;
        }
    }

    public static function isMuted(Model $model): bool
    {
        return self::$disabled > 0 || isset(self::$muted[spl_object_id($model)]);
    }

    /**
     * Nilai kolom model + baris anak (dari activityRelations()) yang siap
     * dibandingkan dan ditampilkan, dengan kunci berupa label kolom.
     *
     * @param  array<int, string>|null  $only
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, ?array $only = null): array
    {
        $ignored = [...self::IGNORED, ...(method_exists($model, 'activityIgnored') ? $model->activityIgnored() : [])];
        $values = [];

        foreach ($model->getAttributes() as $attribute => $raw) {
            if (in_array($attribute, $ignored, true) || ($only !== null && ! in_array($attribute, $only, true))) {
                continue;
            }

            // Waktu & pelaku proses (confirmed_at, received_by, ...) sudah
            // tercermin di waktu dan user log itu sendiri.
            if (str_ends_with($attribute, '_at') || str_ends_with($attribute, '_by')) {
                continue;
            }

            $values[self::attributeLabel($attribute)] = self::value($model, $attribute);
        }

        if ($only === null && method_exists($model, 'activityRelations')) {
            foreach ($model->activityRelations() as $label => $value) {
                $values[$label] = is_array($value) ? array_values($value) : $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach (array_keys($before + $after) as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $old[$field] = $before[$field] ?? null;
                $new[$field] = $after[$field] ?? null;
            }
        }

        return [$old, $new];
    }

    public static function module(Model $model): string
    {
        return method_exists($model, 'activityModule') ? $model->activityModule() : Str::snake(class_basename($model));
    }

    public static function name(Model $model): string
    {
        return method_exists($model, 'activityName') ? $model->activityName() : Str::headline(class_basename($model));
    }

    public static function label(Model $model): string
    {
        if (method_exists($model, 'activityLabel')) {
            return $model->activityLabel();
        }

        $attributes = $model->getAttributes();

        return (string) ($attributes['number'] ?? $attributes['code'] ?? $attributes['sku'] ?? $attributes['name'] ?? '#'.$model->getKey());
    }

    public static function attributeLabel(string $attribute): string
    {
        return self::LABELS[$attribute] ?? Str::headline(Str::beforeLast($attribute, '_id'));
    }

    /**
     * Nilai satu kolom dalam bentuk yang terbaca: label status, Ya/Tidak,
     * tanggal, dan nama/kode untuk foreign key.
     */
    public static function value(Model $model, string $attribute): mixed
    {
        $raw = $model->getAttributes()[$attribute] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (in_array($attribute, self::SECRET, true)) {
            return '••••••';
        }

        if ($attribute === 'status' && defined($model::class.'::STATUSES')) {
            return $model::STATUSES[$raw]['label'] ?? $raw;
        }

        if ($attribute === 'reason' && defined($model::class.'::REASONS')) {
            return $model::REASONS[$raw] ?? $raw;
        }

        if ($attribute === 'source' && defined($model::class.'::SOURCES')) {
            return $model::SOURCES[$raw] ?? $raw;
        }

        if ($attribute === 'type' && defined($model::class.'::TYPES')) {
            $type = $model::TYPES[$raw] ?? $raw;

            return is_array($type) ? ($type['label'] ?? $raw) : $type;
        }

        if (str_ends_with($attribute, '_id')) {
            return self::relatedLabel($model, $attribute) ?? $raw;
        }

        $value = $model->getAttribute($attribute);

        return match (true) {
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            $value instanceof DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'd/m/Y' : 'd/m/Y H:i'),
            $value instanceof BackedEnum => $value->value,
            is_array($value) || is_object($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_float($value) || is_int($value) => $value,
            default => (string) $value,
        };
    }

    private static function relatedLabel(Model $model, string $attribute): ?string
    {
        $relation = Str::camel(Str::beforeLast($attribute, '_id'));

        foreach ([$relation, Str::after(Str::snake($relation), '_')] as $candidate) {
            $candidate = Str::camel($candidate);

            if (! method_exists($model, $candidate)) {
                continue;
            }

            try {
                $belongsTo = $model->{$candidate}();
            } catch (Throwable) {
                continue;
            }

            if ($belongsTo instanceof BelongsTo && $belongsTo->getForeignKeyName() === $attribute) {
                $related = $belongsTo->getRelated()->newQuery()->find($model->getAttributes()[$attribute]);

                if ($related) {
                    $attributes = $related->getAttributes();
                    $code = $attributes['code'] ?? $attributes['number'] ?? null;
                    $name = $attributes['name'] ?? null;

                    return $code && $name ? "{$code} · {$name}" : (string) ($code ?? $name ?? self::label($related));
                }
            }
        }

        return null;
    }
}
