<?php

declare(strict_types=1);

namespace AutowirePHP\Compiled;

use AutowirePHP\Attribute\Inject;
use AutowirePHP\Attribute\Singleton;
use AutowirePHP\Exception\NotFoundException;
use AutowirePHP\Exception\NotInstantiableException;
use AutowirePHP\Exception\UnresolvableParameterException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Walks the dependency graph with reflection exactly once and produces a
 * CompiledPlan, so that subsequent resolutions can interpret the plan without
 * touching the Reflection API.
 *
 * The classification of a constructor parameter reproduces the branch order of
 * Container::resolveParameter() verbatim — that order is semantically fragile
 * and this class is its second implementor. Any change there must be mirrored
 * here; the parity harness in CompiledContainerTest guards the equivalence.
 *
 * Compilation instantiates nothing (its own #[Inject]/#[Singleton] attributes
 * excepted), dispatches no events, writes none of the resolution log records
 * and never touches the container's resolution stack: it keeps its own path
 * set purely to terminate the walk. Cycles are deliberately NOT recorded into
 * the plan — a cycle is a property of the execution path, not of the graph
 * node, and its chain message depends on the entry point. The untouched
 * enter()/leave() of the container catches it at runtime.
 *
 * Default values are never evaluated here either: getDefaultValue() executes
 * the initializer expression (a new-in-initializer runs a constructor), which
 * would both instantiate user code during compilation and evaluate defaults
 * the runtime path only evaluates after a failed probe. A defaulted parameter
 * therefore compiles into a reference spec (class + position) that the
 * executor evaluates lazily, at the same point in control flow as the runtime
 * path.
 *
 * @internal Not part of the public API; Container is the only entry point.
 */
final class PlanCompiler
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $nodes = [];

    /**
     * Path-scoped set of ids currently being compiled, for walk termination
     * only. Encountering an id already on the path just records a reference
     * spec: the in-flight frame completes the node, the plan stays finite.
     *
     * @var array<string, true>
     */
    private array $path = [];

    /**
     * File path -> [mtime, size] for every file whose reflection was read.
     *
     * @var array<string, array{int, int}>
     */
    private array $manifest = [];

    /**
     * @var array<string, true>
     */
    private array $missing = [];

    /**
     * @param array<class-string, class-string> $bindings
     * @param array<string, true> $shared
     */
    public function __construct(
        private readonly array $bindings,
        private readonly array $shared,
    ) {
    }

    public function compile(string $rootId, string $configHash): CompiledPlan
    {
        $this->compileId($rootId);

        return CompiledPlan::create(
            $configHash,
            $rootId,
            $this->nodes,
            $this->manifest,
            array_keys($this->missing),
        );
    }

    private function compileId(string $id): void
    {
        if (isset($this->nodes[$id]) || isset($this->path[$id])) {
            return;
        }

        $this->path[$id] = true;

        try {
            $this->nodes[$id] = $this->compileNode($id);
        } finally {
            unset($this->path[$id]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function compileNode(string $id): array
    {
        $concrete = $this->bindings[$id] ?? $id;

        if (!class_exists($concrete) && !interface_exists($concrete)) {
            $this->missing[$concrete] = true;

            // The executor never actually replays this node: Container::get()
            // runs the identical class_exists()/interface_exists() check and
            // throws its own NotFoundException before a missing id's node is
            // ever looked up (see the ordering in Container::get()). The node
            // is still built for two reasons that have nothing to do with the
            // executor reaching it: it keeps compileId() total (every id gets
            // a node, so the caller never has to special-case "absent from
            // the map"), and $missing above is what lets staleness() notice
            // when a previously-absent type later appears.
            return CompiledPlan::failNode(NotFoundException::class, [$concrete]);
        }

        $reflection = new ReflectionClass($concrete);
        $this->recordManifest($reflection);

        if (!$reflection->isInstantiable()) {
            // Mirrors the reason wording of Container::instantiate(), so the
            // replayed exception is character-for-character the same.
            if ($reflection->isInterface()) {
                $reason = 'It is an interface with no binding registered.';
            } elseif ($reflection->isAbstract()) {
                $reason = 'It is an abstract class.';
            } else {
                $reason = 'It cannot be instantiated (e.g. private or protected constructor).';
            }

            return CompiledPlan::failNode(NotInstantiableException::class, [$concrete, $reason]);
        }

        // Same priority as Container::sharedReason(): singleton() wins over
        // #[Singleton] when both apply.
        $sharedReason = isset($this->shared[$id])
            ? 'singleton()'
            : ($reflection->getAttributes(Singleton::class) !== [] ? '#[Singleton]' : null);

        $constructor = $reflection->getConstructor();
        $args = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                // A variadic parameter ends the argument list, encoded simply
                // as the absence of specs after it.
                if ($param->isVariadic()) {
                    break;
                }

                $args[] = $this->compileParameter($param, $concrete);
            }
        }

        return CompiledPlan::buildNode($concrete, $sharedReason, $args);
    }

    /**
     * One-shot replay of the resolveParameter() branch order: union ->
     * class type (#[Inject] with explicit-binding priority -> non-nullable ->
     * nullable probe) -> default -> untyped failure -> nullable null ->
     * builtin failure -> union failure.
     *
     * @return array<string, mixed>
     */
    private function compileParameter(
        ReflectionParameter $param,
        string $declaringClass,
    ): array {
        $type = $param->getType();

        if ($type instanceof ReflectionUnionType) {
            return $this->compileUnionParameter($type, $param, $declaringClass);
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();
            $injected = $this->injectTarget($param, $typeName);

            if ($injected !== null) {
                $this->compileId($injected);

                return CompiledPlan::serviceSpec($injected, [
                    'parameter' => $param->getName(),
                    'class' => $declaringClass,
                    'target' => $injected,
                ]);
            }

            $this->compileId($typeName);

            if (!$type->allowsNull()) {
                return CompiledPlan::serviceSpec($typeName, null);
            }

            // The probe stays a probe even though the structural outcome is
            // known at compile time: baking null would erase the child's
            // ResolutionRequested/ResolutionFailed events, and a constructor
            // may throw a ContainerException subclass only at runtime. The
            // fallback is a reference, not a value: the runtime path only
            // evaluates the default after the probe failed, so must we.
            $fallback = $param->isDefaultValueAvailable()
                ? CompiledPlan::defaultSpec($declaringClass, $param->getPosition())
                : CompiledPlan::nullSpec();

            return CompiledPlan::probeSpec($typeName, $fallback);
        }

        if ($param->isDefaultValueAvailable()) {
            return CompiledPlan::defaultSpec($declaringClass, $param->getPosition());
        }

        if ($type === null) {
            return CompiledPlan::failSpec(UnresolvableParameterException::class, [
                $declaringClass,
                $param->getName(),
                'It has no type hint and no default value.',
            ]);
        }

        if ($param->allowsNull()) {
            return CompiledPlan::nullSpec();
        }

        if ($type instanceof ReflectionNamedType) {
            return CompiledPlan::failSpec(UnresolvableParameterException::class, [
                $declaringClass,
                $param->getName(),
                sprintf('It is of built-in type "%s" and has no default value.', $type->getName()),
            ]);
        }

        return CompiledPlan::failSpec(UnresolvableParameterException::class, [
            $declaringClass,
            $param->getName(),
            sprintf('It has type "%s" and no member of it could be resolved.', (string) $type),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function compileUnionParameter(
        ReflectionUnionType $type,
        ReflectionParameter $param,
        string $declaringClass,
    ): array {
        $members = [];

        // Candidates in declaration order, filtered only by "named class-type
        // member": structurally unresolvable members stay in the list so their
        // probe events are not erased. #[Inject] on a union parameter is
        // silently ignored, exactly as on the runtime path.
        foreach ($type->getTypes() as $member) {
            if (!$member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            $members[] = $member->getName();
            $this->compileId($member->getName());
        }

        if ($param->isDefaultValueAvailable()) {
            // Deferred for the same reason as the probe fallback: the runtime
            // path evaluates a union default only after every member failed.
            $terminal = CompiledPlan::defaultSpec($declaringClass, $param->getPosition());
        } elseif ($param->allowsNull()) {
            $terminal = CompiledPlan::nullSpec();
        } else {
            $terminal = CompiledPlan::failSpec(UnresolvableParameterException::class, [
                $declaringClass,
                $param->getName(),
                sprintf('It has type "%s" and no member of it could be resolved.', (string) $type),
            ]);
        }

        return CompiledPlan::probeAnySpec($members, $terminal);
    }

    /**
     * Mirror of Container::resolveInjectTarget(): an explicit binding for the
     * parameter type takes precedence over the attribute. The binding lookup
     * is baked safely because bind() flushes every loaded plan.
     */
    private function injectTarget(ReflectionParameter $param, string $typeName): ?string
    {
        if (isset($this->bindings[$typeName])) {
            return null;
        }

        $attributes = $param->getAttributes(Inject::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->concrete;
    }

    /**
     * Record every file this compilation read reflection from: the concrete
     * class, the class that declared the constructor (it may live in a parent
     * file), the file the constructor body sits in (it may live in a trait
     * file) and the traits composed into either class.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function recordManifest(ReflectionClass $reflection): void
    {
        $this->recordFile($reflection->getFileName());
        $this->recordTraits($reflection);

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return;
        }

        $declaring = $constructor->getDeclaringClass();

        $this->recordFile($declaring->getFileName());
        $this->recordFile($constructor->getFileName());
        $this->recordTraits($declaring);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function recordTraits(ReflectionClass $reflection): void
    {
        foreach ($reflection->getTraits() as $trait) {
            $this->recordFile($trait->getFileName());
            $this->recordTraits($trait);
        }
    }

    /**
     * Classes without a file (PHP internals such as ArrayObject) are excluded
     * from the manifest on purpose: they can only change together with the
     * runtime, and replacing the runtime is a redeploy anyway.
     */
    private function recordFile(string|false $file): void
    {
        if ($file === false || isset($this->manifest[$file])) {
            return;
        }

        $stat = @stat($file);

        if ($stat === false) {
            // The path exists (getFileName() returned it) but stat() itself
            // failed — open_basedir, a permission change, a race with
            // deletion. CompiledPlan::staleness() treats a failing stat() on
            // read as "the plan is stale"; recording a sentinel that can
            // never match a real stat() result carries that same reading
            // into the manifest instead of silently dropping the file, which
            // would let its future edits go unnoticed forever.
            $this->manifest[$file] = [-1, -1];

            return;
        }

        // Both values come from the same stat() call; the size partially
        // covers the one-second mtime granularity of some filesystems.
        $this->manifest[$file] = [$stat['mtime'], $stat['size']];
    }
}
