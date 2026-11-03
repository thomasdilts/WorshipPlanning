<?php

namespace App\Core\Modules;

use App\Models\Church;
use InvalidArgumentException;

class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    protected array $modules = [];

    public function register(ModuleDefinition $definition): void
    {
        if (isset($this->modules[$definition->key])) {
            throw new InvalidArgumentException("Module [{$definition->key}] is already registered.");
        }

        $this->modules[$definition->key] = $definition;
    }

    /**
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $key): ?ModuleDefinition
    {
        return $this->modules[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    /**
     * Locked modules count as off for every church, whatever is stored (see config/demo.php).
     */
    public function isLocked(string $key): bool
    {
        return config('demo.enabled') && in_array($key, config('demo.locked_modules', []), true);
    }

    public function enabledFor(Church $church, string $key): bool
    {
        $definition = $this->get($key);

        if ($definition === null || $this->isLocked($key)) {
            return false;
        }

        if ($definition->required) {
            return true;
        }

        return $church->hasModule($key);
    }
}
