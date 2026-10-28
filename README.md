# SuperKernel AOP

Aspect declarations, method weaving, join points, and interceptor dispatch with
startup-activated class loading. Mimic supplies file proxies and Stream fallback.

## Package

- Composer package: `super-kernel/aop`.
- Source: `src/`.
- Production namespace: `SuperKernel\Aop\`.
- Test namespace: `SuperKernel\Aop\Tests\`.
- Runtime: PHP 8.4.27 or later in the 8.4.x series with Reflection, PHP-Parser `^5.9.0`, and the Annotator, Attribute, Contract, Event, and Mimic components.
- The application supplies `SuperKernel\Contract\AutoLoaderInterface`; the Autoloader component provides its default binding.

## Aspect attribute

`SuperKernel\Aop\Attribute\Aspect` marks a class that handles aspect behavior.
The native Attribute is placed on that handler class; its arguments describe
the classes, methods, and property hooks that the handler targets.

It is a final, readonly, non-repeatable native PHP Attribute targeting classes only.
One declaration can contain multiple pointcuts in each of three public readonly arrays:

- `classes`: a list of target class names.
- `methods`: target class names mapped to non-empty lists of method names.
- `propertyHooks`: target class names mapped to property names, each containing
  a non-empty list of `get` and/or `set` hook names. Property names omit `$`.

```php
use SuperKernel\Aop\Attribute\Aspect;
use SuperKernel\Aop\Contract\AspectInterface;
use SuperKernel\Aop\Contract\ProceedingJoinPointInterface;

#[Aspect(
    classes: [
        App\Service\OrderService::class,
        App\Service\InvoiceService::class,
    ],
    methods: [
        App\Service\UserService::class => [
            'create',
            'update',
        ],
    ],
)]
final class ExampleAspect implements AspectInterface
{
    public function process(ProceedingJoinPointInterface $point): mixed
    {
        return $point->proceed();
    }
}
```

All three arguments default to empty arrays for manual self-weaving. Names, ordering, and duplicate list
entries are preserved. Class names may have a leading namespace separator;
wildcards and selector strings such as `Class::method` are not accepted in these
structured declarations.

Construction checks identifier syntax, list shapes, and the supported hook names,
throwing `InvalidArgumentException` for malformed declarations. It does not load
target classes or check whether their members exist, so metadata collection can
leave targets unloaded for later weaving.

Handlers with class or method pointcuts implement `Contract\AspectInterface`.
The container supplies their dependencies, and `process(ProceedingJoinPointInterface): mixed`
provides around advice through the `Contract\ProceedingJoinPointInterface` contract.
Call `proceed()` to invoke the original method, preserving
its result or deliberately supplying a replacement. Use `try/finally` for after
logic that must also run when the original method throws.

The AOP autoloader resolves matching handlers and registers their advice when a
declared target class is loaded. Parameter-free declarations select self-weaving: their declaring class is selected for weaving and callbacks may be
registered manually without implementing `AspectInterface`.

Property hook metadata remains supported by the Attribute, but hook bodies are not
yet woven. Loading a declared property hook target raises `LogicException` before
the target is declared. Unloaded targets do not prevent startup or unrelated class
loads.

## Aspect collector

`SuperKernel\Aop\Collector\AspectCollector` is a final, readonly service. It receives
the registered `AnnotationCollectorInterface` through constructor injection and
builds its declaration map from collected metadata.

`getClasses()` returns original `Aspect` instances keyed by declaring class name,
preserving discovery order and the shared collector's package ownership.

Collection does not reflect or autoload annotated target classes, instantiate
their annotations again, or run another scan. The query returns an array by value.
Resolve this service through DI when aspect declarations are needed.

`isTargetClass()` checks the collected class, method, and property hook targets
without loading them. It ignores class-name case and a leading namespace separator.
An explicit handler is not itself a target unless one of its pointcuts selects it.

## Application startup

`SuperKernel\Aop\Listener\BeforeApplicationStartListener` implements the shared
`ListenerInterface`, subscribes exclusively to
`SuperKernel\Event\BeforeApplicationStart`, and declares
`#[Listener(PHP_INT_MAX)]`.

The existing event dispatcher discovers the listener and places it in the highest
numeric priority group, before lower-priority listeners. Equal priorities retain
discovery order, including other listeners using `PHP_INT_MAX`.

The listener receives `AspectCollector` and the shared PSR-11 container. During
`process()`, an empty aspect map returns immediately. The AOP loader and its
runtime dependencies are resolved only after this check, so an empty collection
does not activate the shared loader or initialize Mimic.

For a non-empty collection, the listener resolves `SuperKernel\Aop\AutoLoader`
and registers it at the front of the SPL stack. Repeated startup dispatch reuses
the container's instance and does not add duplicate callbacks. Resolving the
default shared loader first performs the Autoloader component's documented SPL
takeover; AOP then prepends its own callback and preserves the resulting stack.

The listener does not resolve handlers, validate their contracts, or register
interceptors. Repeated startup only ensures loader registration and leaves
existing advice callbacks unchanged.

## Aspect autoloading

`AutoLoader` receives `AspectCollector`, `AutoLoaderInterface`, `MimicFactory`,
the shared `ContainerInterface`, and `Runtime\SourceMetadata` through constructor
injection. Direct callers supply the container as the fourth argument and source
metadata as the fifth. For each SPL class request it:

1. Checks the collected target map without reflecting or loading the target.
2. Returns for an already loaded symbol or a class absent from the declared
   pointcuts and self-weaving markers.
3. Reads the selected class's pending path from `AutoLoaderInterface::getClassMap()`.
4. Returns if the path is absent.
5. Asks Mimic to prepare a cached proxy or a fallback `mimic://` URI.
6. Resolves the original file through `SourceMetadata` and the injected package
   manager, binding file and directory constants before proxy evaluation.
7. Finds declarations that select this target, rejects its unsupported property
   hook pointcuts, and resolves matching class or method handlers through the
   injected container. Each handler must implement `AspectInterface`.
8. Executes the prepared source and verifies that the requested symbol was declared.
9. Registers matching class and method selectors with `Runtime\Dispatcher` using
   each handler's `process()` callable, before returning to the caller.

Unrelated declarations do not resolve their handlers or register their selectors.
If several handlers select the same case-insensitive selector, the last one in
discovery order replaces earlier registrations. Repeated requests for an already
loaded target do not replace its callbacks.

Handler resolution happens before declaring the target, so a resolution or
validation failure leaves it unloaded and releases the loader's recursion guard
for a retry. A handler constructor must not request the target currently being
loaded, directly or through another dependency. Such a dependency is circular.
Advice is published only after the prepared source successfully declares the
requested target.

AOP owns the actual replacement load, including recursion checks and the SPL
callback. Mimic prepares source using the AOP mutator supplied through its generic contract.

`Mutation\Mutator` declares `#[Provider(MutatorInterface::class)]`, binding
`SuperKernel\Mimic\Contract\MutatorInterface` directly to its shared instance.
The container constructs `MimicFactory`
from injected `FileDriver` and `StreamDriver` services. The file driver also receives
`PackageManagerInterface` for class names. It writes
`<VendorDirInterface>/.cache/mimic/Src.Service.TestService.tpl`,
replacing namespace separators with dots and using no hash subdirectory.
A first-line SHA-256 fingerprint validates the original path, source content,
and transformation key, so changes regenerate the same class-named proxy.
Mimic's `Source\ProxyFile` owns proxy paths and header reads and writes. AOP loads
the returned address directly, without reconstructing filenames or suffixes.
Publication uses temporary files and atomic rename without `.lock` sidecars.
Cache creation or replacement failures fall back to Stream. Mimic registers no
SPL callback, and construction initializes neither cache files nor the protocol.

## Interceptors and join points

AOP's `Mutation\Mutator` and `Mutation\NodeVisitor` use PHP-Parser to weave
`Runtime\Dispatcher` and `Runtime\ProceedingJoinPoint` into concrete methods.
Register callbacks through the AOP runtime before invoking selected methods:

```php
use App\Service;
use SuperKernel\Aop\Runtime\Dispatcher;
use SuperKernel\Aop\Runtime\ProceedingJoinPoint;

Dispatcher::intercept([
    Service::class,
    'calculate',
], static function (ProceedingJoinPoint $point): mixed {
    return $point->proceed();
});
```

`Dispatcher::intercept()` accepts a class name, `Class::method`, or `[Class, method]`.
Selectors are case-insensitive. Method selectors override class selectors, and
re-registering a selector replaces its callback. Matching uses the runtime class,
including the called class for static methods, without ancestor or interface
expansion. Without a callback, dispatch proceeds into the original body.

`Runtime\ProceedingJoinPoint` implements `Contract\ProceedingJoinPointInterface`.
Aspect implementations accept the interface, allowing advice to work with other
implementations of the same call contract. Existing `AspectInterface` implementers
must update their `process()` parameter from the concrete class to the interface.

`ProceedingJoinPointInterface::getInstance()` returns an object for an instance method or
a class-string for a static method. `getArguments()`, `getClassReflection()`,
`getMethodReflection()`, and `getShortMethodName()` describe the original call.
`proceed()` invokes its body. A callback can bypass that body by returning without
proceeding; the enclosing method still enforces its return type. Constructors,
destructors, and `void` methods discard callback results. PHP performs constructor
promotion before interception. A `never` interceptor must not return normally.

Registrations and `Runtime\Tracker` are process-global. Loading a selected target
installs its advice for that process; forked workers inherit callbacks already
registered in their parent. Advice discovery supports class and method pointcuts
through `AspectInterface`. There is no separate Pointcut Attribute.

## Supported source and diagnostics

- Use trusted, declaration-oriented files. All concrete methods in a selected
  file are woven; abstract methods remain unchanged. Final modifiers and private
  visibility are preserved. AOP's `Mutation\Modifier` is an optional PHP-Parser
  visitor that relaxes final modifiers and private visibility; the default
  mutator does not install it.
- Original parameter values and promoted default objects are preserved.
  `__FILE__` and `__DIR__` refer to the original local source, including inside
  class constants, property initializers, and parameter defaults.
  Generated code references namespaced source constants instead of embedding
  absolute paths. `Runtime\SourceMetadata` receives `PackageManagerInterface` and
  binds these constants from the original class map before loading.
  Direct Mimic integrations using the AOP mutator must also call
  `SourceMetadata::register($class)` on their injected instance before executing
  the prepared source. Constant bindings remain process-local.
- Reference returns, reference or variadic parameters, hooked promoted
  parameters, generators, static locals, and `__FUNCTION__`, `__METHOD__`, or
  `__LINE__` in method bodies are rejected before loading. This conservative
  check includes nested closures.
- Exceptions from transformed bodies retain their object and receive their
  original file and last entered top-level statement line. Nested statements
  may report the enclosing statement. Callback and external failures retain
  their own locations; nested dispatch restores the caller's diagnostic state.
- Stack frames and reflection line numbers still describe generated code.
  Diagnostic lines are not isolated across suspended fibers or Swoole coroutines.
  Normal initialization does not replace the global exception handler.
- Parser and transformation failures propagate without falling through to
  untransformed loading. Unmarked classes and missing map entries continue
  through other SPL loaders. Already loaded symbols cannot be rewritten.

## Ownership and cache identity

AOP owns `Runtime\Dispatcher`, `ProceedingJoinPoint`, `Tracker`, and the `Mutation` classes.
`Runtime\Dispatcher::intercept()` registers callbacks and returns void. Mimic supplies generic preparation drivers
and `MutatorInterface`; dependencies run from AOP to Mimic. PHP-Parser is an AOP production dependency.

`Mutator::getCacheKey()` identifies generated-code semantics. Change it with weaving semantics to invalidate file and
Stream identities. Historical files remain untouched. Rebuild annotation and Phar snapshots after declaration changes,
and restart workers to load rebuilt code.

The migrated source derives from the authorized Mimic port; provenance and authorization
are retained in [NOTICE](NOTICE).

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```

The component is licensed under the [MIT License](LICENSE), as designated by wheakerd.
