<?php

namespace SoysalTan\MaryGen\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SoysalTan\MaryGen\Facades\MaryGen;

class MaryGenCommand extends Command
{
    protected $signature = 'marygen:make {model : The name of the model} {viewName? : The name of the view file}';
    protected $description = 'Generate MaryUI components and a Livewire page for a given model';

    private string $ds = DIRECTORY_SEPARATOR;

    /**
     * @throws FileNotFoundException
     */
    public function handle()
    {
        if (!$this->checkPackageInComposerJson('robsontenorio/mary')) {
            $this->error('MaryUI package not found! Please install using: `composer req robsontenorio/mary`');
            return Command::FAILURE;
        }

        if (!$this->checkPackageInComposerJson('livewire/volt')) {
            $this->error('Livewire Volt package not found! Please run: composer require livewire/livewire livewire/volt && php artisan volt:install or see docs at: `https://livewire.laravel.com/docs/volt#installation`');
            return Command::FAILURE;
        }

        $modelName = $this->argument('model');
        $modelNamespace = config('marygen.model_namespace');

        $modelFqdn = "{$modelNamespace}\\{$modelName}";

        if (!class_exists($modelFqdn)) {
            $this->error("Model {$modelName} does not exist!");
            return Command::FAILURE;
        }

        $viewName = strtolower($this->argument('viewName') ?? $modelName);
        $viewFilePath = $this->getViewFilePath($viewName);

        if (file_exists($viewFilePath)) {
            $this->error("File {$viewName}.blade.php already exists!");
            return Command::FAILURE;
        }

        /** @var Model $modelInstance */
        $modelInstance = new $modelFqdn;

        $table = $modelInstance->getTable();
        $columns = Schema::getColumns($table);

        $modelKey = $modelInstance->getKeyName();

        $formFields = $this->generateFormFields($table, $columns, $modelKey);

        $tableColumns = $this->generateTableColumns($columns, $modelKey);
        $fieldTypes = $this->getTableFieldTypes($columns);
        $accessModifiers = $this->createAccessModifiers($fieldTypes);

        $cols = collect($columns)->pluck('name')->toArray();

        $livewirePage = $this->generateLivewirePage($cols, $modelName, $formFields, $tableColumns, $accessModifiers, $modelNamespace);

        $this->createLivewireFile($livewirePage, $viewFilePath);

        $route = $this->updateRoute($table, $viewName);
        $fullUrl = config('app.url') . '/' . $route;

        Artisan::call('view:clear');

        $this->info("✅ Done! Livewire page for `{$modelName}` has been generated successfully at `{$viewFilePath}`!");
        $this->info("✅ Route has been added to the routes/web.php file");
        $this->info("🌐 You can access your generated page via `{$fullUrl}`");

        return Command::SUCCESS;
    }

    private function getViewFilePath(string $viewName): string
    {
        $livewireViewDir = config('livewire.view_path');
        return "{$livewireViewDir}{$this->ds}{$viewName}.blade.php";
    }

    private function checkPackageInComposerJson(string $packageName): bool
    {
        $composerJson = file_get_contents(base_path('composer.json'));

        if ($composerJson === false) {
            throw new RuntimeException("Unable to read composer.json file");
        }

        $composerData = json_decode($composerJson, true);
        if ($composerData === null) {
            throw new RuntimeException("Invalid JSON in composer.json");
        }

        return isset($composerData['require'][$packageName]) || isset($composerData['require-dev'][$packageName]);
    }

    private function generateFormFields(string $table, array $columns, string $modelKey): string
    {
        $fields = '';
        $prefix = config('mary.prefix');

        foreach ($columns as $column) {
            if ($column['name'] === $modelKey) {
                continue;
            }

            $colName = $column['name'];
            $required = !$column['nullable'] ? 'required' : '';

            $type = Schema::getColumnType($table, $colName);
            $component = $this->getMaryUIComponent($type);
            $typeProp = $colName === 'password' ? 'type="password"' : '';
            $icon = $this->getIconForColumn($colName);

            $fields .= "<x-{$prefix}{$component} {$typeProp} wire:model=\"{$colName}\" {$icon} $required label=\"" . Str::headline($colName) . "\" />\n";
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

    private function getTableFieldTypes(array $columns): array
    {
        $fields = [];

        foreach ($columns as $column) {
            $fields[$column['name']] = [
                'type' => $this->getPropTypeFromTableField($column['type_name']),
                'required' => !$column['nullable']
            ];
        }
        return $fields;
    }

    private function createAccessModifiers(array $fields): string
    {
        return collect($fields)->map(function (array $field, string $id) {
            $validationRule = $field['required'] ? 'required' : 'nullable';

            return sprintf(
                '#[Validate(\'%s\')]%spublic %s%s $%s%s;%s%s',
                $validationRule,
                PHP_EOL,
                $field['required'] ? '' : '?',
                $field['type'],
                $id,
                $field['required'] ? '' : '= null',
                PHP_EOL,
                PHP_EOL
            );
        })->implode('');
    }

    private function generateTableColumns(array $columns, string $modelKey): string
    {
        $tableColumns = [];

        foreach ($columns as $column) {
            if ($column['name'] === $modelKey) {
                continue;
            }

            $label = Str::title(str_replace('_', ' ', $column['name']));

            $tableColumns[] = "['key' => '{$column['name']}', 'label' => '{$label}', 'sortable' => true],\n";
        }

        $tableColumns[] = "['key' => 'actions', 'label' => 'Actions', 'sortable' => false],";

        return implode('', $tableColumns);
    }

    private function generateLivewirePage(array $dbTableCols, string $modelName, string $formFields, string $tableColumns, string $accessModifiers = '', string $modelNamespace = 'App\Models'): string
    {
        $modelVariable = Str::camel($modelName);
        $pluralModelVariable = Str::plural($modelVariable);
        $pluralModelTitle = Str::title($pluralModelVariable);

        $modelFqdn = "{$modelNamespace}\\{$modelName}";

        $prefix = config('mary.prefix');

        /** @var Model $modelInstance */
        $modelInstance = new $modelFqdn;

        $modelKey = $modelInstance->getKeyName();
        $hasUuid = $modelInstance->usesUniqueIds();

        $sortingCol = in_array($modelInstance->getCreatedAtColumn(), $dbTableCols)
            ? $modelInstance->getCreatedAtColumn()
            : $modelKey;

        $singleQuote = $hasUuid ? "'" : '';

        $whereLikes = "['" . implode("','", $dbTableCols) . "']";

        $useMgDirective = (bool)config('marygen.use_mg_like_eloquent_directive');

        $mgSearchQuery = '';

        if ($useMgDirective) {
            $mgSearchQuery = "->when(\$this->search, fn(Builder \$q) => \$q->mgLike($whereLikes, \$this->search))";
        }

        return <<<EOT
<?php

namespace App\Livewire;

use Livewire\Volt\Component;
use $modelFqdn;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Validate;

new class extends Component
{
    use \Livewire\WithPagination;
    use \Mary\Traits\Toast;

    public array \$sortBy = ['column' => '$sortingCol', 'direction' => 'desc'];
    public int \$perPage = 10;
    public string \$search = '';
    
    public bool \$isEditModalOpen = false;
    public bool \$isCreateModalOpen = false;
    public bool \$isDeleteModalOpen = false;

    public {$modelName}|Model|null \$editingModel = null;
    public {$modelName}|Model|null \$modelToDelete = null;
    
    {$accessModifiers}

    public function openEditModal(string \$modelId): void
    {
        \$this->editingModel = {$modelName}::findOrFail(\$modelId);
        \$this->fill(\$this->editingModel->toArray());
        \$this->isEditModalOpen = true;
    }

    public function openCreateModal(): void
    {
        \$this->reset();
        \$this->isCreateModalOpen = true;
    }

    public function openDeleteModal({$modelName} \$model): void
    {
        \$this->modelToDelete = \$model;
        \$this->isDeleteModalOpen = true;
    }
    
     public function closeModal(): void
    {
        \$this->isEditModalOpen = false;
        \$this->isCreateModalOpen = false;
        \$this->editingModel = null;
    }
    
    public function deleteModel(): void
    {
        \$this->modelToDelete->delete();
        
        \$this->isDeleteModalOpen = false;
    }

   public function saveModel(): void
    {
        \$validated = \$this->validate();
        
        if (\$this->editingModel) {
            \$this->editingModel->update(\$validated);
            \$this->success('Record updated successfully.');
        } else {
            {$modelName}::create(\$validated);
            \$this->success('Record created successfully.');
        }
        \$this->closeModal();
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
             $mgSearchQuery
            ->orderBy(\$this->sortBy['column'], \$this->sortBy['direction'])
            ->paginate(15);
    }

    public function with(): array
    {
        return [
            'headers' => \$this->headers(),
            '{$pluralModelVariable}' => \$this->{$pluralModelVariable}(),
        ];
    }
}
?>

<div class="bg-gradient-to-br from-violet-50 via-purple-50 to-indigo-50 min-h-screen p-8">
    <x-{$prefix}header title="{$pluralModelTitle}" subtitle="{$modelName} List" separator progress-indicator class="mb-8">
        <x-slot:middle class="!justify-end">
            <x-{$prefix}input icon="o-magnifying-glass" placeholder="Search..." wire:model.live.debounce="search"
                     class="w-64 bg-white/70 backdrop-blur-sm border-violet-200 focus:border-violet-400 focus:ring focus:ring-violet-200 focus:ring-opacity-50 rounded-full"/>
        </x-slot:middle>
        <x-slot:actions>
          <x-{$prefix}button icon="o-plus" wire:click="openCreateModal"
                      class="bg-gradient-to-r from-violet-500 to-indigo-500 hover:from-violet-600 hover:to-indigo-600 text-white shadow-lg hover:shadow-xl transition duration-300 rounded-full px-6">
                Add New {$modelName}
            </x-{$prefix}button>
        </x-slot:actions>
    </x-{$prefix}header>
    
    <x-{$prefix}table :headers="\$headers" :rows="\${$pluralModelVariable}" :sort-by="\$sortBy" with-pagination class="w-full table-auto">
        @php
            /** @var $modelName \${$modelVariable} */
        @endphp
        @scope('cell_actions', \${$modelVariable})
        <div class="flex items-center space-x-2">
            <x-{$prefix}button tooltip="Edit" icon="o-pencil" wire:click="openEditModal({$singleQuote}{{ \${$modelVariable}->{$modelKey} }}{$singleQuote})"/>
            <x-{$prefix}button tooltip="Delete" icon="o-trash" wire:click="openDeleteModal({$singleQuote}{{ \${$modelVariable}->{$modelKey} }}{$singleQuote})"/>
        </div>
        @endscope
    </x-{$prefix}table>
    
     @if (\$isEditModalOpen)
         <x-{$prefix}modal wire:model="isEditModalOpen">
            <x-{$prefix}card title="Edit {$modelName}">
                <p class="text-gray-600">
                    Edit the details of the {$modelName}.
                </p>
                <div class="mt-4">
                    {$formFields}
                </div>
                <x-slot name="actions">
                    <div class="flex justify-end gap-x-4">
                        <x-{$prefix}button label="Cancel" wire:click="closeModal" icon="o-x-mark" />
                        <x-{$prefix}button label="Save" wire:click="saveModel" spinner class="btn-primary" icon="o-check-circle"/>
                    </div>
                </x-slot>
            </x-{$prefix}card>
        </x-{$prefix}modal>
    @endif

    
    @if (\$isCreateModalOpen)
        <x-{$prefix}modal wire:model="isCreateModalOpen">
            <x-{$prefix}card title="Create New {$modelName}">
                <p class="text-gray-600">
                    Enter the details for the new {$modelName}.
                </p>
                <div class="mt-4">
                    {$formFields}
                </div>
                <x-slot name="actions">
                    <div class="flex justify-end gap-x-4">
                        <x-{$prefix}button label="Cancel" wire:click="closeModal" icon="o-x-mark" />
                        <x-{$prefix}button label="Create" wire:click="saveModel" spinner class="btn-primary" icon="o-plus-circle"/>
                    </div>
                </x-slot>
            </x-{$prefix}card>
        </x-{$prefix}modal>
    @endif
    
    @if (\$isDeleteModalOpen)
         <x-{$prefix}modal wire:model="isDeleteModalOpen" title="Delete">
            <div>Are you sure you want to delete this record ?</div>
    
            <x-slot:actions>
                <x-{$prefix}button label="No" @click="\$wire.isDeleteModalOpen = false"/>
                <x-{$prefix}button label="Yes" wire:click="deleteModel" class="btn-primary"/>
            </x-slot:actions>
        </x-{$prefix}modal>
    @endif
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

        $viewName = str_replace('/', '.', $viewName);
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
