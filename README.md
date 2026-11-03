<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel AOP

`super-kernel/aop` provides aspect declarations, method weaving, join points, and interceptor dispatch with startup-activated class loading. `super-kernel/mimic` supplies the cached file proxies and Stream fallback. The application's `SuperKernel\Contract\AutoLoaderInterface` binding, by default from `super-kernel/autoloader`, supplies the class map.

## Aspect attribute

`SuperKernel\Aop\Attribute\Aspect` marks a class that handles aspect behavior. The native Attribute is placed on that handler class; its arguments describe the classes, methods, and property hooks that the handler targets.

It is a final, readonly, non-repeatable native PHP Attribute targeting classes only. One declaration can contain multiple pointcuts in each of three public readonly arrays:

- `classes`: a list of target class names.
- `methods`: target class names mapped to non-empty lists of method names.
- `propertyHooks`: target class names mapped to property names, each containing a non-empty list of `get` and/or `set` hook names. Property names omit `$`.

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

All three arguments default to empty arrays for manual self-weaving. Names, ordering, and duplicate list entries are preserved. Class names may have a leading namespace separator; wildcards and selector strings such as `Class::method` are not accepted in these structured declarations.

Construction checks identifier syntax, list shapes, and the supported hook names, throwing `InvalidArgumentException` for malformed declarations. It does not load target classes or check whether their members exist, so metadata collection can leave targets unloaded for later weaving.

Handlers with class or method pointcuts implement `Contract\AspectInterface`. The container supplies their dependencies, and `process(ProceedingJoinPointInterface): mixed` provides around advice. Call `proceed()` to invoke the original method, preserving its result or deliberately supplying a replacement. Use `try/finally` for after logic that must also run when the original method throws.

Matching handlers are resolved and their advice registered when a declared target class is loaded. Parameter-free declarations select self-weaving: their declaring class is woven and callbacks may be registered manually without implementing `AspectInterface`.

Property hook pointcuts are accepted by the Attribute, but hook bodies are not woven. Loading a declared property hook target raises `LogicException` before the target is declared. Unloaded targets do not prevent startup or unrelated class loads.

## Aspect collector

`SuperKernel\Aop\Collector\AspectCollector` is a final, readonly service; resolve it through DI when aspect declarations are needed. `getClasses()` returns the original `Aspect` instances keyed by declaring class name, preserving discovery order and package ownership. It returns an array by value and never reflects or autoloads target classes or runs another scan.

`isTargetClass()` checks the collected class, method, and property hook targets without loading them. It ignores class-name case and a leading namespace separator. A handler is not itself a target unless one of its pointcuts selects it.

## Application startup

During `SuperKernel\Event\BeforeApplicationStart`, at `PHP_INT_MAX` priority before lower-priority listeners, AOP registers its class loader at the front of the SPL stack when at least one Aspect is collected. With no Aspects, nothing is activated and Mimic is not initialized. Resolving the default class loader first performs the Autoloader component's SPL takeover; AOP then prepends its own callback. Repeated startup dispatch adds no duplicate callbacks and leaves registered advice unchanged.

## Application build

During `SuperKernel\Event\ApplicationBuild`, which SKERNEL's `build` command publishes in the project's own process, AOP prepares the proxy of every collected target that has a pending path in the class map, when at least one Aspect is collected. Targets are not loaded, their handlers are not resolved, and no class loader is registered. A packaged application, such as a Phar built from that project, loads the prepared proxies instead of weaving at runtime; a target without a prepared proxy is woven through Mimic's Stream driver. Parse and transformation failures fail the build.

## Aspect autoloading

For each class request, the AOP loader weaves only declared targets and self-weaving classes that are not loaded yet and have a pending path in the class map; other requests fall through to the remaining SPL loaders. A selected class is prepared through Mimic, its matching handlers are resolved from the container, the prepared source is executed, and the matching class and method selectors are registered before the request returns.

- Each resolved handler must implement `AspectInterface`. Unrelated declarations do not resolve their handlers.
- If several handlers select the same case-insensitive selector, the last one in discovery order replaces earlier registrations. Repeated requests for an already loaded target do not replace its callbacks.
- Handlers are resolved before the target is declared, so a resolution or validation failure leaves it unloaded and can be retried. A handler constructor must not request the target currently being loaded, directly or through another dependency; such a dependency is circular.
- Advice is published only after the prepared source successfully declares the requested target.

Mimic writes proxies to `<VendorDirInterface>/.cache/mimic/<class-name>.tpl` and falls back to `mimic://` streams; see the [Mimic README](https://github.com/super-kernel/mimic/blob/main/README.md) for cache behavior.

## Interceptors and join points

The weaver rewrites concrete methods to dispatch through `Runtime\Dispatcher` with a `Runtime\ProceedingJoinPoint`. Register callbacks before invoking selected methods:

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

`Dispatcher::intercept()` accepts a class name, `Class::method`, or `[Class, method]`, and returns `void`. Selectors are case-insensitive. Method selectors override class selectors, and re-registering a selector replaces its callback. Matching uses the runtime class, including the called class for static methods, without ancestor or interface expansion. Without a callback, dispatch proceeds into the original body.

`Runtime\ProceedingJoinPoint` implements `Contract\ProceedingJoinPointInterface`; Aspect implementations accept the interface. `getInstance()` returns an object for an instance method or a class-string for a static method. `getArguments()`, `getClassReflection()`, `getMethodReflection()`, and `getShortMethodName()` describe the original call. `proceed()` invokes its body. A callback can bypass that body by returning without proceeding; the enclosing method still enforces its return type. Constructors, destructors, and `void` methods discard callback results. PHP performs constructor promotion before interception. A `never` interceptor must not return normally.

Registrations and `Runtime\Tracker` are process-global. Loading a selected target installs its advice for that process; forked workers inherit callbacks already registered in their parent. Advice discovery supports class and method pointcuts through `AspectInterface`. There is no separate Pointcut Attribute.

## Supported source and diagnostics

- Use trusted, declaration-oriented files. All concrete methods in a selected file are woven; abstract methods remain unchanged. Final modifiers and private visibility are preserved. `Mutation\Modifier` is an optional PHP-Parser visitor that relaxes final modifiers and private visibility; the default mutator does not install it.
- Original parameter values and promoted default objects are preserved. `__FILE__` and `__DIR__` refer to the original source, local or inside a Phar archive, including inside class constants, property initializers, and parameter defaults. Generated code references namespaced source constants instead of embedding absolute paths, and `Runtime\SourceMetadata` binds those constants from the original class map before loading. The constants are named after the source's package name and package-relative path, so code woven before the project moves or is packaged into a Phar archive binds the source that is actually running. Direct Mimic integrations using the AOP mutator must call `SourceMetadata::register($class)` on their injected instance before executing the prepared source. Constant bindings remain process-local.
- Reference returns, reference or variadic parameters, hooked promoted parameters, generators, static locals, and `__FUNCTION__`, `__METHOD__`, or `__LINE__` in method bodies are rejected before loading. This conservative check includes nested closures.
- Exceptions from transformed bodies retain their object and receive their original file and last entered top-level statement line. Nested statements may report the enclosing statement. Callback and external failures retain their own locations; nested dispatch restores the caller's diagnostic state.
- Stack frames and reflection line numbers still describe generated code. Diagnostic lines are not isolated across suspended fibers or Swoole coroutines. Normal initialization does not replace the global exception handler.
- Parser and transformation failures propagate without falling through to untransformed loading. Unmarked classes and missing map entries continue through other SPL loaders. Already loaded symbols cannot be rewritten.

## Cache identity

`Mutation\Mutator::getCacheKey()` identifies generated-code semantics, so a release that changes weaving semantics regenerates file and Stream proxies. Rebuild annotation and Phar snapshots after declaration changes, and restart workers to load rebuilt code.

## License

The component is licensed under the [MIT License](LICENSE), as designated by wheakerd. Its source derives from the authorized Mimic port; provenance and authorization are retained in [NOTICE](NOTICE).
