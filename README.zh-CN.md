<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel AOP

`super-kernel/aop` 在你的类的方法周围运行代码：切面声明它针对哪些类和方法，本包在这些类被加载时对它们进行织入，使每次调用都经过该切面。

## 安装

```bash
composer require super-kernel/aop
```

本包会安装 `super-kernel/mimic` 和 `nikic/php-parser`。只要存在一个切面，它还需要一个 `SuperKernel\Contract\AutoLoaderInterface` 的提供者，例如 `super-kernel/autoloader`；本包建议（suggest）但并不依赖该包，请用 `composer require super-kernel/autoloader` 安装它。缺少提供者时，`skernel build` 和应用启动都会失败，消息为 `Unable to resolve method parameter SuperKernel\Aop\AutoLoader::__construct($autoLoader) of type SuperKernel\Contract\AutoLoaderInterface.`织入在 `super-kernel/framework` 启动应用时开始，切面从 `skernel build` 生成的元数据中发现。`super-kernel/skeleton` 会安装所有这些包。

## 提供的能力

- 用 `#[Aspect]` 声明、针对整个类或指定方法的切面。
- 通过 `AspectInterface::process()` 提供的环绕通知，带有调用的参数、反射和 `proceed()`。
- 在应用启动之后、目标类首次被加载时对其进行织入，不修改你的源文件。
- 由 `skernel build` 预先准备的代理，使打包后的应用直接加载它们而无需织入。
- 为自我织入的类通过 `Dispatcher::intercept()` 手动注册的回调。

它不会：

- 织入属性钩子；声明属性钩子会使其目标无法加载；
- 织入按引用返回、接受引用或可变参数、属于生成器，或者使用静态局部变量或 `__FUNCTION__`、`__METHOD__`、`__LINE__` 的方法；
- 按通配符、父类或接口匹配；
- 织入已经加载的类；
- 按请求、协程或纤程隔离回调；回调在整个进程范围内生效。

## 快速开始

声明一个服务。将它保存为 `src/Billing/PriceCalculator.php`：

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

为它的 `total()` 方法声明一个切面。将它保存为 `src/Aspect/LogCalculation.php`：

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

在命令中使用该服务。将它保存为 `src/Command/TotalCommand.php`：

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

构建项目并运行命令：

```bash
skernel build
php bin/bootstrap.php invoice:total
```

它会打印：

```text
total(1000, 19) = 1190
Total: 1190
```

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Aop\Attribute\Aspect` | 注解 | 声明切面及其目标：`classes`、`methods` 和 `propertyHooks`；没有参数时，被注解的类对自身进行织入。 |
| `SuperKernel\Aop\Contract\AspectInterface` | 接口 | 由带有目标的切面实现；`process()` 在每次选中的调用周围运行。 |
| `SuperKernel\Aop\Contract\ProceedingJoinPointInterface` | 接口 | 描述被拦截的调用；`proceed()` 运行原始方法。 |
| `SuperKernel\Aop\Runtime\Dispatcher` | 类 | `intercept()` 为已织入类的某个类或方法注册回调。 |
| `SuperKernel\Aop\Runtime\ProceedingJoinPoint` | 类 | 传给用 `Dispatcher::intercept()` 注册的回调的连接点。 |
| `SuperKernel\Aop\Collector\AspectCollector` | 类 | 由容器提供；`getClasses()` 返回收集到的切面，`isTargetClass()` 判断某个类是否被织入。 |
| `SuperKernel\Contract\AutoLoaderInterface` | 接口 | 提供织入目标所依据的类映射；由 `super-kernel/autoloader` 提供。 |
| `SuperKernel\Aop\Mutation\Mutator` | 类 | 与 `super-kernel/mimic` 一起使用的源码转换；`getCacheKey()` 标识它的输出。 |
| `SuperKernel\Aop\Mutation\Modifier` | 类 | 可选的 PHP-Parser 访问器，移除 `final` 并把 `private` 方法和属性改为 `protected`；默认不安装。 |
| `SuperKernel\Aop\Runtime\SourceMetadata` | 类 | 在某个类的织入源码运行之前绑定其原始文件，供直接集成 Mimic 时使用。 |

## 需要了解的行为

- 织入在容器第一次创建 `ApplicationInterface` 时开始。在此之前加载的目标类（例如被从不启动应用的脚本加载）不会被织入，它的切面永远不会运行。
- 切面的处理器在其目标加载时通过容器创建，并且必须实现 `AspectInterface`；否则加载目标会抛出 `InvalidArgumentException`，消息为 `Aspect handler <class> must implement SuperKernel\Aop\Contract\AspectInterface.`
- 本包无法织入的方法会在准备其类时抛出 `LogicException`，例如 `AOP cannot preserve generators, static locals, or method/line magic constants.`，这会使 `skernel build` 失败。带有属性钩子声明的目标在加载时抛出 `AOP property hook interception is not implemented.`
- 参数格式错误的 `#[Aspect]` 会在 `skernel build` 期间抛出 `InvalidArgumentException`，构建会跳过该类并报告它，因此该切面不会生效。
- `__FILE__` 和 `__DIR__` 仍然指向你的源码；反射和栈帧指向生成的代理。
- 添加、删除或修改切面或目标之后，请重新运行 `skernel build`，或者用会先构建的 `skernel run` 启动；请重启长时间运行的工作进程以加载新代码。

## 文档

开发者文档介绍了目标、通知、自我织入、类何时以及如何被织入、构建时的代理、支持的源码、每条错误消息以及不要这样做的事情：[SuperKernel AOP](https://github.com/super-kernel/.github/blob/main/docs/components/aop.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。织入源码的来源及其授权记录在 [NOTICE](NOTICE) 中。
