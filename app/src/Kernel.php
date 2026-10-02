<?php

namespace App;

use App\Profiler\RedactingSerializerDataCollector;
use App\Profiler\SensitiveCasters;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // SEC-01, lot 09: the API key must not survive in the profiler's
        // dumps, which read their casters before any request is handled.
        SensitiveCasters::register();

        parent::boot();
    }

    protected function build(ContainerBuilder $container): void
    {
        // SEC-01, lot 09: the serializer's panel dumps the request body
        // as it came in — the redacting subclass masks the `_password`
        // field on the way in, panel kept.
        $container->addCompilerPass(new class () implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition('serializer.data_collector')) {
                    $container->getDefinition('serializer.data_collector')->setClass(RedactingSerializerDataCollector::class);
                }
            }
        });
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
