<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Record harus terlihat lewat Eloquent (lolos global scope akses gudang),
 * sehingga user tidak bisa mengirim ID milik gudang lain lewat form.
 */
class AccessibleRecord implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(private string $model) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || ! $this->model::query()->whereKey($value)->exists()) {
            $fail(':Attribute yang dipilih di luar gudang yang bisa Anda akses.');
        }
    }
}
