# MaryGen Documentation

## Table of Contents
1. [Introduction](#introduction)
2. [Installation](#installation)
3. [Usage](#usage)
4. [Command Structure](#command-structure)
5. [Generated Components](#generated-components)
6. [Customization](#customization)
7. [Troubleshooting](#troubleshooting)
8. [Contributing](#contributing)

## Introduction

MaryGen is a powerful Laravel package designed to streamline the process of generating MaryUI components and Livewire pages for your Laravel models. It automates the creation of CRUD (Create, Read, Update, Delete) interfaces, saving developers significant time and effort in setting up admin panels or data management systems.

## Installation

To install MaryGen, follow these steps:

1. Ensure you have a Laravel project set up.

   For more detailed information about MaryUI, including its features, installation process, and usage, please visit the official MaryUI documentation:
   https://mary-ui.com/docs/installation


2. Install the MaryUI package:
   ```
   composer require robsontenorio/mary
   ```
3. Install the MaryGen package:
   ```
   composer require soysaltan/mary-gen
   ```

4. You can publish the config file with (optional):
    ```bash
    php artisan vendor:publish --provider="SoysalTan\MaryGen\MaryGenServiceProvider" --tag="config"
    ```

## Usage

To generate a MaryUI component and Livewire page for a model, use the following command:

```
php artisan mary-gen:make {model} {viewName?}
```

- `{model}`: The name of the model for which you want to generate the components.
- `{name?}`: (Optional) The name of the view file. If not provided, it will use the lowercase model name.

Example:
```
php artisan mary-gen:make User
```

This command will generate a Livewire page for the User model with CRUD functionality.

## Command Structure

The `MaryGenCommand` class is responsible for generating the components. Here's a brief overview of its main methods:

- `handle()`: The main method that orchestrates the generation process.
- `generateFormFields()`: Generates form fields based on the model's table structure.
- `generateTableColumns()`: Creates table columns for displaying the model data.
- `generateLivewirePage()`: Generates the Livewire component with CRUD functionality.
- `updateRoute()`: Adds a route for the newly created page.

## Generated Components

MaryGen generates the following components:

1. A Livewire component class with:
    - CRUD operations
    - Sorting functionality
    - Pagination
    - Search capability
2. A Blade view with:
    - A table displaying the model data
    - A modal for editing records
    - A form for creating/editing records

## Customization

You can customize the generated components by modifying the following methods in the `MaryGenCommand` class:

- `getMaryUIComponent()`: Customize the mapping between database column types and MaryUI components.
- `getIconForColumn()`: Modify the icon selection for form fields.
- `generateLivewirePage()`: Adjust the structure of the generated Livewire component and Blade view.

## Troubleshooting

Common issues and their solutions:

1. **MaryUI package not found**: Ensure you've installed the MaryUI package using `composer require robsontenorio/mary`.
2. **Model not found**: Make sure the specified model exists in your `App\Models` namespace.
3. **View file already exists**: If you receive this error, choose a different name for your view or manually delete the existing file if you want to overwrite it.

## Contributing

Contributions to MaryGen are welcome! Here's how you can contribute:

1. Fork the repository
2. Create a new branch for your feature or bug fix
3. Write your code and tests
4. Submit a pull request with a clear description of your changes

Please ensure your code adheres to the existing style conventions and includes appropriate tests.

---

For more information or support, please open an issue on the GitHub repository or contact the package maintainer.
