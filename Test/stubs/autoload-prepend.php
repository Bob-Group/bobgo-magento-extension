<?php
/**
 * This file is loaded via PHPUnit --prepend BEFORE the composer autoloader.
 * It stubs Magento\Framework\Component\ComponentRegistrar so that
 * registration.php (included by composer autoload.files) does not fatal.
 */
if (!class_exists(\Magento\Framework\Component\ComponentRegistrar::class, false)) {
    eval('
        namespace Magento\Framework\Component;
        class ComponentRegistrar {
            public const MODULE = "module";
            public static function register(string $type, string $name, string $path): void {}
        }
    ');
}
