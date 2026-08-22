<?php

declare(strict_types=1);

namespace AutowirePHP\Compiled;

use AutowirePHP\Exception\NotFoundException;
use AutowirePHP\Exception\NotInstantiableException;
use AutowirePHP\Exception\UnresolvableParameterException;
use UnitEnum;

/**
 * Serialisable resolution plan for one root id and everything reachable from it.
 *
 * The cached payload is a plain array of strings, ints and nulls — since
 * default values stopped being baked (see below) not a single object can reach
 * it: any PSR-6/PSR-16 driver can store it, a stale payload from another
 * library version is rejected by the format stamp instead of exploding on
 * unserialization, and no foreign objects are ever unserialized.
 *
 * Node kinds:
 *  - build:   concrete class, shared reason and an ordered list of argument specs;
 *  - fail:    the id is structurally unresolvable; carries a rebuildable
 *             exception description (only string constructor arguments). A
 *             fail node for a missing id (NotFoundException) is compiled but
 *             never actually replayed by the executor — Container::get()
 *             throws its own NotFoundException earlier, from the same check.
 *             The node still gets built so every id has one, and recording
 *             the id is what lets staleness() detect it later appearing.
 *             The fail node for an uninstantiable id (NotInstantiableException)
 *             has no such earlier check and is genuinely reachable.
 *
 * Default values are never baked into a plan. A defaulted parameter compiles
 * into a `default` spec carrying only a reference (concrete class + position —
 * the same base the runtime path reflects from, which for an inherited
 * constructor is not the class that declared it);
 * the executor evaluates it at build time, at the exact point in control flow
 * where the runtime path would — so a new-in-initializer default stays a fresh
 * object per build and a throwing default expression throws at the same moment
 * on both paths, never during compilation.
 *
 * @internal Not part of the public API; Container is the only entry point.
 */
final class CompiledPlan
{
    /**
     * Bumped whenever the payload structure changes; a payload with any other
     * stamp is silently discarded and recompiled.
     * 3: tagged variadic collections (the tagged spec kind).
     */
    public const FORMAT_VERSION = 3;

    public const NODE_BUILD = 'build';
    public const NODE_FAIL = 'fail';

    public const SPEC_SERVICE = 'service';
    public const SPEC_PROBE = 'probe';
    public const SPEC_PROBE_ANY = 'probeAny';
    public const SPEC_DEFAULT = 'default';
    public const SPEC_NULL = 'null';
    public const SPEC_FAIL = 'fail';
    public const SPEC_TAGGED = 'tagged';

    /**
     * The only spec kinds a probe may fall back to after a failed child
     * resolution, and the only kinds a probeAny terminal may hold. Kept as
     * whitelists so a foreign payload with a service spec smuggled into a
     * fallback slot is discarded by validation, not interpreted.
     */
    private const PROBE_FALLBACK_KINDS = [self::SPEC_NULL, self::SPEC_DEFAULT];
    private const PROBE_ANY_TERMINAL_KINDS = [self::SPEC_DEFAULT, self::SPEC_NULL, self::SPEC_FAIL];

    /**
     * The only exceptions a plan may describe. CircularDependencyException is
     * absent by design — cycles are a property of the execution path, not of
     * the plan, and are never recorded. ListenerException is born at runtime
     * only. Both would be unserialisable anyway (array chain, event object).
     */
    private const ALLOWED_EXCEPTIONS = [
        NotFoundException::class,
        NotInstantiableException::class,
        UnresolvableParameterException::class,
    ];

    private const SHARED_REASONS = [null, 'singleton()', '#[Singleton]'];

    /**
     * @param string $configHash hash of the bindings/shared maps the plan was compiled against
     * @param string $root id the compilation started from
     * @param array<string, array<string, mixed>> $nodes flat map id -> node; children reference ids of this map
     * @param array<string, array{int, int}> $manifest file path -> [mtime, size] for every file whose reflection was read
     * @param list<string> $missing type names compiled into a NotFoundException fail node; the plan is stale once one of them exists
     */
    private function __construct(
        public readonly string $configHash,
        public readonly string $root,
        public readonly array $nodes,
        public readonly array $manifest,
        public readonly array $missing,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array{int, int}> $manifest
     * @param list<string> $missing
     */
    public static function create(
        string $configHash,
        string $root,
        array $nodes,
        array $manifest,
        array $missing,
    ): self {
        return new self($configHash, $root, $nodes, $manifest, $missing);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'hash' => $this->configHash,
            'root' => $this->root,
            'nodes' => $this->nodes,
            'manifest' => $this->manifest,
            'missing' => $this->missing,
        ];
    }

    /**
     * Rebuild a plan from a cached payload, or null when the payload is not an
     * array of ours: wrong shape, foreign format stamp, malformed nodes. Null
     * always means "discard and recompile", never "fail".
     */
    public static function fromPayload(mixed $payload): ?self
    {
        if (
            !is_array($payload)
            || ($payload['version'] ?? null) !== self::FORMAT_VERSION
            || !is_string($payload['hash'] ?? null)
            || !is_string($payload['root'] ?? null)
            || !is_array($payload['nodes'] ?? null)
            || !is_array($payload['manifest'] ?? null)
            || !is_array($payload['missing'] ?? null)
        ) {
            return null;
        }

        if (!isset($payload['nodes'][$payload['root']])) {
            return null;
        }

        foreach ($payload['nodes'] as $id => $node) {
            if (!is_string($id) || !self::isValidNode($node)) {
                return null;
            }
        }

        foreach ($payload['manifest'] as $file => $entry) {
            if (
                !is_string($file)
                || !is_array($entry)
                || !is_int($entry[0] ?? null)
                || !is_int($entry[1] ?? null)
            ) {
                return null;
            }
        }

        foreach ($payload['missing'] as $name) {
            if (!is_string($name)) {
                return null;
            }
        }

        return new self(
            $payload['hash'],
            $payload['root'],
            $payload['nodes'],
            $payload['manifest'],
            array_values($payload['missing']),
        );
    }

    /**
     * Why the plan no longer matches the code on disk, or null while it does.
     *
     * Checked once per plan load into the process, not on every get(). Both
     * mtime and size come from the same stat() call; the size partially covers
     * the one-second mtime granularity of some filesystems. Classes without a
     * file (PHP internals) were never recorded, so they are naturally excluded:
     * they only change together with the runtime itself.
     *
     * A failing stat() here always counts as staleness, on both sides of the
     * manifest: a file PlanCompiler could not stat() at record time is stored
     * as the [-1, -1] sentinel (see PlanCompiler::recordFile()), which no real
     * stat() result can ever match, so it is caught by the mismatch branch
     * below the same way a file that has since become unreadable is caught by
     * the stat() === false branch.
     */
    public function staleness(): ?string
    {
        foreach ($this->manifest as $file => [$mtime, $size]) {
            $stat = @stat($file);

            if ($stat === false || $stat['mtime'] !== $mtime || $stat['size'] !== $size) {
                return sprintf('file "%s" has changed since compilation', $file);
            }
        }

        // Same predicate the container uses for existence, so a type compiled
        // as missing invalidates the plan the moment its file appears. One
        // autoload miss per plan load is the accepted price.
        foreach ($this->missing as $name) {
            if (class_exists($name) || interface_exists($name)) {
                return sprintf('previously missing type "%s" now exists', $name);
            }
        }

        return null;
    }

    /**
     * Whether an evaluated default may be memoised by the executor: null,
     * scalars, enum cases and arrays thereof. Such results are referentially
     * transparent within a process (classes and constants are immutable once
     * loaded, enum cases are singletons), so re-evaluating the constant
     * expression could not observably differ. Everything else — objects from
     * new-in-initializers first of all — must be re-evaluated on every build,
     * exactly as the runtime path does, or a transient object would silently
     * become a shared one.
     */
    public static function isPlanValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof UnitEnum) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isPlanValue($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildNode(string $concrete, ?string $sharedReason, array $args): array
    {
        return [
            'kind' => self::NODE_BUILD,
            'concrete' => $concrete,
            'shared' => $sharedReason,
            'args' => $args,
        ];
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    public static function failNode(string $exceptionClass, array $args): array
    {
        return ['kind' => self::NODE_FAIL, 'exception' => ['class' => $exceptionClass, 'args' => $args]];
    }

    /**
     * @param array{parameter: string, class: string, target: string}|null $inject
     * @return array<string, mixed>
     */
    public static function serviceSpec(string $id, ?array $inject): array
    {
        return ['kind' => self::SPEC_SERVICE, 'id' => $id, 'inject' => $inject];
    }

    /**
     * @param array<string, mixed> $fallback a null or default spec applied after the probe failed
     * @return array<string, mixed>
     */
    public static function probeSpec(string $id, array $fallback): array
    {
        return ['kind' => self::SPEC_PROBE, 'id' => $id, 'fallback' => $fallback];
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $terminal a default, null or fail spec applied after every member failed
     * @return array<string, mixed>
     */
    public static function probeAnySpec(array $ids, array $terminal): array
    {
        return ['kind' => self::SPEC_PROBE_ANY, 'ids' => $ids, 'terminal' => $terminal];
    }

    /**
     * Reference to a parameter default value, deliberately without the value:
     * the executor re-creates the ReflectionParameter from the concrete class
     * and the position — the very same base the runtime path reflects from.
     *
     * @return array<string, mixed>
     */
    public static function defaultSpec(string $class, int $position): array
    {
        return ['kind' => self::SPEC_DEFAULT, 'class' => $class, 'position' => $position];
    }

    /**
     * @return array<string, mixed>
     */
    public static function nullSpec(): array
    {
        return ['kind' => self::SPEC_NULL];
    }

    /**
     * A #[Tagged] variadic tail: the one spec kind that contributes several
     * positional arguments — one resolved instance per member id, spliced
     * into the end of the argument list by the executor. It is neither a
     * probe fallback nor a probeAny terminal (see the whitelists above): it
     * only ever appears as the last entry of a build node's argument list.
     *
     * @param list<string> $ids tag members, in registration order, deduplicated
     * @param array{parameter: string, class: string, tag: string} $tagged
     *        data for replaying the tagged-collection log record, which on the
     *        runtime path lives inside resolveTaggedVariadic()
     * @return array<string, mixed>
     */
    public static function taggedSpec(array $ids, array $tagged): array
    {
        return ['kind' => self::SPEC_TAGGED, 'ids' => $ids, 'tagged' => $tagged];
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    public static function failSpec(string $exceptionClass, array $args): array
    {
        return ['kind' => self::SPEC_FAIL, 'exception' => ['class' => $exceptionClass, 'args' => $args]];
    }

    private static function isValidNode(mixed $node): bool
    {
        if (!is_array($node)) {
            return false;
        }

        return match ($node['kind'] ?? null) {
            self::NODE_FAIL => self::isValidException($node['exception'] ?? null),
            self::NODE_BUILD => is_string($node['concrete'] ?? null)
                && array_key_exists('shared', $node)
                && in_array($node['shared'], self::SHARED_REASONS, true)
                && is_array($node['args'] ?? null)
                && self::areValidSpecs($node['args']),
            default => false,
        };
    }

    /**
     * @param array<mixed> $specs
     */
    private static function areValidSpecs(array $specs): bool
    {
        foreach ($specs as $spec) {
            if (!self::isValidSpec($spec)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidSpec(mixed $spec): bool
    {
        if (!is_array($spec)) {
            return false;
        }

        return match ($spec['kind'] ?? null) {
            self::SPEC_NULL => true,
            self::SPEC_DEFAULT => is_string($spec['class'] ?? null)
                && is_int($spec['position'] ?? null)
                && $spec['position'] >= 0,
            self::SPEC_FAIL => self::isValidException($spec['exception'] ?? null),
            self::SPEC_SERVICE => is_string($spec['id'] ?? null)
                && array_key_exists('inject', $spec)
                && self::isValidInject($spec['inject']),
            self::SPEC_PROBE => is_string($spec['id'] ?? null)
                && is_array($spec['fallback'] ?? null)
                && self::isValidSpec($spec['fallback'])
                && in_array($spec['fallback']['kind'], self::PROBE_FALLBACK_KINDS, true),
            self::SPEC_PROBE_ANY => is_array($spec['ids'] ?? null)
                && $spec['ids'] === array_filter($spec['ids'], 'is_string')
                && is_array($spec['terminal'] ?? null)
                && self::isValidSpec($spec['terminal'])
                && in_array($spec['terminal']['kind'], self::PROBE_ANY_TERMINAL_KINDS, true),
            self::SPEC_TAGGED => is_array($spec['ids'] ?? null)
                && $spec['ids'] === array_filter($spec['ids'], 'is_string')
                && is_array($spec['tagged'] ?? null)
                && is_string($spec['tagged']['parameter'] ?? null)
                && is_string($spec['tagged']['class'] ?? null)
                && is_string($spec['tagged']['tag'] ?? null),
            default => false,
        };
    }

    private static function isValidInject(mixed $inject): bool
    {
        if ($inject === null) {
            return true;
        }

        return is_array($inject)
            && is_string($inject['parameter'] ?? null)
            && is_string($inject['class'] ?? null)
            && is_string($inject['target'] ?? null);
    }

    private static function isValidException(mixed $exception): bool
    {
        if (
            !is_array($exception)
            || !in_array($exception['class'] ?? null, self::ALLOWED_EXCEPTIONS, true)
            || !is_array($exception['args'] ?? null)
        ) {
            return false;
        }

        foreach ($exception['args'] as $argument) {
            if (!is_string($argument)) {
                return false;
            }
        }

        return true;
    }
}
