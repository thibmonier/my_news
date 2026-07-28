<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Application Kernel — Symfony 8 + MicroKernelTrait.
 * Config loaded automatically from config/ directory.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
