<?php

declare(strict_types=1);

// OPcache preload — généré par cache:warmup --env=prod
// Référencé par docker/php/app.ini (opcache.preload)
// tech-spec §14.2 : worker mode FrankenPHP

$cacheFile = __DIR__ . '/../var/cache/prod/App_KernelProdContainer.preload.php';

if (file_exists($cacheFile)) {
    require_once $cacheFile;
}
