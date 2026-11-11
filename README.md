<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel AOP

`super-kernel/aop` runs code around the methods of your classes: an aspect declares which classes and methods it targets, and the package weaves those classes when they are loaded, so every call passes through the aspect.

## Installation

```bash
composer require super-kernel/aop
```

The package installs `super-kernel/mimic` and `nikic/php-parser`. It also needs a `SuperKernel\Contract\AutoLoaderInterface`, which `super-kernel/autoloader` provides; the package does not require it, so install it too, or `skernel build` fails as soon as one aspect exists. Weaving starts when `super-kernel/framework` starts the application, and the aspects are found in the metadata that `skernel build` generates. `super-kernel/skeleton` installs all of them.

## What it provides

- Aspects declared with `#[Aspect]`, targeting whole classes or named methods.
- Around advice through `AspectInterface::process()`, with the call's arguments, reflection, and `proceed()`.
- Weaving of a target class when it is first loaded after the application starts, with no change to your source files.
- Proxies prepared by `skernel build`, so a packaged application loads them instead of weaving.
- Callbacks registered by hand with `Dispatcher::intercept()` for classes that weave themselves.

It does not:

- weave property hooks; declaring one makes its target fail to load;
- weave methods that return by reference, take reference or variadic parameters, are generators, or use static locals or `__FUNCTION__`, `__METHOD__`, or `__LINE__`;
- match by wildcard, parent class, or interface;
- weave a class that is already loaded;
- isolate callbacks per request, coroutine, or fiber; they are process-wide.

## Quick start

Declare a service. Save it as `src/Billing/PriceCalculator.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Billing;

final class PriceCalculator
{
    public function total(int $net, int $taxPercent): int
    {
        return $net + intdiv($net * $taxPercent, 100);
    }
}
```

Declare an aspect of its `total()` method. Save it as `src/Aspect/LogCalculation.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Aspect;

use Src\Billing\PriceCalculator;
use SuperKernel\Aop\Attribute\Aspect;
use SuperKernel\Aop\Contract\AspectInterface;
use SuperKernel\Aop\Contract\ProceedingJoinPointInterface;

#[Aspect(methods: [PriceCalculator::class => ['total']])]
final readonly class LogCalculation implements AspectInterface
{
    public function process(ProceedingJoinPointInterface $point): mixed
    {
        $arguments = implode(', ', $point->getArguments());
        $result = $point->proceed();

        echo "{$point->getShortMethodName()}({$arguments}) = {$result}", PHP_EOL;

        return $result;
    }
}
```

Use the service in a command. Save it as `src/Command/TotalCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Command;

use Src\Billing\PriceCalculator;
use SuperKernel\Command\Attribute\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Command(name: 'invoice:total', description: 'Print an invoice total.')]
final class TotalCommand extends SymfonyCommand
{
    public function __construct(private readonly PriceCalculator $calculator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Total: {$this->calculator->total(1000, 19)}");

        return self::SUCCESS;
    }
}
```

Build the project and run the command:

```bash
skernel build
php bin/bootstrap.php invoice:total
```

It prints:

```text
total(1000, 19) = 1190
Total: 1190
```

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\Aop\Attribute\Aspect` | Attribute | Declares an aspect and its targets: `classes`, `methods`, and `propertyHooks`; without arguments, the annotated class weaves itself. |
| `SuperKernel\Aop\Contract\AspectInterface` | Interface | Implemented by an aspect with targets; `process()` runs around each selected call. |
| `SuperKernel\Aop\Contract\ProceedingJoinPointInterface` | Interface | Describes the intercepted call; `proceed()` runs the original method. |
| `SuperKernel\Aop\Runtime\Dispatcher` | Class | `intercept()` registers a callback for a class or method of a woven class. |
| `SuperKernel\Aop\Runtime\ProceedingJoinPoint` | Class | The join point passed to callbacks registered with `Dispatcher::intercept()`. |
| `SuperKernel\Aop\Collector\AspectCollector` | Class | Provided by the container; `getClasses()` returns the collected aspects and `isTargetClass()` tells whether a class is woven. |
| `SuperKernel\Contract\AutoLoaderInterface` | Interface | Supplies the class map from which targets are woven; `super-kernel/autoloader` provides it. |
| `SuperKernel\Aop\Mutation\Mutator` | Class | The source transformation used with `super-kernel/mimic`; `getCacheKey()` identifies its output. |
| `SuperKernel\Aop\Mutation\Modifier` | Class | An optional PHP-Parser visitor that removes `final` and makes `private` methods and properties `protected`; not installed by default. |
| `SuperKernel\Aop\Runtime\SourceMetadata` | Class | Binds the original file of a class before its woven source runs, for direct Mimic integrations. |

## Behavior to know

- Weaving starts when the container first creates `ApplicationInterface`. A target class loaded before that, for example by a script that never starts the application, is not woven, and its aspects never run.
- An aspect's handler is created through the container when its target is loaded, and must implement `AspectInterface`; otherwise loading the target raises `InvalidArgumentException` with `Aspect handler <class> must implement SuperKernel\Aop\Contract\AspectInterface.`
- A method the package cannot weave raises `LogicException` when its class is prepared, such as `AOP cannot preserve generators, static locals, or method/line magic constants.`, which fails `skernel build`. A target with a property hook declaration raises `AOP property hook interception is not implemented.` when it is loaded.
- An `#[Aspect]` with malformed arguments raises `InvalidArgumentException` during `skernel build`, which skips the class and reports it, so the aspect does not apply.
- `__FILE__` and `__DIR__` keep pointing to your source; reflection and stack frames point to the generated proxy.
- Run `skernel build` again after adding, removing, or changing an aspect or a target, or start with `skernel run`, which builds first; restart long-running workers to load the new code.

## Documentation

The developer documentation describes targets, advice, self-weaving, when and how classes are woven, build-time proxies, supported source, every error message, and what not to do: [SuperKernel AOP](https://github.com/super-kernel/.github/blob/main/docs/components/aop.md).

## License

Released under the MIT License. See [LICENSE](LICENSE). The provenance of the weaving source and its authorization are recorded in [NOTICE](NOTICE).
