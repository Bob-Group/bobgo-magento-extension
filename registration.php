<?php
// Guard the registration call so the module can be composer-autoloaded outside
// a Magento context (CI, PHPStan, third-party static analysis). Inside Magento,
// ComponentRegistrar is always present and the registration runs normally.
if (class_exists(\Magento\Framework\Component\ComponentRegistrar::class)) {
    \Magento\Framework\Component\ComponentRegistrar::register(
        \Magento\Framework\Component\ComponentRegistrar::MODULE,
        'BobGroup_BobGo',
        __DIR__
    );
}
