<?php

declare(strict_types=1);

/**
 * AutowirePHP benchmark: runtime reflection vs the compiled plan.
 *
 * Three measurements on a layered graph of 35 classes (interface bindings, a
 * couple of singletons, nullable and union parameters):
 *
 *   1. runtime-reflection get() of the root, steady state;
 *   2. compiled get() of the root, steady state (plan already in memory);
 *   3. the one-off cost: first get() over a cold cache (compile + store +
 *      build) and over a warm cache (load + manifest validation + build).
 *
 * Honesty notes: the containers run without a logger and without a dispatcher
 * — observability is not free and a production setup with observers will see
 * a smaller relative win. The compiled path deliberately keeps full
 * observability parity (every child id passes through a complete get() frame),
 * which eats part of the theoretical speedup by design.
 */

require __DIR__ . '/../vendor/autoload.php';

use AutowirePHP\Benchmarks\ArrayCache;
use AutowirePHP\Benchmarks\Fixtures\AppKernel;
use AutowirePHP\Benchmarks\Fixtures\PortA;
use AutowirePHP\Benchmarks\Fixtures\PortAImpl;
use AutowirePHP\Benchmarks\Fixtures\PortB;
use AutowirePHP\Benchmarks\Fixtures\PortBImpl;
use AutowirePHP\Benchmarks\Fixtures\PortC;
use AutowirePHP\Benchmarks\Fixtures\PortCImpl;
use AutowirePHP\Benchmarks\Fixtures\PortD;
use AutowirePHP\Benchmarks\Fixtures\PortDImpl;
use AutowirePHP\Container;
use Psr\SimpleCache\CacheInterface;

const WARMUP = 100;
const ITERATIONS = 500;
const ONE_OFF_REPS = 30;

function makeContainer(?CacheInterface $cache): Container
{
    $container = new Container(null, null, $cache);
    $container->bind(PortA::class, PortAImpl::class);
    $container->singleton(PortB::class, PortBImpl::class);
    $container->bind(PortC::class, PortCImpl::class);
    $container->bind(PortD::class, PortDImpl::class);

    return $container;
}

/**
 * @param list<float> $samples seconds
 */
function median(array $samples): float
{
    sort($samples);
    $count = count($samples);
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? $samples[$middle]
        : ($samples[$middle - 1] + $samples[$middle]) / 2;
}

/**
 * @param callable(): void $probe
 * @return list<float>
 */
function sample(callable $probe, int $iterations): array
{
    $samples = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        $probe();
        $samples[] = microtime(true) - $start;
    }

    return $samples;
}

function us(float $seconds): string
{
    return sprintf('%8.1f us', $seconds * 1e6);
}

// --- (1) runtime reflection, steady state -----------------------------------

$runtime = makeContainer(null);

for ($i = 0; $i < WARMUP; $i++) {
    $runtime->get(AppKernel::class);
}

$runtimeSamples = sample(static function () use ($runtime): void {
    $runtime->get(AppKernel::class);
}, ITERATIONS);

// --- (2) compiled, steady state ----------------------------------------------

$cache = new ArrayCache();
$compiled = makeContainer($cache);

for ($i = 0; $i < WARMUP; $i++) {
    $compiled->get(AppKernel::class); // first iteration compiles and stores
}

$compiledSamples = sample(static function () use ($compiled): void {
    $compiled->get(AppKernel::class);
}, ITERATIONS);

// --- (3) one-off costs --------------------------------------------------------

$coldSamples = sample(static function (): void {
    makeContainer(new ArrayCache())->get(AppKernel::class);
}, ONE_OFF_REPS);

$warmCache = new ArrayCache();
makeContainer($warmCache)->get(AppKernel::class);

$warmSamples = sample(static function () use ($warmCache): void {
    makeContainer($warmCache)->get(AppKernel::class);
}, ONE_OFF_REPS);

// --- report -------------------------------------------------------------------

$runtimeMedian = median($runtimeSamples);
$compiledMedian = median($compiledSamples);

printf("AutowirePHP benchmark — PHP %s\n", PHP_VERSION);
printf("Graph: 35 classes (root %s); no logger, no dispatcher attached\n", AppKernel::class);
printf("Steady state: median over %d iterations after %d warmup runs\n\n", ITERATIONS, WARMUP);

printf("(1) runtime-reflection get(root):        %s\n", us($runtimeMedian));
printf("(2) compiled get(root), steady state:    %s   (%.2fx faster)\n", us($compiledMedian), $runtimeMedian / $compiledMedian);
printf(
    "(3) one-off first get(), cold cache:     %s   (compile + store + build, median of %d)\n",
    us(median($coldSamples)),
    ONE_OFF_REPS,
);
printf(
    "    one-off first get(), warm cache:     %s   (load + manifest validation + build)\n",
    us(median($warmSamples)),
);
