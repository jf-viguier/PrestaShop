<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Tests\Integration\Classes\module;

use Context;
use Employee;
use Module;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\Addon\Module\ModuleManagerBuilder;
use PrestaShop\PrestaShop\Core\Module\ModuleManager;
use Tests\Resources\ResourceResetter;
use Tools;

/**
 * Test that module override install/uninstall correctly handles
 * PHP 8.1+ visibility-modified constants (public const, protected const).
 *
 * Related to: https://github.com/PrestaShop/PrestaShop/pull/40239
 *
 * @group isolatedProcess
 */
class ModuleOverrideVisibilityConstantTest extends TestCase
{
    private const MODULE_NAME = 'demooverrideobjectmodel';
    private const OVERRIDE_FILE = _PS_ROOT_DIR_ . '/override/classes/Manufacturer.php';

    private ModuleManager $moduleManager;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $dirResources = dirname(__DIR__, 3);
        $source = $dirResources . '/Resources/modules_tests/' . self::MODULE_NAME;

        if (is_dir($source)) {
            Tools::recurseCopy($source, _PS_MODULE_DIR_ . '/' . self::MODULE_NAME);
        }
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (Module::isInstalled(self::MODULE_NAME)) {
            Module::getInstanceByName(self::MODULE_NAME)->uninstall();
        }

        if (is_dir(_PS_MODULE_DIR_ . '/' . self::MODULE_NAME)) {
            Tools::deleteDirectory(_PS_MODULE_DIR_ . '/' . self::MODULE_NAME);
        }

        @unlink(self::OVERRIDE_FILE);

        (new ResourceResetter())->resetTestModules();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Context::getContext()->employee = new Employee(1);
        $this->moduleManager = ModuleManagerBuilder::getInstance()->build();
    }

    /**
     * Test that addOverride() correctly places module comment markers
     * BEFORE visibility modifiers on constants, not between the modifier and 'const'.
     *
     * BUG (without fix): "public const FOO = 1;" becomes:
     *   public /*
     *   * module: demooverrideobjectmodel
     *   * ...
     *   * /
     *   const FOO = 1;
     *
     * EXPECTED (with fix): "public const FOO = 1;" becomes:
     *   /*
     *   * module: demooverrideobjectmodel
     *   * ...
     *   * /
     *   public const FOO = 1;
     */
    public function testInstallOverrideWithVisibilityConstants(): void
    {
        $this->assertTrue(
            (bool) $this->moduleManager->install(self::MODULE_NAME),
            'Module ' . self::MODULE_NAME . ' should install successfully'
        );

        $this->assertFileExists(
            self::OVERRIDE_FILE,
            'Override file for Manufacturer should exist after install'
        );

        $overrideContent = file_get_contents(self::OVERRIDE_FILE);

        // Check that module comment markers exist for constants
        $this->assertStringContainsString(
            'module: ' . self::MODULE_NAME,
            $overrideContent,
            'Override file should contain module comment markers'
        );

        // CRITICAL: Check that "public" does NOT appear BEFORE the comment block for constants.
        // The bug causes the comment to be inserted between "public" and "const", producing
        // "public /* ... */ const FOO" instead of "/* ... */ public const FOO".
        $this->assertDoesNotMatchRegularExpression(
            '/public\s+\/\*\s*\n\s*\*\s*module:\s*' . self::MODULE_NAME . '/m',
            $overrideContent,
            'Comment marker must not be placed between visibility modifier and const keyword. '
            . 'The visibility modifier "public" should come AFTER the comment block, not before it.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/protected\s+\/\*\s*\n\s*\*\s*module:\s*' . self::MODULE_NAME . '/m',
            $overrideContent,
            'Comment marker must not be placed between visibility modifier and const keyword. '
            . 'The visibility modifier "protected" should come AFTER the comment block, not before it.'
        );

        // Check that "public const" appears intact AFTER the comment block
        $this->assertMatchesRegularExpression(
            '/\*\/\s*\n\s*public\s+const\s+CODE_MAX_LENGTH/m',
            $overrideContent,
            'The "public const CODE_MAX_LENGTH" declaration must appear intact after the closing comment marker'
        );

        $this->assertMatchesRegularExpression(
            '/\*\/\s*\n\s*protected\s+const\s+CODE_PREFIX/m',
            $overrideContent,
            'The "protected const CODE_PREFIX" declaration must appear intact after the closing comment marker'
        );

        // Also verify method and property markers are correct
        $this->assertMatchesRegularExpression(
            '/\*\/\s*\n\s*public\s+function\s+getFullCode/m',
            $overrideContent,
            'The method getFullCode should have its comment marker correctly placed'
        );

        $this->assertMatchesRegularExpression(
            '/\*\/\s*\n\s*public\s+\$custom_code/m',
            $overrideContent,
            'The property $custom_code should have its comment marker correctly placed'
        );
    }

    /**
     * Test that removeOverride() correctly removes constants with visibility modifiers.
     * This test depends on testInstallOverrideWithVisibilityConstants running first.
     *
     * @depends testInstallOverrideWithVisibilityConstants
     */
    public function testUninstallOverrideWithVisibilityConstants(): void
    {
        $this->assertTrue(
            (bool) $this->moduleManager->uninstall(self::MODULE_NAME),
            'Module ' . self::MODULE_NAME . ' should uninstall successfully'
        );

        // After uninstall, the override file should be removed entirely
        // (since no other module contributed overrides to Manufacturer)
        $this->assertFileDoesNotExist(
            self::OVERRIDE_FILE,
            'Override file for Manufacturer should be removed after uninstall '
            . '(no other module overrides this class)'
        );
    }

    /**
     * Test that uninstallOverrides() works even when the module's
     * local override source files have been deleted.
     *
     * This tests the second bug fixed by PR #40239: the old code scanned the module's
     * own override directory, which fails if files were removed from the module.
     */
    public function testUninstallOverrideAfterModuleSourceDeleted(): void
    {
        // First install the module normally
        $this->assertTrue(
            (bool) $this->moduleManager->install(self::MODULE_NAME),
            'Module should install successfully'
        );

        $this->assertFileExists(self::OVERRIDE_FILE, 'Override file should exist after install');

        // Now simulate the module's override source files being deleted
        // (e.g., a module update removed some override files)
        $moduleOverridePath = _PS_MODULE_DIR_ . '/' . self::MODULE_NAME . '/override/classes/Manufacturer.php';
        if (file_exists($moduleOverridePath)) {
            unlink($moduleOverridePath);
        }

        // Uninstall should still clean up the installed overrides
        $module = Module::getInstanceByName(self::MODULE_NAME);
        $this->assertNotFalse($module, 'Module instance should still be retrievable');

        $result = $module->uninstallOverrides();
        $this->assertTrue(
            (bool) $result,
            'uninstallOverrides() should succeed even when module override source files are deleted'
        );

        // The override file should be cleaned up
        $this->assertFileDoesNotExist(
            self::OVERRIDE_FILE,
            'Override file should be removed even when module source files were deleted'
        );

        // Clean up: uninstall the module
        $module->uninstall();
    }
}
