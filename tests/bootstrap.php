<?php

// LabelRenderer needs nothing but GD. The plugin class extends Grav's Plugin,
// so its tests load a Grav install: GRAV_ROOT, or the site this plugin sits in.
$root = getenv('GRAV_ROOT') ?: dirname(__DIR__, 4);
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
    defined('GRAV_ROOT') || define('GRAV_ROOT', $root);
}

require_once dirname(__DIR__) . '/classes/LabelRenderer.php';
