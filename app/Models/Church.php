<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Church extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'uses_24_hour_time' => true,
    ];

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'locale',
        'uses_24_hour_time',
        'logo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'uses_24_hour_time' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Church $church): void {
            if (filled($church->logo_path)) {
                Storage::disk('public')->delete($church->logo_path);
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(ChurchModule::class);
    }

    public function hasModule(string $key): bool
    {
        return $this->modules()
            ->where('module_key', $key)
            ->where('enabled', true)
            ->exists();
    }

    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Absolute filesystem path for DomPDF (must be local-readable).
     */
    public function logoAbsolutePath(): ?string
    {
        if (blank($this->logo_path) || ! Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }

        return Storage::disk('public')->path($this->logo_path);
    }
}
