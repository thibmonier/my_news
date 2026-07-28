<?php

// Briefly AI — Point d'entrée HTTP
// Utilise Symfony Runtime Component pour FrankenPHP worker mode.
// Ne jamais modifier ce fichier directement.

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
