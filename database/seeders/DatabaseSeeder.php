<?php

namespace Database\Seeders;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\SeedsDefaultTeamTypes;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(LanguagesSeeder::class);

        User::factory()->platformAdmin()->create([
            'name' => 'Platform Admin',
            'display_name' => 'Platform',
            'email' => 'admin@worshipplanning.test',
            'password' => Hash::make('password'),
        ]);

        $church = Church::factory()->create([
            'name' => 'Example Church',
            'slug' => 'example-church',
        ]);

        $this->call(RolesAndPermissionsSeeder::class);

        $churchAdmin = User::factory()->create([
            'church_id' => $church->id,
            'name' => 'Church Admin',
            'display_name' => 'Calum',
            'email' => 'calum@example.test',
            'password' => Hash::make('password'),
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($churchAdmin, 'Church Admin');

        $planner = User::factory()->create([
            'church_id' => $church->id,
            'name' => 'Event Planner',
            'display_name' => 'Planner',
            'email' => 'planner@example.test',
            'password' => Hash::make('password'),
        ]);
        RolesAndPermissionsSeeder::assignDefaultRoles($planner, 'Event Planner');

        $templates = [
            [
                'name' => 'Welcome',
                'default_order' => 10,
                'default_duration' => 5,
                'person_usage' => 'required',
                'description_usage' => 'optional',
                'song_usage' => 'not_used',
            ],
            [
                'name' => 'Opening song',
                'default_order' => 20,
                'default_duration' => 10,
                'person_usage' => 'optional',
                'description_usage' => 'optional',
                'song_usage' => 'required',
            ],
            [
                'name' => 'Prayer',
                'default_order' => 30,
                'default_duration' => 10,
                'person_usage' => 'required',
                'description_usage' => 'optional',
                'song_usage' => 'not_used',
            ],
            [
                'name' => 'Sermon',
                'default_order' => 40,
                'default_duration' => 45,
                'person_usage' => 'required',
                'description_usage' => 'required',
                'song_usage' => 'not_used',
            ],
            [
                'name' => 'Closing song',
                'default_order' => 50,
                'default_duration' => 10,
                'person_usage' => 'optional',
                'description_usage' => 'optional',
                'song_usage' => 'required',
            ],
        ];

        foreach ($templates as $template) {
            TaskTemplate::factory()->create([
                'church_id' => $church->id,
                ...$template,
            ]);
        }

        app(ChurchModuleManager::class)->enable($church, 'messaging');
        app(ChurchModuleManager::class)->enable($church, 'teams');
        SeedsDefaultTeamTypes::for($church);
    }
}
