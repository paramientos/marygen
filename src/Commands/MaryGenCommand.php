<?php

namespace Soysaltan\MaryGen\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MaryGenCommand extends Command
{
    protected $signature = 'mary-gen:make {model : The name of the model} {viewName? : The name of the view file}';
    protected $description = 'Generate MaryUI components and a Livewire page for a given model';

    public function handle()
    {
        if (!$this->checkPackageInComposerJson('robsontenorio/mary')) {
            $this->error('MaryUI package not found! Please install using: `composer req robsontenorio/mary`');
            return Command::FAILURE;
        }

        $modelName = $this->argument('model');
        $modelNamespace = config('marygen.model_namespace');

        $modelClass = "{$modelNamespace}\\{$modelName}";

        if (!class_exists($modelClass)) {
            $this->error("Model {$modelName} does not exist!");
            return Command::FAILURE;
        }

        $viewName = strtolower($this->argument('viewName') ?? $modelName);
        $viewFilePath = $this->getViewFilePath($viewName);

        if (file_exists($viewFilePath)) {
            $this->error("File {$viewName}.blade.php already exists!");
            return Command::FAILURE;
        }

        $table = (new $modelClass)->getTable();
        $columns = Schema::getColumnListing($table);

        $formFields = $this->generateFormFields($table, $columns);
        $tableColumns = $this->generateTableColumns($columns);
        $fieldTypes = $this->getTableFieldTypes($table, $columns);
        $accessModifiers = $this->createAccessModifiers($fieldTypes);

        $livewirePage = $this->generateLivewirePage($modelName, $formFields, $tableColumns, $accessModifiers, $modelNamespace);

        $this->createLivewireFile($livewirePage, $viewFilePath);

        $route = $this->updateRoute($table, $viewName);
        $fullUrl = config('app.url') . '/' . $route;

        Artisan::call('view:clear');

        $this->info("✅ Done! Livewire page for `{$modelName}` has been generated successfully at `{$viewFilePath}`!");
        $this->info("🌐 You can access your generated page via `{$fullUrl}`");

        return Command::SUCCESS;
    }

    private function getViewFilePath(string $viewName): string
    {
        $livewireViewDir = config('livewire.view_path');
        return "{$livewireViewDir}/{$viewName}.blade.php";
    }

    private function checkPackageInComposerJson(string $packageName): bool
    {
        $composerJson = file_get_contents('composer.json');
        if ($composerJson === false) {
            throw new \RuntimeException("Unable to read composer.json file");
        }

        $composerData = json_decode($composerJson, true);
        if ($composerData === null) {
            throw new \RuntimeException("Invalid JSON in composer.json");
        }

        return isset($composerData['require'][$packageName]) || isset($composerData['require-dev'][$packageName]);
    }

    private function generateFormFields(string $table, array $columns): string
    {
        $fields = '';
        $prefix = config('mary.prefix');

        foreach ($columns as $column) {
            $type = Schema::getColumnType($table, $column);
            $component = $this->getMaryUIComponent($type);
            $typeProp = $column === 'password' ? 'type="password"' : '';
            $icon = $this->getIconForColumn($column);

            $fields .= "<x-{$prefix}{$component} name=\"{$column}\" {$typeProp} wire:model=\"{$column}\" {$icon} label=\"" . Str::title($column) . "\" />\n";
        }

        return $fields;
    }

    private function getIconForColumn(string $column): string
    {
        $iconMap = [
            'mail' => 'o-envelope',
            'password' => 'o-lock-closed',
            'username' => 'o-user',
            'avatar' => 'o-user-circle',
            'phone' => 'o-phone',
            'time' => 'o-clock',
            'status' => 'o-check-circle',
        ];

        foreach ($iconMap as $key => $icon) {
            if (str_contains($column, $key)) {
                return "icon=\"{$icon}\"";
            }
        }

        return '';
    }

    private function getMaryUIComponent(string $type): string
    {
        $componentMap = [
            'varchar' => 'input',
            'text' => 'textarea',
            'integer' => 'number',
            'bigint' => 'number',
            'bool' => 'checkbox',
            'time' => 'datepicker',
            'timestamp' => 'datepicker',
        ];

        return $componentMap[$type] ?? 'input';
    }

    private function getPropTypeFromTableField(string $type): string
    {
        $typeMap = [
            'integer' => 'int',
            'bigint' => 'int',
            'bool' => 'bool',
        ];

        return $typeMap[$type] ?? 'string';
    }

    private function getTableFieldTypes(string $table, array $columns): array
    {
        $fields = [];
        foreach ($columns as $column) {
            $type = Schema::getColumnType($table, $column);
            $fields[$column] = $this->getPropTypeFromTableField($type);
        }
        return $fields;
    }

    private function createAccessModifiers(array $fields): string
    {
        return collect($fields)->map(function ($type, $id) {
            return sprintf('#[Validate(\'sometimes\')]%spublic ?%s $%s = null;%s%s', PHP_EOL, $type, $id, PHP_EOL, PHP_EOL);
        })->implode('');
    }

    private function generateTableColumns($columns): string
    {
        $tableColumns = array_map(function ($column) {
            $label = Str::title(str_replace('_', ' ', $column));
            return "['key' => '{$column}', 'label' => '{$label}', 'sortable' => true],\n";
        }, $columns);

        $tableColumns[] = "['key' => 'actions', 'label' => 'Actions', 'sortable' => false],";

        return implode('', $tableColumns);
    }

    private function generateLivewirePage(string $modelName, string $formFields, string $tableColumns, string $accessModifiers = '', string $modelNamespace = 'App\Models'): string
    {
        $modelVariable = Str::camel($modelName);
        $pluralModelVariable = Str::plural($modelVariable);
        $pluralModelTitle = Str::title($pluralModelVariable);
        $prefix = config('mary.prefix');

        return <<<EOT
<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use {$modelNamespace}\\{$modelName};
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Validate;

new class extends Component
{
    use \Livewire\WithPagination;
    use \Mary\Traits\Toast;

    public array \$sortBy = ['column' => 'created_at', 'direction' => 'desc'];
    public int \$perPage = 10;
    public string \$search = '';
    public bool \$isModalOpen = false;
    public {$modelName}|Model|null \$editingModel = null;
    
    {$accessModifiers}
    
    public function getModelFields()
    {
        \$table = (new {$modelName}())->getTable();
        \$columns = Schema::getColumnListing(\$table);
        return collect(\$columns)->mapWithKeys(function (\$column) use (\$table) {
            \$type = Schema::getColumnType(\$table, \$column);
            return [\$column => [
                'type' => \$this->getMaryUIComponentType(\$type),
                'label' => ucfirst(str_replace('_', ' ', \$column)),
            ]];
        })->all();
    }

    public function getMaryUIComponentType(string \$type): string
    {
        \$componentMap = [
            'text' => 'textarea',
            'integer' => 'number',
            'bigint' => 'number',
            'boolean' => 'checkbox',
            'date' => 'datepicker',
            'datetime' => 'datetimepicker',
        ];
        return \$componentMap[\$type] ?? 'input';
    }

    public function openEditModal(string \$modelId): void
    {
        \$this->editingModel = {$modelName}::findOrFail(\$modelId);
        \$this->fill(\$this->editingModel->toArray());
        \$this->isModalOpen = true;
    }

    public function closeModal(): void
    {
        \$this->isModalOpen = false;
        \$this->editingModel = null;
    }

    public function saveModel(): void
    {
        \$validated = \$this->validate();
        \$this->editingModel->update(\$validated);
        \$this->closeModal();
        \$this->success('Record updated successfully.');
    }

    public function headers(): array
    {
        return [
{$tableColumns}
        ];
    }

    public function sort(\$column): void
    {
        \$this->sortBy['direction'] = (\$this->sortBy['column'] === \$column)
            ? (\$this->sortBy['direction'] === 'asc' ? 'desc' : 'asc')
            : 'asc';
        \$this->sortBy['column'] = \$column;
    }

    public function {$pluralModelVariable}(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return {$modelName}::query()
            ->orderBy(\$this->sortBy['column'], \$this->sortBy['direction'])
            ->paginate(15);
    }

    public function with(): array
    {
        return [
            'headers' => \$this->headers(),
            '{$pluralModelVariable}' => \$this->{$pluralModelVariable}(),
            'modelFields' => \$this->getModelFields(),
        ];
    }
}
?>

<div class="bg-gradient-to-br from-violet-50 via-purple-50 to-indigo-50 min-h-screen p-8">
    <x-header title="{$pluralModelTitle}" subtitle="{$modelName} List" separator progress-indicator class="mb-8">
        <x-slot:middle class="!justify-end">
            <x-input icon="o-magnifying-glass" placeholder="Search..." wire:model.live.debounce="search"
                     class="w-64 bg-white/70 backdrop-blur-sm border-violet-200 focus:border-violet-400 focus:ring focus:ring-violet-200 focus:ring-opacity-50 rounded-full"/>
        </x-slot:middle>
        <x-slot:actions>
            <x-button icon="o-plus" @click="\$wire.create()"
                      class="bg-gradient-to-r from-violet-500 to-indigo-500 hover:from-violet-600 hover:to-indigo-600 text-white shadow-lg hover:shadow-xl transition duration-300 rounded-full px-6">
                Add New {$modelName}
            </x-button>
        </x-slot:actions>
    </x-header>
    
    <x-{$prefix}table :headers="\$headers" :rows="\${$pluralModelVariable}" :sort-by="\$sortBy" with-pagination class="w-full table-auto">
        @php
            /** @var $modelName \${$modelVariable} */
        @endphp
        @scope('cell_actions', \${$modelVariable})
        <div class="flex items-center space-x-2">
            <x-{$prefix}button tooltip="Edit" icon="o-pencil" wire:click="openEditModal('{{ \${$modelVariable}->id }}')"/>
        </div>
        @endscope
    </x-{$prefix}table>
    
    <x-{$prefix}modal wire:model="isModalOpen">
        <x-{$prefix}card title="Edit {$modelName}">
            <p class="text-gray-600">
                Edit the details of the {$modelName}.
            </p>
            <div class="mt-4">
                {$formFields}
            </div>
            <x-{$prefix}slot name="actions">
                <div class="flex justify-end gap-x-4">
                    <x-button label="Cancel" wire:click="closeModal" icon="o-x-mark" />
                    <x-button label="Save" wire:click="saveModel" spinner class="btn-primary" icon="o-check-circle"/>
                </div>
            </x-{$prefix}slot>
        </x-{$prefix}card>
    </x-{$prefix}modal>
</div>
EOT;
    }

    /**
     * @throws FileNotFoundException
     */
    private function updateRoute(string $tableName, string $viewName): string
    {
        $webRouteContent = File::get(base_path('routes/web.php'));
        $uri = str($tableName)->kebab();
        $route = "Volt::route('/{$uri}', '{$viewName}');";

        if (!str($webRouteContent)->contains($route)) {
            File::put(base_path('routes/web.php'), str($webRouteContent)->append("\r\n")->append($route));
        }

        return $uri;
    }

    private function createLivewireFile(string $content, string $filePath): void
    {
        file_put_contents($filePath, $content);
    }
}
