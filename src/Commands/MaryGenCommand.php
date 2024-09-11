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
use Stichoza\GoogleTranslate\Exceptions\LargeTextException;
use Stichoza\GoogleTranslate\Exceptions\RateLimitException;
use Stichoza\GoogleTranslate\Exceptions\TranslationRequestException;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Symfony\Component\Console\Input\InputOption;

class MaryGenCommand extends Command
{
    protected $name = 'marygen:make';
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

        $modelName = $this->option('model');
        $modelNamespace = config('marygen.model_namespace');

        $modelFqdn = "{$modelNamespace}\\{$modelName}";

        if (!class_exists($modelFqdn)) {
            $this->error("Model {$modelName} does not exist!");
            return Command::FAILURE;
        }

        $viewName = strtolower($this->option('view') ?? $modelName);
        $viewFilePath = $this->getViewFilePath($viewName);

        if (file_exists($viewFilePath)) {
            $this->error("File {$viewName}.blade.php already exists!");
            return Command::FAILURE;
        }

        if ($this->option('source_lang') && !$this->option('dest_lang')) {
            $this->error("Destination language is required if source language presents!");
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
        $accessModifiers = $this->createAccessModifiers($fieldTypes, $modelKey);

        $cols = collect($columns)->pluck('name')->toArray();

        $livewirePage = $this->generateLivewirePage($cols, $modelName, $formFields, $tableColumns, $accessModifiers, $modelNamespace);

        $this->createLivewireFile($livewirePage, $viewFilePath);

        if ($this->option('no-route')) {
            $route = $this->updateRoute($table, $viewName);
            $fullUrl = config('app.url') . '/' . $route;
        }

        Artisan::call('view:clear');

        $this->info("✅ Done! Livewire page for `{$modelName}` has been generated successfully at `{$viewFilePath}`!");

        if ($this->option('no-route')) {
            $this->info("✅ Route has been added to the routes/web.php file");
            $this->info("🌐 You can access your generated page via `{$fullUrl}`");
        }

        return Command::SUCCESS;
    }

    protected function getOptions()
    {
        return [
            ['--model', 'm', InputOption::VALUE_REQUIRED, 'The name of the model to generate components for'],
            ['--view', 'w', InputOption::VALUE_OPTIONAL, 'The name of the view file (defaults to lowercase model name)'],
            ['--dest_lang', 'd', InputOption::VALUE_OPTIONAL, 'The destination language for translation'],
            ['--source_lang', 's', InputOption::VALUE_OPTIONAL, 'The source language for translation'],
            ['--no-route', 'nr', InputOption::VALUE_OPTIONAL, 'Prevent automatic route addition'],
        ];
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

    /**
     * @throws LargeTextException
     * @throws RateLimitException
     * @throws TranslationRequestException
     */
    private function generateFormFields(string $table, array $columns, string $modelKey): string
    {
        $fields = '';
        $prefix = config('mary.prefix');

        if ($this->option('dest_lang')) {
            $tr = new GoogleTranslate();
            $tr->setSource($this->option('source_lang') ?? null);
            $tr->setTarget($this->option('dest_lang'));
        }

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

            $label = Str::headline($colName);

            if ($this->option('dest_lang')) {
                $label = $tr->translate($label);
            }

            $fields .= "<x-{$prefix}{$component} {$typeProp} wire:model=\"{$colName}\" {$icon} $required label=\"" . $label . "\" />\n";
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

    private function createAccessModifiers(array $fields, string $modelKey): string
    {
        return collect($fields)->except([$modelKey])->map(function (array $field, string $id) {
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

    /**
     * @throws LargeTextException
     * @throws RateLimitException
     * @throws TranslationRequestException
     */
    private function generateTableColumns(array $columns, string $modelKey): string
    {
        $tableColumns = [];

        if ($this->option('dest_lang')) {
            $tr = new GoogleTranslate();
            $tr->setSource($this->option('source_lang') ?? null);
            $tr->setTarget($this->option('dest_lang'));
        }

        foreach ($columns as $column) {
            // Ignore id field
            if ($column['name'] === $modelKey) {
                continue;
            }

            $label = Str::headline($column['name']);

            if ($this->option('dest_lang')) {
                $label = $tr->translate($label);
            }

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

        $lowerModelName = mb_strtolower($modelName, 'UTF-8');

        $_ = [
            'listTitle' => $pluralModelTitle,
            'listSubTitle' => "{$modelName} List",

            'addNew' => "Add New {$modelName}",
            'createModalTitle' => "Create New {$modelName}",
            'createModalDescription' => "Enter the details for the new {$lowerModelName}.",

            'updateModalTitle' => "Update {$lowerModelName}",
            'updateModalDescription' => "Edit the details of the {$lowerModelName}.",

            'deleteModalTitle' => "Delete {$lowerModelName}",
            'deleteModalDescription' => 'Are you sure you want to delete this record ?',

            'save' => 'Save',
            'update' => 'Update',
            'delete' => 'Delete',
            'create' => 'Create',
            'edit' => 'Edit',
            'search' => 'Search',
            'cancel' => 'Cancel',
            'yes' => 'Yes',
            'no' => 'No',
        ];

        if ($this->option('dest_lang')) {
            $tr = new GoogleTranslate();
            $tr->setSource($this->option('source_lang') ?? null);
            $tr->setTarget($this->option('dest_lang'));

            $_['listTitle'] = $tr->translate($_['listTitle']);
            $_['listSubTitle'] = $tr->translate($_['listSubTitle']);

            $_['addNew'] = $tr->translate($_['addNew']);
            $_['createModalTitle'] = $tr->translate($_['createModalTitle']);
            $_['createModalDescription'] = $tr->translate($_['createModalDescription']);

            $_['updateModalTitle'] = $tr->translate($_['updateModalTitle']);
            $_['updateModalDescription'] = $tr->translate($_['updateModalDescription']);

            $_['deleteModalTitle'] = $tr->translate($_['deleteModalTitle']);
            $_['deleteModalDescription'] = $tr->translate($_['deleteModalDescription']);

            $_['save'] = $tr->translate($_['save']);
            $_['update'] = $tr->translate($_['update']);
            $_['delete'] = $tr->translate($_['delete']);
            $_['create'] = $tr->translate($_['create']);
            $_['cancel'] = $tr->translate($_['cancel']);
            $_['yes'] = $tr->translate($_['yes']);
            $_['no'] = $tr->translate($_['no']);
            $_['search'] = $tr->translate($_['search']);
            $_['edit'] = $tr->translate($_['edit']);
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

<div>
    <x-{$prefix}header title="{$_['listTitle']}" subtitle="{$_['listSubTitle']}" separator progress-indicator class="mb-8">
        <x-slot:middle class="!justify-end">
            <x-{$prefix}input icon="o-magnifying-glass" placeholder="{$_['search']}..." wire:model.live.debounce="search"
                     class="w-64 bg-white/70 backdrop-blur-sm border-violet-200 focus:border-violet-400 focus:ring focus:ring-violet-200 focus:ring-opacity-50"/>
        </x-slot:middle>
        <x-slot:actions>
          <x-{$prefix}button icon="o-plus" wire:click="openCreateModal"
                      class="btn-primary">
                {$_['addNew']}
            </x-{$prefix}button>
        </x-slot:actions>
    </x-{$prefix}header>

    <x-{$prefix}table :headers="\$headers" :rows="\${$pluralModelVariable}" :sort-by="\$sortBy" with-pagination class="w-full table-auto">
        @php
            /** @var $modelName \${$modelVariable} */
        @endphp
        @scope('cell_actions', \${$modelVariable})
        <div class="flex items-center space-x-2">
            <x-{$prefix}button tooltip="{$_['edit']}" icon="o-pencil" wire:click="openEditModal({$singleQuote}{{ \${$modelVariable}->{$modelKey} }}{$singleQuote})"/>
            <x-{$prefix}button tooltip="{$_['delete']}" icon="o-trash" wire:click="openDeleteModal({$singleQuote}{{ \${$modelVariable}->{$modelKey} }}{$singleQuote})"/>
        </div>
        @endscope
    </x-{$prefix}table>

     @if (\$isEditModalOpen)
         <x-{$prefix}modal wire:model="isEditModalOpen">
            <x-{$prefix}card title="{$_['updateModalTitle']}">
                <p class="text-gray-600">
                    {$_['updateModalDescription']}
                </p>
                <div class="mt-4 grid gap-3">
                    {$formFields}
                </div>
                <x-slot name="actions">
                    <div class="flex justify-end gap-x-4">
                        <x-{$prefix}button label="{$_['cancel']}" wire:click="closeModal" icon="o-x-mark" />
                        <x-{$prefix}button label="{$_['save']}" wire:click="saveModel" spinner class="btn-primary" icon="o-check-circle"/>
                    </div>
                </x-slot>
            </x-{$prefix}card>
        </x-{$prefix}modal>
    @endif


    @if (\$isCreateModalOpen)
        <x-{$prefix}modal wire:model="isCreateModalOpen">
            <x-{$prefix}card title="{$_['createModalTitle']}">
                <p class="text-gray-600">
                    {$_['createModalDescription']}
                </p>
                <div class="mt-4 grid gap-3">
                    {$formFields}
                </div>
                <x-slot name="actions">
                    <div class="flex justify-end gap-x-4">
                        <x-{$prefix}button label="{$_['cancel']}" wire:click="closeModal" icon="o-x-mark" />
                        <x-{$prefix}button label="{$_['create']}" wire:click="saveModel" spinner class="btn-primary" icon="o-plus-circle"/>
                    </div>
                </x-slot>
            </x-{$prefix}card>
        </x-{$prefix}modal>
    @endif

    @if (\$isDeleteModalOpen)
         <x-{$prefix}modal wire:model="isDeleteModalOpen" title="{$_['deleteModalTitle']}">
            <div>{$_['deleteModalDescription']}</div>

            <x-slot:actions>
                <x-{$prefix}button label="{$_['no']}" @click="\$wire.isDeleteModalOpen = false"/>
                <x-{$prefix}button label="{$_['yes']}" wire:click="deleteModel" class="btn-primary"/>
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
        $this->createFile($filePath, $content);
    }

    private function createFile(string $filePath, string $content = ''): bool
    {
        $pathInfo = pathinfo($filePath);

        $dirPath = $pathInfo['dirname'];

        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        if (file_put_contents($filePath, $content) !== false) {
            return true;
        }

        return false;
    }
}
