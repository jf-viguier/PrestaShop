# Test: Override install/uninstall broken with PHP 8.1+ visibility-modified constants

## Description

The `addOverride()` and `removeOverride()` methods in `classes/module/Module.php` do not correctly handle PHP 8.1+ visibility-modified constants (`public const`, `protected const`, `private const`).

### Bug 1: Comment marker misplacement on install

When a module override contains a constant with a visibility modifier such as:

```php
public const CODE_MAX_LENGTH = 64;
```

The current regex in `addOverride()`:
```php
'/(const\s)\s*(\b' . $constant . '\b)/ism'
```

Only captures `const CODE_MAX_LENGTH` but **not** the `public` keyword. The replacement inserts the comment block between `public` and `const`, producing **corrupted** output:

```php
public /*
    * module: demooverrideobjectmodel
    * date: 2026-01-28 10:00:00
    * version: 1.0.0
    */
    const CODE_MAX_LENGTH = 64;
```

Instead of the expected correct output:

```php
/*
    * module: demooverrideobjectmodel
    * date: 2026-01-28 10:00:00
    * version: 1.0.0
    */
    public const CODE_MAX_LENGTH = 64;
```

### Bug 2: Override removal fails when module source files are deleted

`uninstallOverrides()` scans the module's **local** override directory to find classes to clean up. If the module's override source files were deleted or changed since installation, the installed overrides become orphaned and cannot be removed.

Similarly, `removeOverride()` loads the module's source override file to identify which methods/properties/constants to remove. If this file no longer exists, the method fails.

## Reproduction test module

A modified version of [demooverrideobjectmodel](https://github.com/PrestaShop/example-modules/tree/master/demooverrideobjectmodel) has been created as a test fixture.

The module override (`override/classes/Manufacturer.php`) contains:

```php
class Manufacturer extends ManufacturerCore
{
    public const CODE_MAX_LENGTH = 64;        // public visibility (PHP 8.1+)
    protected const CODE_PREFIX = 'MFR-';     // protected visibility (PHP 8.1+)
    public $custom_code;                       // property

    public function getFullCode(): string      // method
    {
        return self::CODE_PREFIX . ($this->custom_code ?? '');
    }
}
```

## Steps to reproduce

### Bug 1 (comment marker misplacement):
1. Copy the test module to `modules/demooverrideobjectmodel/`
2. Install the module via back office or CLI
3. Open `override/classes/Manufacturer.php`
4. Observe that `public` appears **before** the comment block instead of after it for constants with visibility modifiers

### Bug 2 (orphaned overrides):
1. Install the module
2. Delete `modules/demooverrideobjectmodel/override/classes/Manufacturer.php`
3. Uninstall the module
4. Observe that `override/classes/Manufacturer.php` still contains the module's overrides

## Integration test

An integration test has been added at:
- **Test:** `tests/Integration/Classes/module/ModuleOverrideVisibilityConstantTest.php`
- **Test module:** `tests/Resources/modules_tests/demooverrideobjectmodel/`

The test covers three scenarios:
1. **`testInstallOverrideWithVisibilityConstants`** -- Verifies comment markers are placed before (not between) visibility modifiers
2. **`testUninstallOverrideWithVisibilityConstants`** -- Verifies constants with visibility modifiers are properly cleaned up on uninstall
3. **`testUninstallOverrideAfterModuleSourceDeleted`** -- Verifies `uninstallOverrides()` works even when module override source files are deleted

## Expected behavior

- Comment markers should be placed **before** the full constant declaration including the visibility modifier
- Override removal should work regardless of the current state of the module's source files
- The regex should handle `public const`, `protected const`, `private const`, and typed constants (`public const string FOO`)

## Related

- Fix PR: https://github.com/PrestaShop/PrestaShop/pull/40239
