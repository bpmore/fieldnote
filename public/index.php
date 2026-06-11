<?php

/**
 * Public entry point. The only PHP file in the web root that runs the app.
 * All application code, config, and data live one level up, outside public/.
 */

require dirname(__DIR__) . '/src/routes.php';
