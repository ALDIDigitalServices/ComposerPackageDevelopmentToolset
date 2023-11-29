# Composer Package Development Toolset
A Composer plugin that enables you to develop your Composer packages right inside your project without altering its composer.json/composer.lock.
This works by symlinking the development packages into the vendor directory, replacing existing installations.

## Installation
Add the package to your dev dependencies:
```shell
composer require --dev aldidigitalservices/composer-package-development-toolset
```
When prompted to allow this plugin confirm with `y`.

## Usage
### Development Package Location
The development packages are automatically registered by scanning the `dev-packages` directory.
Its default location is in your project's root directory, ensuring your packages are available in your Docker container and adding code completion for project code in the development packages.  
However, the location can be changed by adding the following to your composer.json:

```json
"extra": {
    "composer-package-development-toolset": {
        "package-dir": "dev-packages"
    }
}
```

### Workflow
As your composer.json and composer.lock won't be altered, Composer will remove the development package's symlinks on certain actions to match the content of these files.
This plugin hooks into these actions and restores the symlinks afterwards, ensuring a seamless experience.
