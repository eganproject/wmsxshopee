<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * Catat otomatis buat, ubah, ubah status & hapus pada model ini ke log
 * aktivitas. Model wajib mendefinisikan:
 *
 *   activityModule(): string  kunci modul (sama dengan modul permission)
 *   activityName(): string    nama jenis data, cth. "Supplier"
 *
 * Opsional: activityLabel(), activityIgnored() dan activityRelations()
 * (baris anak, cth. item dokumen, yang ikut dicatat sebagai daftar).
 */
trait LogsActivity
{
    /** @var array<string, mixed>|null Isi model sebelum dihapus. */
    protected ?array $activityDeleting = null;

    /** Log "dibuat" masih menunggu commit; perubahan sebelum itu ikut di log tersebut. */
    protected bool $activityPendingCreate = false;

    public static function bootLogsActivity(): void
    {
        // Dicatat setelah transaksi selesai supaya baris anak (item/varian)
        // yang dibuat sesudah header ikut tercatat; batal kalau rollback.
        static::created(function (Model $model) {
            if (Activity::isMuted($model)) {
                return;
            }

            $model->activityPendingCreate = true;

            DB::afterCommit(function () use ($model) {
                $model->activityPendingCreate = false;
                Activity::record('created', Activity::module($model), Activity::name($model).' '.Activity::label($model).' dibuat',
                    $model, [], array_filter(Activity::snapshot($model), fn ($value) => $value !== null && $value !== []));
            });
        });

        static::updated(function (Model $model) {
            if (Activity::isMuted($model) || $model->activityPendingCreate) {
                return;
            }

            $changed = array_keys($model->getChanges());
            $after = Activity::snapshot($model, $changed);

            if (! $after) {
                return;
            }

            // Nilai lama dibaca dari original: dibuat model sementara supaya
            // cast & label relasi sama dengan nilai baru.
            $before = Activity::snapshot((clone $model)->setRawAttributes($model->getRawOriginal()), $changed);
            [$old, $new] = Activity::diff($before, $after);

            if (! $old && ! $new) {
                return;
            }

            $name = Activity::name($model).' '.Activity::label($model);

            if (in_array('status', $changed, true)) {
                $statusLabel = Activity::attributeLabel('status');
                Activity::record('status', Activity::module($model),
                    "{$name}: {$old[$statusLabel]} → {$new[$statusLabel]}", $model, $old, $new);
            } elseif ($changed === ['is_active'] || array_keys($new) === [Activity::attributeLabel('is_active')]) {
                Activity::record('status', Activity::module($model),
                    $name.($model->getAttribute('is_active') ? ' diaktifkan' : ' dinonaktifkan'), $model, $old, $new);
            } else {
                Activity::record('updated', Activity::module($model), "{$name} diubah", $model, $old, $new);
            }
        });

        static::deleting(function (Model $model) {
            $model->activityDeleting = Activity::isMuted($model) ? null : Activity::snapshot($model);
        });

        static::deleted(function (Model $model) {
            if ($model->activityDeleting === null) {
                return;
            }

            Activity::record('deleted', Activity::module($model), Activity::name($model).' '.Activity::label($model).' dihapus',
                $model, array_filter($model->activityDeleting, fn ($value) => $value !== null && $value !== []), []);
        });
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('created_at')->latest('id');
    }
}
