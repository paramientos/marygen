<?php

namespace SoysalTan\MaryGen\Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use SoysalTan\MaryGen\Tests\TestCase;

class MaryGenCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the necessary facades and classes
        $this->mockFileFacade();
        $this->mockSchemaFacade();

        // Ensure the MaryUI package is "installed"
        $this->mockComposerJson();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_generates_livewire_component_for_existing_model()
    {
        // Arrange
        $modelName = 'Admin';
        $viewName = 'admin';

        // Act
        $exitCode = $this->artisan("marygen:make {$modelName} {$viewName}")->run();

        // Assert
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists(config('livewire.view_path') . "/{$viewName}.blade.php");
    }

    /** @test */
    public function it_fails_when_model_does_not_exist()
    {
        // Arrange
        $modelName = 'NonExistentModel';

        // Act
        $exitCode = $this->artisan("marygen:make {$modelName}")->run();


        // Assert
        $this->assertEquals(1, $exitCode);
    }

    /** @test */
    public function it_fails_when_view_file_already_exists()
    {
        // Arrange
        $modelName = 'User';
        $viewName = 'existing-view';
        File::shouldReceive('exists')->andReturn(true);

        // Act
        $exitCode = $this->artisan("marygen:make {$modelName} {$viewName}")->run();

        // Assert
        $this->assertEquals(1, $exitCode);
    }

    /** @test */
    public function it_uses_default_view_name_when_not_provided()
    {
        // Arrange
        $modelName = 'User';

        // Act
        $exitCode = $this->artisan("marygen:make {$modelName}")->run();

        // Assert
        $this->assertEquals(0, $exitCode);
        $this->assertFileExists(config('livewire.view_path') . "/user.blade.php");
    }

    /** @test */
    public function it_generates_correct_form_fields_based_on_model_schema()
    {
        // Arrange
        $modelName = 'User';
        $viewName = 'users';

        // Mock the Schema facade to return specific columns
        Schema::shouldReceive('getColumnListing')->andReturn(['id', 'name', 'email', 'password']);
        Schema::shouldReceive('getColumnType')->andReturn('integer', 'string', 'string', 'string');

        // Act
        $exitCode = $this->artisan("marygen:make {$modelName} {$viewName}")->run();

        // Assert
        $this->assertEquals(0, $exitCode);
        $generatedView = File::get(config('livewire.view_path') . "/{$viewName}.blade.php");
        $this->assertStringContainsString('<x-mary-input name="name"', $generatedView);
        $this->assertStringContainsString('<x-mary-input name="email"', $generatedView);
        $this->assertStringContainsString('<x-mary-input name="password" type="password"', $generatedView);
    }

    private function mockFileFacade()
    {
        File::shouldReceive('get')->andReturn('');
        File::shouldReceive('put')->andReturn(true);
        File::shouldReceive('exists')->andReturn(false);
    }

    private function mockSchemaFacade()
    {
        Schema::shouldReceive('getColumnListing')->andReturn(['id', 'name', 'email']);
        Schema::shouldReceive('getColumnType')->andReturn('integer', 'string', 'string');
    }

    private function mockComposerJson()
    {
        $composerJson = json_encode([
            'require' => [
                'robsontenorio/mary' => '^1.0'
            ]
        ]);
        File::shouldReceive('get')->with('composer.json')->andReturn($composerJson);
    }
}
