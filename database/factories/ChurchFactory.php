<?php

namespace Database\Factories;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * @extends Factory<Church>
 */
class ChurchFactory extends Factory
{
    protected $model = Church::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' Church';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'timezone' => 'Europe/Stockholm',
            'locale' => 'en',
            'is_active' => true,
        ];
    }

    /**
     * A church whose email module has SMTP settings, so it can send invites and reset links.
     *
     * @param  array<string, mixed>  $email
     */
    public function withEmailSettings(array $email = []): static
    {
        return $this->afterCreating(function (Church $church) use ($email): void {
            app(ChurchModuleManager::class)->enable($church, MessagingAvailability::MODULE_EMAIL, [
                'email' => array_merge([
                    'host' => 'smtp.church.test',
                    'port' => 587,
                    'encryption' => 'tls',
                    'from_address' => 'noreply@church.test',
                    'from_name' => '',
                ], $email),
            ]);
        });
    }
}
