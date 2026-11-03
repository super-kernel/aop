<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel AOP

`super-kernel/aop` 提供切面声明、方法织入、连接点和拦截器调度，类加载在启动时激活。缓存的文件代理和 Stream 回退由 `super-kernel/mimic` 提供。类图来自应用的 `SuperKernel\Contract\AutoLoaderInterface` 绑定，默认由 `super-kernel/autoloader` 提供。

## Aspect 注解

`SuperKernel\Aop\Attribute\Aspect` 标记一个处理切面行为的类。原生注解放在这个处理器类上，其参数描述该处理器所针对的类、方法和属性 hook。

它是一个 final、readonly、不可重复、只能标注在类上的原生 PHP 注解。一个声明可以在三个公开的只读数组中各包含多个切点：

- `classes`：目标类名的列表。
- `methods`：目标类名映射到非空的方法名列表。
- `propertyHooks`：目标类名映射到属性名，每个属性名对应一个非空的 `get` 和/或 `set` hook 名称列表。属性名不带 `$`。

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

三个参数都默认为空数组，用于手动自织入。名称、顺序和列表中的重复项都会保留。类名可以带前导命名空间分隔符；这些结构化声明不接受通配符，也不接受 `Class::method` 这样的选择器字符串。

构造时会检查标识符语法、列表形状和支持的 hook 名称，声明格式错误时抛出 `InvalidArgumentException`。它不会加载目标类，也不检查其成员是否存在，因此元数据收集可以让目标保持未加载，留待之后织入。

带有类或方法切点的处理器实现 `Contract\AspectInterface`。容器为它们提供依赖，`process(ProceedingJoinPointInterface): mixed` 提供环绕通知。调用 `proceed()` 执行原方法，保留其结果，或有意提供一个替代结果。如果某段后置逻辑在原方法抛出异常时也必须执行，请使用 `try/finally`。

声明的目标类被加载时，会解析匹配的处理器并注册其通知。不带参数的声明表示自织入：声明它的类本身会被织入，可以手动注册回调，而不必实现 `AspectInterface`。

注解接受属性 hook 切点，但 hook 体不会被织入。加载声明了属性 hook 的目标时，会在声明该目标之前抛出 `LogicException`。未加载的目标不会妨碍启动，也不会影响无关类的加载。

## 切面收集器

`SuperKernel\Aop\Collector\AspectCollector` 是一个 final、readonly 的服务；需要切面声明时通过 DI 解析它。`getClasses()` 返回以声明类名为键的原始 `Aspect` 实例，保持发现顺序和包归属。它按值返回数组，从不反射或自动加载目标类，也不会再次扫描。

`isTargetClass()` 检查收集到的类、方法和属性 hook 目标，不会加载它们。它忽略类名的大小写和前导命名空间分隔符。处理器本身不是目标，除非它的某个切点选中了它自己。

## 应用启动

在 `SuperKernel\Event\BeforeApplicationStart` 期间，AOP 以 `PHP_INT_MAX` 优先级（先于优先级更低的监听器），在至少收集到一个 Aspect 时，把它的类加载器注册到 SPL 栈的最前面。没有任何 Aspect 时，什么都不会激活，Mimic 也不会初始化。解析默认类加载器时，会先执行 Autoloader 组件的 SPL 接管；然后 AOP 把自己的回调放到最前面。重复分发启动事件不会添加重复的回调，也不会改变已注册的通知。

## 应用构建

在 `SuperKernel\Event\ApplicationBuild` 期间（SKernel 的 `build` 命令会在项目自己的进程中发布该事件），只要收集到至少一个 Aspect，AOP 就为类图中每个仍有待加载路径的目标准备代理。不会加载目标类、不会解析其处理器，也不会注册类加载器。打包后的应用（例如由该项目构建的 Phar）直接加载这些准备好的代理，而不在运行时织入；没有预先准备代理的目标会通过 Mimic 的 Stream 驱动织入。解析和转换失败会使构建失败。

## 切面自动加载

对于每次类请求，AOP 加载器只织入尚未加载、且在类图中有待加载路径的已声明目标和自织入类；其他请求会交给其余的 SPL 加载器。被选中的类通过 Mimic 准备，从容器中解析其匹配的处理器，执行准备好的源码，并在请求返回之前注册匹配的类选择器和方法选择器。

- 每个被解析的处理器都必须实现 `AspectInterface`。无关的声明不会解析它们的处理器。
- 如果多个处理器选中了同一个（不区分大小写的）选择器，按发现顺序最后一个会替换之前的注册。对已加载的目标重复请求不会替换其回调。
- 处理器在目标被声明之前解析，因此解析或校验失败时目标保持未加载，可以重试。处理器的构造函数不得直接或通过其他依赖请求当前正在加载的目标；这样的依赖属于循环依赖。
- 只有在准备好的源码成功声明了所请求的目标之后，才会发布通知。

Mimic 把代理写到 `<VendorDirInterface>/.cache/mimic/<class-name>.tpl`，并在必要时回退到 `mimic://` 流；缓存行为参见 [Mimic README](https://github.com/super-kernel/mimic/blob/main/README.md)。

## 拦截器与连接点

织入器会改写具体方法，使其通过 `Runtime\Dispatcher` 并借助 `Runtime\ProceedingJoinPoint` 进行分发。请在调用被选中的方法之前注册回调：

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

`Dispatcher::intercept()` 接受类名、`Class::method` 或 `[Class, method]`，返回 `void`。选择器不区分大小写。方法选择器优先于类选择器，重新注册一个选择器会替换其回调。匹配使用运行时的类，对静态方法则使用被调用的类，不会扩展到父类或接口。没有回调时，分发直接进入原方法体。

`Runtime\ProceedingJoinPoint` 实现 `Contract\ProceedingJoinPointInterface`；Aspect 实现接收该接口。`getInstance()` 对实例方法返回对象，对静态方法返回类名字符串。`getArguments()`、`getClassReflection()`、`getMethodReflection()` 和 `getShortMethodName()` 描述原始调用。`proceed()` 执行原方法体。回调可以不调用 `proceed()` 直接返回，从而跳过原方法体；外层方法仍会检查其返回类型。构造函数、析构函数和 `void` 方法会丢弃回调的结果。PHP 在拦截之前完成构造函数属性提升。`never` 拦截器不得正常返回。

注册信息和 `Runtime\Tracker` 是进程全局的。加载一个被选中的目标，会为该进程安装其通知；fork 出的工作进程会继承父进程中已经注册的回调。通知发现通过 `AspectInterface` 支持类切点和方法切点。没有单独的 Pointcut 注解。

## 支持的源码与诊断

- 请使用可信的、以声明为主的文件。被选中文件中的所有具体方法都会被织入；抽象方法保持不变。final 修饰符和 private 可见性会被保留。`Mutation\Modifier` 是一个可选的 PHP-Parser 访问器，可以放宽 final 修饰符和 private 可见性；默认的 mutator 不会安装它。
- 原始参数值和提升属性的默认对象会被保留。`__FILE__` 和 `__DIR__` 指向原始源码（本地文件或 Phar 归档内的文件），包括在类常量、属性初始值和参数默认值中。生成的代码引用带命名空间的源码常量，而不是嵌入绝对路径；`Runtime\SourceMetadata` 在加载之前根据原始类图绑定这些常量。这些常量按源码所属包的名称和包内相对路径命名，因此在项目移动或打包进 Phar 归档之前织入的代码，绑定的是实际运行的源码。直接使用 AOP mutator 集成 Mimic 时，必须在执行准备好的源码之前，在注入的实例上调用 `SourceMetadata::register($class)`。常量绑定只在当前进程内有效。
- 方法体中的引用返回、引用参数或可变参数、带 hook 的提升参数、生成器、静态局部变量，以及 `__FUNCTION__`、`__METHOD__` 或 `__LINE__`，都会在加载之前被拒绝。这项保守的检查也覆盖嵌套的闭包。
- 从转换后的方法体中抛出的异常保留其对象，并获得原始文件和最后进入的顶层语句的行号。嵌套语句可能报告外层语句的行号。回调和外部的失败保留它们自己的位置；嵌套分发会恢复调用方的诊断状态。
- 调用栈帧和反射得到的行号仍然描述生成的代码。诊断行号在挂起的 Fiber 或 Swoole 协程之间不是隔离的。正常初始化不会替换全局异常处理器。
- 解析和转换失败会向外传播，不会退回到未转换的加载。未标记的类和类图中缺失的条目会继续交给其他 SPL 加载器。已加载的符号无法被重写。

## 缓存身份

`Mutation\Mutator::getCacheKey()` 标识生成代码的语义，因此改变织入语义的版本会重新生成文件代理和 Stream 代理。声明发生变化后，请重新生成注解快照和 Phar 快照，并重启工作进程以加载重新构建的代码。

## 许可证

本组件按 wheakerd 的指定以 [MIT 许可证](LICENSE) 发布。其源码来自经授权的 Mimic 移植；来源和授权声明保留在 [NOTICE](NOTICE) 中。
