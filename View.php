<?php
declare(strict_types=1);

namespace CodeX\Template;

use CodeX\Exception\Template;
use DateTimeInterface;
use Random\RandomException;
use ReflectionProperty;
use Throwable;
use Traversable;

/**
 * Шаблонизатор CodeX — компилирует Twig-подобные шаблоны в PHP.
 * Поддерживает наследование ({% extends %}), include, фильтры, функции и автоэкранирование.
 *
 * @api
 * @noinspection PhpUnused
 */
class View
{
    // Максимальная глубина вложенности include для защиты от бесконечной рекурсии
    private const int MAX_INCLUDE_DEPTH = 20;

    // Кэш realpath для ускорения проверок безопасности путей
    private static array $realpathCache = [];

    /** @var array<string, bool> Кэш публичных свойств для resolve() */
    private static array $propertyCache = [];

    /**
     * Директория с шаблонами.
     * Используется асимметричная видимость (PHP 8.4): публичное чтение, приватная запись.
     * Хук `set` гарантирует, что путь всегда будет нормализован и существовать.
     */
    private(set) string $templateDir {
        set { $this->templateDir = $this->ensureDirectory($value); }
    }

    /**
     * Директория для кэша скомпилированных шаблонов.
     */
    private string $cacheDir {
        set { $this->cacheDir = $this->ensureDirectory($value); }
    }

    private array $variables = [];
    private array $filters = [];
    private array $functions = [];

    public function __construct(string $templateDir, string $cacheDir)
    {
        $this->templateDir = $templateDir;
        $this->cacheDir = $cacheDir;
        $this->registerFilters();
    }

    /**
     * Добавление глобальной переменной в шаблон.
     */
    public function assign(string $key, mixed $value): void
    {
        $this->variables[$key] = $value;
    }

    /**
     * Регистрация пользовательского фильтра.
     */
    public function addFilter(string $name, callable $callback): void
    {
        $this->filters[$name] = $callback;
    }

    /**
     * Регистрация пользовательской функции.
     */
    public function addFunction(string $name, callable $callback): void
    {
        $this->functions[$name] = $callback;
    }

    /**
     * Главный метод рендеринга шаблона.
     *
     * @throws RandomException
     */
    public function render(string $template, array $data = []): string
    {
        // Объединяем глобальные переменные и локальные данные
        $localVars = array_merge($this->variables, $data);
        $templateFile = $this->templateDir . $template;

        if (!file_exists($templateFile)) {
            throw Template::notFound($templateFile);
        }

        // Пути к файлам кэша (сам PHP-код и мета-файл с зависимостями)
        $cacheFile = $this->cacheDir . md5($templateFile) . '.php';
        $metaFile = $cacheFile . '.meta.php';
        $needsCompilation = false;

        // Проверка актуальности кэша
        if (!file_exists($cacheFile) || !file_exists($metaFile)) {
            $needsCompilation = true;
        } else {
            $cacheMtime = filemtime($cacheFile);
            // Загружаем массив зависимостей (файл => время изменения)
            $dependencies = include $metaFile;
            foreach ($dependencies as $depFile => $depMtime) {
                // Если хотя бы один зависимый файл изменился или удален - нужна перекомпиляция
                if (!file_exists($depFile) || filemtime($depFile) > $cacheMtime) {
                    $needsCompilation = true;
                    break;
                }
            }
        }

        if ($needsCompilation) {
            $this->compile($templateFile, $cacheFile);
        }

        // Выполнение скомпилированного шаблона в изолированном буфере
        ob_start();
        try {
            // Передаем экземпляр движка в шаблон для вызова фильтров и функций
            $_CODEX_VIEW_ENGINE_ = $this;
            // Извлекаем переменные в локальную область видимости
            extract($localVars, EXTR_SKIP);
            include $cacheFile;
            return ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    // ============================================
    // ФАЙЛОВАЯ СИСТЕМА И БЕЗОПАСНОСТЬ
    // ============================================

    /**
     * Гарантирует, что директория существует и возвращает нормализованный путь.
     */
    private function ensureDirectory(string $path): string
    {
        $normalizedPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($normalizedPath) && !mkdir($normalizedPath, 0755, true) && !is_dir($normalizedPath)) {
            throw Template::ioError('Не удалось создать каталог: ' . $normalizedPath);
        }
        return $normalizedPath;
    }

    /**
     * Проверяет, что путь находится внутри разрешенной директории шаблонов.
     * Защита от Path Traversal (LFI).
     */
    private function assertSafePath(string $path): void
    {
        if (!isset(self::$realpathCache[$this->templateDir])) {
            $realBase = realpath($this->templateDir);
            if ($realBase === false) {
                throw Template::unsafePath($this->templateDir);
            }
            self::$realpathCache[$this->templateDir] = $realBase;
        }
        $realBase = self::$realpathCache[$this->templateDir];

        if (file_exists($path)) {
            if (!isset(self::$realpathCache[$path])) {
                self::$realpathCache[$path] = realpath($path);
            }
            $realPath = self::$realpathCache[$path];
            // Строгая проверка: реальный путь должен начинаться с базового
            if ($realPath === false || !str_starts_with($realPath, $realBase)) {
                throw Template::unsafePath($path);
            }
        } else {
            // Если файл еще не существует (например, при компиляции), используем строковое сравнение
            $normalized = str_replace('\\', '/', $path);
            $normalizedBase = str_replace('\\', '/', $realBase);
            if (!str_starts_with($normalized, $normalizedBase)) {
                throw Template::unsafePath($path);
            }
        }
    }

    /**
     * Атомарная запись файла.
     * Предотвращает чтение наполовину записанного кэша при конкурентных запросах.
     *
     * @throws RandomException
     */
    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw Template::ioError('Не удалось создать директорию: ' . $dir);
        }
        // Создаем временный файл со случайным именем
        $tmpFile = $path . '.' . (random_bytes(8) |> bin2hex(...)) . '.tmp';
        if (file_put_contents($tmpFile, $content) === false) {
            throw Template::ioError('Не удалось записать временный файл.');
        }
        // rename() в POSIX-системах атомарен
        if (!rename($tmpFile, $path)) {
            unlink($tmpFile);
            throw Template::ioError('Не удалось переименовать временный файл.');
        }
    }

    // ============================================
    // УНИВЕРСАЛЬНЫЙ ЛЕКСЕР (DRY)
    // ============================================

    /**
     * Посимвольный обход строки с учетом строковых литералов и экранирования.
     * Это фундамент для корректного парсинга вложенных структур.
     */
    private function iterateString(string $str, callable $callback): void
    {
        $inString = false;
        $stringChar = '';
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];

            if ($inString) {
                // Проверяем закрывающую кавычку, учитывая экранирование слешем
                if ($ch === $stringChar && $this->countPrecedingBackslashes($str, $i) % 2 === 0) {
                    $inString = false;
                }
            } elseif ($ch === '"' || $ch === '\'') {
                $inString = true;
                $stringChar = $ch;
            }

            if ($callback($ch, $i, $inString) === true) {
                break;
            }
        }
    }

    /**
     * Подсчитывает количество обратных слешей перед позицией.
     */
    private function countPrecedingBackslashes(string $str, int $pos): int
    {
        $count = 0;
        $i = $pos - 1;
        while ($i >= 0 && $str[$i] === '\\') {
            $count++;
            $i--;
        }
        return $count;
    }

    /**
     * Разбивает строку по условию, игнорируя разделители внутри скобок и строк.
     */
    private function splitByContext(string $str, callable $shouldSplit): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;

        $this->iterateString($str, function ($ch, $i, $inString) use ($shouldSplit, &$parts, &$buffer, &$depth) {
            if (!$inString) {
                if ($ch === '(' || $ch === '[' || $ch === '{') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                    $depth--;
                }
            }

            if ($shouldSplit($ch, $depth, $inString)) {
                $parts[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $ch;
            }
            return false;
        });

        if ($buffer !== '') {
            $parts[] = $buffer;
        }
        return $parts;
    }

    /**
     * Разбивает строку по символу `|` (pipe) для фильтров.
     */
    private function splitByPipe(string $str): array
    {
        return $this->splitByContext($str, static fn($ch, $depth, $inStr) => !$inStr && $ch === '|' && $depth === 0);
    }

    /**
     * Разбивает аргументы функции по запятым.
     */
    private function splitArguments(string $argsString): array
    {
        $parts = $this->splitByContext($argsString, static fn($ch, $depth, $inStr) => !$inStr && $ch === ',' && $depth === 0);
        return array_filter(array_map('trim', $parts), static fn($arg) => $arg !== '');
    }

    private function splitArrayPairs(string $str): array
    {
        return $this->splitByContext($str, static fn($ch, $depth, $inStr) => !$inStr && $ch === ',' && $depth === 0);
    }

    private function splitWithPairs(string $str): array
    {
        return $this->splitByContext($str, static fn($ch, $depth, $inStr) => !$inStr && $ch === ',' && $depth === 0);
    }

    /**
     * Ищет позицию первого двоеточия на верхнем уровне вложенности.
     */
    private function findColonPosition(string $str): int|false
    {
        $depth = 0;
        $result = false;
        $this->iterateString($str, function ($ch, $i, $inString) use (&$depth, &$result) {
            if ($inString) {
                return false;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
            } elseif ($ch === ':' && $depth === 0) {
                $result = $i;
                return true;
            }
            return false;
        });
        return $result;
    }

    /**
     * Проверяет, полностью ли выражение заключено в указанные скобки.
     */
    private function isEnclosedIn(string $expr, string $open, string $close): bool
    {
        $depth = 0;
        $len = strlen($expr);
        $valid = true;
        $this->iterateString($expr, function ($ch, $i, $inString) use ($open, $close, $len, &$depth, &$valid) {
            if ($inString) {
                return false;
            }
            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                // Если глубина упала до 0, но это не конец строки - значит скобки не охватывают всё выражение
                if ($depth === 0 && $i < $len - 1) {
                    $valid = false;
                    return true;
                }
            }
            return false;
        });
        return $valid && $depth === 0;
    }

    /**
     * Разбивает арифметическое выражение на операнды и оператор.
     * Соблюдает приоритет операций (+ и - имеют меньший приоритет, чем *, /).
     */
    private function splitByArithmetic(string $expr): ?array
    {
        $depth = 0;
        $lastPlusMinusPos = -1;
        $lastMulDivPos = -1;

        $this->iterateString($expr, function ($ch, $i, $inString) use ($expr, &$depth, &$lastPlusMinusPos, &$lastMulDivPos) {
            if ($inString) {
                return false;
            }
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            }
            if ($depth > 0 || ($i === 0 && ($ch === '-' || $ch === '+'))) {
                return false;
            }

            if ($i > 0) {
                $prevNonSpace = '';
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($expr[$j] !== ' ' && $expr[$j] !== "\t") {
                        $prevNonSpace = $expr[$j];
                        break;
                    }
                }
                if (in_array($prevNonSpace, ['(', ',', '+', '-', '*', '/', '%', '=', '>', '<', '!'], true)) {
                    return false;
                }
            }

            if ($ch === '+' || $ch === '-') {
                $lastPlusMinusPos = $i;
            } elseif ($ch === '*' || $ch === '/' || $ch === '%') {
                $lastMulDivPos = $i;
            }
            return false;
        });

        if ($lastPlusMinusPos > 0) {
            return [trim(substr($expr, 0, $lastPlusMinusPos)), $expr[$lastPlusMinusPos], trim(substr($expr, $lastPlusMinusPos + 1))];
        }
        if ($lastMulDivPos > 0) {
            return [trim(substr($expr, 0, $lastMulDivPos)), $expr[$lastMulDivPos], trim(substr($expr, $lastMulDivPos + 1))];
        }
        return null;
    }

    /**
     * Разбирает оператор диапазона `..` (например, 1..10).
     */
    private function splitByRange(string $expr): ?array
    {
        $depth = 0;
        $len = strlen($expr);
        $result = null;

        $this->iterateString($expr, function ($ch, $i, $inString) use ($expr, $len, &$depth, &$result) {
            if ($inString) {
                return false;
            }
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            }

            if ($depth === 0 && $ch === '.' && $i < $len - 1 && $expr[$i + 1] === '.') {
                $left = substr($expr, 0, $i);
                $right = substr($expr, $i + 2);
                if (trim($left) !== '' && trim($right) !== '') {
                    $result = [trim($left), trim($right)];
                    return true;
                }
            }
            return false;
        });
        return $result;
    }

    // ============================================
    // КОМПИЛЯЦИЯ
    // ============================================

    /**
     * Компилирует исходный шаблон в PHP-код и сохраняет в кэш.
     *
     * @throws RandomException
     */
    private function compile(string $templateFile, string $cacheFile, int $depth = 0): void
    {
        $this->assertSafePath($templateFile);
        $content = file_get_contents($templateFile);
        if ($content === false) {
            throw Template::ioError('Не удалось прочитать шаблон: ' . $templateFile);
        }

        // Удаляем комментарии шаблонизатора {# ... #}
        $content = preg_replace('/\{#.*?#}/s', '', $content);
        $dependencies = [$templateFile => filemtime($templateFile)];

        // Обработка наследования {% extends "parent.html" %}
        if (preg_match('/\{%\s*extends\s+[\'"](.+?)[\'"]\s*%}/', $content, $extendsMatch)) {
            $parentFile = $this->templateDir . ltrim($extendsMatch[1], '/\\');
            $this->assertSafePath($parentFile);
            if (file_exists($parentFile)) {
                $dependencies[$parentFile] = filemtime($parentFile);
                $parentContent = file_get_contents($parentFile);
                // Извлекаем блоки из дочернего шаблона
                preg_match_all('/\{%\s*block\s+(\w+)\s*%}(.*?)\{%\s*endblock\s*%}/s', $content, $blocks, PREG_SET_ORDER);
                $childBlocks = [];
                foreach ($blocks as $block) {
                    if (isset($childBlocks[$block[1]])) {
                        throw Template::syntaxError('Дублирующийся блок \'' . $block[1] . '\'.');
                    }
                    $childBlocks[$block[1]] = $block[2];
                }
                // Заменяем блоки в родительском шаблоне
                foreach ($childBlocks as $name => $blockContent) {
                    $parentContent = preg_replace('/\{%\s*block\s+' . preg_quote($name, '/') . '\s*%}.*?\{%\s*endblock\s*%}/s', $blockContent, $parentContent);
                }
                $content = $parentContent;
            }
        }

        // Обработка {% include "file.html" with {var: val} %}
        $content = preg_replace_callback('/\{%\s*include\s+[\'"](.+?)[\'"]\s*(?:with\s+(\{[^%]*\}))?\s*%}/s', function ($matches) use (&$dependencies, $depth) {
            if ($depth >= self::MAX_INCLUDE_DEPTH) {
                throw Template::syntaxError('Превышена максимальная глубина вложенности.');
            }
            $includedTemplate = $matches[1];
            $withBlock = isset($matches[2]) ? trim($matches[2]) : '';
            if ($withBlock !== '' && !$this->isEnclosedIn($withBlock, '{', '}')) {
                throw Template::syntaxError('Несбалансированные скобки в блоке with.');
            }
            $incTemplateFile = $this->templateDir . ltrim($includedTemplate, '/\\');
            $this->assertSafePath($incTemplateFile);
            if (!file_exists($incTemplateFile)) {
                return '<!-- Шаблон ' . htmlspecialchars($includedTemplate) . ' не найден -->';
            }

            $dependencies[$incTemplateFile] = filemtime($incTemplateFile);
            $incCacheFile = $this->cacheDir . md5($incTemplateFile) . '.php';
            if (!file_exists($incCacheFile) || filemtime($incTemplateFile) > filemtime($incCacheFile)) {
                $this->compile($incTemplateFile, $incCacheFile, $depth + 1);
            }
            // Подтягиваем зависимости вложенного шаблона
            if (file_exists($incCacheFile . '.meta.php')) {
                foreach (include $incCacheFile . '.meta.php' as $depFile => $depMtime) {
                    $dependencies[$depFile] = $depMtime;
                }
            }
            return $withBlock !== '' ? $this->compileIncludeWith($incCacheFile, $this->parseWithBlock($withBlock)) : '<?php include ' . var_export($incCacheFile, true) . '; ?>';
        }, $content);

        // Компиляция вывода {{ expr }}
        $content = preg_replace_callback('/\{\{\s*(.+?)\s*}}/s', fn($m) => $this->compileOutput(trim($m[1])), $content);

        // Компиляция управляющих конструкций
        $content = preg_replace_callback('/\{%\s*if\s+(.+?)\s*%}/', fn($m) => '<?php if (' . $this->compileExpression(trim($m[1])) . '): ?>', $content);
        $content = preg_replace_callback('/\{%\s*elseif\s+(.+?)\s*%}/', fn($m) => '<?php elseif (' . $this->compileExpression(trim($m[1])) . '): ?>', $content);
        $content = preg_replace('/\{%\s*else\s*%}/', '<?php else: ?>', $content);
        $content = preg_replace('/\{%\s*endif\s*%}/', '<?php endif; ?>', $content);

        // Компиляция циклов {% for item in collection %}
        $content = preg_replace_callback('/\{%\s*for\s+(\w+)\s+in\s+(.+?)\s*%}/s', function ($m) {
            $phpCollection = $this->compileExpression(trim($m[2]));
            // Создаем объект $loop с метаданными цикла (index, first, last и т.д.)
            return '<?php $__collection = (array)' . $phpCollection . '; $__loopIndex = 0; $__loopLength = count($__collection); foreach ($__collection as $' . $m[1] . '): $loop = (object)[\'index\' => $__loopIndex, \'iteration\' => $__loopIndex + 1, \'remaining\' => $__loopLength - $__loopIndex - 1, \'count\' => $__loopLength, \'first\' => $__loopIndex === 0, \'last\' => $__loopIndex === $__loopLength - 1]; $__loopIndex++; ?>';
        }, $content);
        $content = preg_replace('/\{%\s*endfor\s*%}/', '<?php endforeach; ?>', $content);

        // Компиляция присваиваний {% set var = expr %}
        $content = preg_replace_callback('/\{%\s*set\s+(\w+)\s*=\s*(.+?)\s*%}/s', function ($m) {
            if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $m[1])) {
                throw Template::invalidVariable($m[1]);
            }
            return '<?php $' . $m[1] . ' = ' . $this->compileExpression(trim($m[2])) . '; ?>';
        }, $content);

        // Атомарная запись результата и метаданных
        $this->atomicWrite($cacheFile, $content);
        $this->atomicWrite($cacheFile . '.meta.php', '<?php' . PHP_EOL . 'return ' . var_export($dependencies, true) . ';' . PHP_EOL);
    }

    /**
     * Компилирует выражение вывода с учетом автоэкранирования и фильтров.
     */
    private function compileOutput(string $expr): string
    {
        if ($expr === '') {
            return '';
        }
        $parts = $this->splitByPipe($expr);
        $baseExpr = array_shift($parts) |> $this->compileExpression(...);
        $isRaw = false;
        $filterParts = [];
        foreach ($parts as $part) {
            if (trim($part) === 'raw') {
                $isRaw = true; // Отключаем экранирование
            } else {
                $filterParts[] = $part;
            }
        }
        $baseExpr = $this->compileFilterChain($baseExpr, $filterParts);
        return $isRaw ? '<?php echo ' . $baseExpr . '; ?>' : '<?php echo \CodeX\Template\View::escape(' . $baseExpr . '); ?>';
    }

    /**
     * Оборачивает выражение в цепочку вызовов фильтров.
     */
    private function compileFilterChain(string $baseExpr, array $filterParts): string
    {
        foreach ($filterParts as $filterPart) {
            $filterPart = trim($filterPart);
            if (preg_match('/^(\w+)(?:\((.*?)\))?$/s', $filterPart, $m)) {
                $compiledArgs = '';
                if (($m[2] ?? '') !== '') {
                    $safeParts = array_map(fn($arg) => $this->compileExpression($arg), $this->splitArguments($m[2]));
                    $compiledArgs = implode(', ', $safeParts);
                }
                // Вызов статического метода, который делегирует выполнение пользовательскому коллбэку
                $baseExpr = '\CodeX\Template\View::callFilter($_CODEX_VIEW_ENGINE_, \'' . $m[1] . '\', ' . $baseExpr . ', [' . $compiledArgs . '])';
            } else {
                throw Template::syntaxError('Некорректный синтаксис фильтра: ' . $filterPart);
            }
        }
        return $baseExpr;
    }

    /**
     * Рекурсивный парсер выражений (Recursive Descent Parser).
     * Превращает строку шаблона в валидный PHP-код.
     */
    private function compileExpression(string $expr): string
    {
        $expr = trim($expr);
        if ($expr === '') {
            throw Template::syntaxError('Пустое выражение.');
        }
        // Запрещаем прямой вызов методов объектов для безопасности (Sandbox)
        if (str_contains($expr, '->')) {
            throw Template::syntaxError('Вызов методов (->) запрещен.');
        }

        // Раскрытие скобок
        if (str_starts_with($expr, '(') && str_ends_with($expr, ')') && $this->isEnclosedIn($expr, '(', ')')) {
            return '(' . $this->compileExpression(substr($expr, 1, -1)) . ')';
        }
        // Диапазоны (1..10)
        if (str_contains($expr, '..') && ($range = $this->splitByRange($expr)) !== null) {
            return '\CodeX\Template\View::range(' . $this->compileExpression($range[0]) . ', ' . $this->compileExpression($range[1]) . ')';
        }
        // Тернарный оператор
        if (($ternary = $this->splitTernary($expr)) !== null) {
            return '(' . $this->compileExpression($ternary[0])
                . ' ? ' . $this->compileExpression($ternary[1])
                . ' : ' . $this->compileExpression($ternary[2]) . ')';
        }
        // Логические операторы (and, or, xor) с учетом строк и скобок
        $logicalOps = ['and', 'or', 'xor'];
        $logicalMatch = null;

        // Ищем операторы справа налево (для правильной ассоциативности)
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $len = strlen($expr);

        for ($i = $len - 1; $i >= 0; $i--) {
            $ch = $expr[$i];

            if ($inString) {
                if ($ch === $stringChar && $this->countPrecedingBackslashes($expr, $i) % 2 === 0) {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"' || $ch === '\'') {
                $inString = true;
                $stringChar = $ch;
                continue;
            }

            if ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth++;
                continue;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth--;
                continue;
            }

            if ($depth === 0) {
                foreach ($logicalOps as $op) {
                    $opLen = strlen($op);
                    // Проверяем, что это отдельное слово (окружено пробелами)
                    if ($i >= $opLen - 1 && strncasecmp(substr($expr, $i - $opLen + 1, $opLen), $op, $opLen) === 0) {
                        $before = $i - $opLen;
                        $after = $i + 1;
                        if (($before < 0 || $expr[$before] === ' ' || $expr[$before] === "\t") &&
                            ($after >= $len || $expr[$after] === ' ' || $expr[$after] === "\t")) {
                            $logicalMatch = [
                                substr($expr, 0, $before + 1),
                                $op,
                                substr($expr, $after)
                            ];
                            break 2;
                        }
                    }
                }
            }
        }

        if ($logicalMatch !== null) {
            return '(' . $this->compileExpression(trim($logicalMatch[0]))
                . ' ' . strtolower($logicalMatch[1]) . ' '
                . $this->compileExpression(trim($logicalMatch[2])) . ')';
        }

        // Операторы сравнения и логические И/ИЛИ
        $opMatch = [];
        // Использование array_any (PHP 8.4+) для поиска первого подходящего паттерна
        $hasMatch = array_any(
            ['/(.*?)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.*)/s', '/(.*?)\s*(&&|\|\|)\s*(.*)/s'],
            static function (string $pattern) use ($expr, &$opMatch): bool {
                return preg_match($pattern, $expr, $opMatch) === 1;
            }
        );

        if ($hasMatch) {
            return '(' . $this->compileExpression($opMatch[1]) . ' ' . $opMatch[2] . ' ' . $this->compileExpression($opMatch[3]) . ')';
        }

        // Операторы вхождения (in, not in)
        if (preg_match('/^(.*?)\s+not\s+in\s+(.*)$/is', $expr, $m)) {
            return '(!\CodeX\Template\View::contains(' . $this->compileExpression(trim($m[2])) . ', ' . $this->compileExpression(trim($m[1])) . '))';
        }
        if (preg_match('/^(.*?)\s+in\s+(.*)$/is', $expr, $m)) {
            return '\CodeX\Template\View::contains(' . $this->compileExpression(trim($m[2])) . ', ' . $this->compileExpression(trim($m[1])) . ')';
        }
        // Проверка на определенность (is defined)
        if (preg_match('/^(.*?)\s+is\s+not\s+defined$/is', $expr, $m)) {
            return '(!\CodeX\Template\View::isDefined(' . $this->compileExpression(trim($m[1])) . '))';
        }
        if (preg_match('/^(.*?)\s+is\s+defined$/is', $expr, $m)) {
            return '\CodeX\Template\View::isDefined(' . $this->compileExpression(trim($m[1])) . ')';
        }
        // Унарное отрицание
        if (preg_match('/^(?:!|not)\s+(.+)$/is', $expr, $unary)) {
            return '(!' . $this->compileExpression($unary[1]) . ')';
        }

        // Арифметика
        if (($arith = $this->splitByArithmetic($expr)) !== null) {
            return '(' . $this->compileExpression($arith[0]) . ' ' . $arith[1] . ' ' . $this->compileExpression($arith[2]) . ')';
        }
        if (preg_match('/^-\s*(.+)$/', $expr, $m)) {
            return '(-' . $this->compileExpression($m[1]) . ')';
        }

        // Массивы [1, 2, "key": "val"]
        if (preg_match('/^\[\s*(.*)\s*]$/s', $expr, $arrMatch)) {
            $pairs = $this->splitArrayPairs($arrMatch[1]);
            if (empty($pairs) && trim($arrMatch[1]) === '') {
                return '[]';
            }
            $compiled = [];
            foreach ($pairs as $pair) {
                if (preg_match('/^\s*([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*:\s*(.+)$/s', $pair, $pm)) {
                    $compiled[] = var_export($pm[1], true) . ' => ' . $this->compileExpression($pm[2]);
                } else {
                    $compiled[] = $this->compileExpression($pair);
                }
            }
            return '[' . implode(', ', $compiled) . ']';
        }

        // Объекты (хэши) {key: "val"}
        if (str_starts_with($expr, '{') && str_ends_with($expr, '}') && $this->isEnclosedIn($expr, '{', '}')) {
            $inner = trim(substr($expr, 1, -1));
            if ($inner === '') {
                return '[]';
            }
            $compiled = [];
            foreach ($this->splitWithPairs($inner) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $colonPos = $this->findColonPosition($pair);
                if ($colonPos === false) {
                    throw Template::syntaxError('Некорректный синтаксис объекта.');
                }

                $key = trim(substr($pair, 0, $colonPos));
                $val = trim(substr($pair, $colonPos + 1));

                if (preg_match('/^[\'"](.+)[\'"]$/', $key, $km)) {
                    $compiledKey = var_export($km[1], true);
                } elseif (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $key)) {
                    $compiledKey = var_export($key, true);
                } else {
                    throw Template::syntaxError('Недопустимый ключ.');
                }

                $compiled[] = $compiledKey . ' => ' . $this->compileExpression($val);
            }
            return '[' . implode(', ', $compiled) . ']';
        }

        // Вызов функций (белый список + пользовательские)
        if (preg_match('/^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\((.*)\)$/s', $expr, $func)) {
            $allowed = ['in_array', 'strpos', 'count', 'array_key_exists', 'isset', 'empty', 'is_array', 'is_string', 'is_int', 'is_numeric', 'strlen', 'substr', 'date', 'time', 'str_contains', 'str_starts_with', 'str_ends_with', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'mb_strtolower', 'mb_strtoupper', 'abs', 'round', 'ceil', 'floor', 'min', 'max', 'intval', 'floatval', 'strval', 'array_merge'];
            if (!isset($this->functions[$func[1]]) && !in_array($func[1], $allowed, true)) {
                throw Template::syntaxError('Вызов функции \'' . $func[1] . '\' запрещен.');
            }
            $args = trim($func[2]) !== '' ? array_map(fn($a) => $this->compileExpression($a), $this->splitArguments($func[2])) : [];
            return isset($this->functions[$func[1]]) ? '\CodeX\Template\View::callFunction($_CODEX_VIEW_ENGINE_, \'' . $func[1] . '\', [' . implode(',', $args) . '])' : $func[1] . '(' . implode(',', $args) . ')';
        }

        // Фильтры без вывода (внутри выражений)
        $pipeParts = $this->splitByPipe($expr);
        if (count($pipeParts) > 1) {
            // Использование Pipe-оператора (PHP 8.5) для передачи в compileExpression
            $baseExpr = array_shift($pipeParts) |> $this->compileExpression(...);
            return $this->compileFilterChain($baseExpr, $pipeParts);
        }

        // Литералы и переменные
        if (in_array(strtolower($expr), ['true', 'false', 'null'], true)) {
            return strtolower($expr);
        }
        if (preg_match('/^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$/', $expr, $var)) {
            return '$' . $var[1];
        }
        // Обращение к свойствам через точку (user.name)
        if (preg_match('/^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\.(.+)$/', $expr, $dot)) {
            return '\CodeX\Template\View::resolve($' . $dot[1] . ', [' . implode(',', array_map(static fn($p) => var_export($p, true), explode('.', $dot[2]))) . '])';
        }
        if (preg_match('/^([\'"])(.*)\1$/s', $expr, $str)) {
            return var_export($str[2], true);
        }
        if (is_numeric($expr)) {
            return $expr;
        }

        throw Template::syntaxError('Недопустимое выражение: ' . $expr);
    }

    // ============================================
    // ХЕЛПЕРЫ И ВЫПОЛНЕНИЕ (RUNTIME)
    // ============================================

    public static function callFilter(self $tpl, string $name, mixed $value, array $args = []): mixed
    {
        if (!isset($tpl->filters[$name])) {
            throw Template::filterNotFound($name);
        }
        return ($tpl->filters[$name])($value, ...$args);
    }

    public static function callFunction(self $tpl, string $name, array $args = []): mixed
    {
        if (!isset($tpl->functions[$name])) {
            throw Template::functionNotFound($name);
        }
        return ($tpl->functions[$name])(...$args);
    }

    /**
     * Разрешает цепочку свойств/методов объекта или ключей массива.
     * Кэширует результаты ReflectionProperty для производительности.
     */
    public static function resolve(mixed $root, array $path): mixed
    {
        if ($root === null || empty($path)) {
            return $root;
        }
        $current = $root;
        foreach ($path as $key) {
            if (is_array($current)) {
                if (!array_key_exists($key, $current)) {
                    return null;
                }
                $current = $current[$key];
            } elseif (is_object($current)) {
                // Кэширование проверки публичных свойств
                $cacheKey = $current::class . '::' . $key;
                if (!isset(self::$propertyCache[$cacheKey])) {
                    self::$propertyCache[$cacheKey] = property_exists($current, $key)
                        && new ReflectionProperty($current, $key)->isPublic(); // ← без скобок
                }

                if (self::$propertyCache[$cacheKey]) {
                    $current = $current->$key;
                    continue;
                }

                // Попытка вызова геттеров (getX, isX, hasX)
                $getter = 'get' . ucfirst($key);
                $isSer = 'is' . ucfirst($key);
                $hasSer = 'has' . ucfirst($key);
                if (method_exists($current, $getter)) {
                    $current = $current->$getter();
                } elseif (method_exists($current, $isSer)) {
                    $current = $current->$isSer();
                } elseif (method_exists($current, $hasSer)) {
                    $current = $current->$hasSer();
                } elseif (method_exists($current, '__get')) {
                    $current = $current->$key;
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }
        return $current;
    }

    /**
     * Экранирование HTML-сущностей для защиты от XSS.
     */
    public static function escape(mixed $value): string
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Проверка вхождения (аналог Twig оператора `in`).
     */
    public static function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_string($haystack)) {
            return str_contains($haystack, (string) $needle);
        }
        if (is_array($haystack)) {
            return in_array($needle, $haystack, true);
        }
        if ($haystack instanceof Traversable) {
            foreach ($haystack as $item) {
                if ($item === $needle) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    /**
     * Генерация диапазона (числового или символьного).
     */
    public static function range(int|float|string $start, int|float|string $end): array
    {
        if (is_string($start) && is_string($end) && strlen($start) === 1 && strlen($end) === 1) {
            return range($start, $end);
        }
        return range((int) $start, (int) $end);
    }

    public static function isDefined(mixed $value): bool
    {
        return isset($value);
    }

    /**
     * Парсинг блока `with {key: val}` для include.
     */
    private function parseWithBlock(string $block): array
    {
        $block = trim(trim($block, '{}'));
        if ($block === '') {
            return [];
        }
        $result = [];
        foreach ($this->splitWithPairs($block) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $colonPos = $this->findColonPosition($pair);
            if ($colonPos === false) {
                throw Template::syntaxError('Некорректный синтаксис в with-блоке.');
            }
            $key = trim(substr($pair, 0, $colonPos));
            if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $key)) {
                throw Template::invalidVariable($key);
            }

            // Использование Pipe-оператора (PHP 8.5) для цепочки вызовов
            $result[$key] = substr($pair, $colonPos + 1)
                    |> trim(...)
                    |> $this->compileExpression(...);
        }
        return $result;
    }

    /**
     * Генерация кода для изолированного include с сохранением и восстановлением переменных.
     */
    private function compileIncludeWith(string $cacheFile, array $withVars): string
    {
        if (empty($withVars)) {
            return '<?php include ' . var_export($cacheFile, true) . '; ?>';
        }
        $code = '<?php' . PHP_EOL . '    $__includeOldVars = [];' . PHP_EOL;
        foreach (array_keys($withVars) as $key) {
            $code .= '    $__includeOldVars[' . var_export($key, true) . '] = isset($' . $key . ') ? $' . $key . ' : null;' . PHP_EOL;
            $code .= '    $__includeHad_' . $key . ' = isset($' . $key . ');' . PHP_EOL;
            $code .= '    $' . $key . ' = ' . $withVars[$key] . ';' . PHP_EOL;
        }
        $code .= '    include ' . var_export($cacheFile, true) . ';' . PHP_EOL;
        foreach (array_keys($withVars) as $key) {
            $code .= '    if ($__includeHad_' . $key . ') { $' . $key . ' = $__includeOldVars[' . var_export($key, true) . ']; } else { unset($' . $key . '); }' . PHP_EOL;
        }
        return $code . '?>';
    }

    // ============================================
    // РЕГИСТРАЦИЯ БАЗОВЫХ ФИЛЬТРОВ
    // ============================================

    private function registerFilters(): void
    {
        // Строковые фильтры
        $stringFilters = [
            'upper' => static fn($v) => mb_strtoupper((string) $v, 'UTF-8'),
            'lower' => static fn($v) => mb_strtolower((string) $v, 'UTF-8'),
            'trim' => 'trim',
            'ltrim' => 'ltrim',
            'rtrim' => 'rtrim',
            'striptags' => 'strip_tags',
            'urlencode' => 'urlencode',
            'urldecode' => 'urldecode',
            'basename' => 'basename',
            'dirname' => 'dirname',
        ];
        foreach ($stringFilters as $name => $cb) {
            $this->filters[$name] = static fn($v) => $cb((string) $v);
        }

        $this->filters['extension'] = static fn($v) => pathinfo((string) $v, PATHINFO_EXTENSION);
        $this->filters['nl2br'] = static fn($v) => nl2br((string) $v);
        $this->filters['json_encode'] = static fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->filters['escape'] = static fn($v) => self::escape($v);
        $this->filters['e'] = static fn($v) => self::escape($v);
        $this->filters['raw'] = static fn($v) => $v;

        // Фильтр default (замена пустых значений и null)
        $this->filters['default'] = static fn($v, $d = '') => ($v === null || $v === '') ? $d : $v;

        $this->filters['replace'] = static fn($v, $s, $r) => str_replace($s, $r, (string) $v);

        // Фильтры подсчета
        $counter = static fn($v) => is_countable($v) ? count($v) : mb_strlen((string) $v, 'UTF-8');
        $this->filters['length'] = $counter;
        $this->filters['size'] = $counter;
        $this->filters['count'] = static fn($v) => is_countable($v) ? count($v) : 0;

        // Работа с массивами и строками
        $this->filters['join'] = static fn($v, $g = ', ') => is_array($v) ? implode($g, $v) : (string) $v;
        $this->filters['split'] = static fn($v, $d = ',') => is_string($v) ? explode($d, $v) : (array) $v;
        $this->filters['merge'] = static fn($v, $o) => is_array($v) ? array_merge($v, $o) : [];
        $this->filters['keys'] = static fn($v) => is_array($v) ? array_keys($v) : [];
        $this->filters['values'] = static fn($v) => is_array($v) ? array_values($v) : [];
        $this->filters['sort'] = static function ($v) {
            if (!is_array($v)) {
                return [];
            }
            $c = $v;
            sort($c);
            return $c;
        };
        $this->filters['unique'] = static fn($v) => is_array($v) ? array_values(array_unique($v)) : [];
        $this->filters['filter'] = static fn($v) => is_array($v) ? array_values(array_filter($v)) : [];
        $this->filters['column'] = static fn($v, $k) => is_array($v) ? array_column($v, $k) : [];
        $this->filters['batch'] = static fn($v, $s) => is_array($v) ? array_chunk($v, $s) : [];
        $this->filters['first'] = static fn($v) => is_array($v) ? reset($v) : mb_substr((string) $v, 0, 1, 'UTF-8');
        $this->filters['last'] = static fn($v) => is_array($v) ? end($v) : mb_substr((string) $v, -1, 1, 'UTF-8');
        $this->filters['slice'] = static fn($v, $s, $l = null) => is_array($v) ? array_slice($v, $s, $l) : mb_substr((string) $v, $s, $l, 'UTF-8');

        // Использование Pipe-оператора (PHP 8.5) для reverse
        $this->filters['reverse'] = static fn($v) => match (true) {
            is_string($v) => $v |> mb_str_split(...) |> array_reverse(...) |> (static fn($a) => implode('', $a)),
            is_array($v) => array_reverse($v),
            default => $v,
        };

        // Математические фильтры
        $this->filters['abs'] = static fn($v) => abs((float) $v);
        $this->filters['round'] = static fn($v, $p = 0) => round((float) $v, $p);
        $this->filters['ceil'] = static fn($v) => (int) ceil((float) $v);
        $this->filters['floor'] = static fn($v) => (int) floor((float) $v);
        $this->filters['number_format'] = static fn($v, $d = 0, $dp = '.', $ts = ',') => number_format((float) $v, $d, $dp, $ts);

        // Фильтр даты
        $this->filters['date'] = static function ($v, $f = 'Y-m-d') {
            if ($v instanceof DateTimeInterface) {
                return $v->format($f);
            }
            if (is_numeric($v)) {
                return date($f, (int) $v);
            }
            if (is_string($v) && $v !== '') {
                $t = strtotime($v);
                return $t !== false ? date($f, $t) : $v;
            }
            return $v;
        };
    }

    /**
     * Разбивает тернарное выражение на [условие, true-ветка, false-ветка].
     * Учитывает строковые литералы и вложенные скобки — не путает `:` внутри
     * строки 'd.m.Y H:i' с разделителем тернарного оператора.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function splitTernary(string $expr): ?array
    {
        $inString = false;
        $stringChar = '';
        $depth = 0;
        $questionPos = null;
        $colonPos = null;
        $len = strlen($expr);

        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];

            // Обработка строковых литералов
            if ($inString) {
                if ($ch === $stringChar && $this->countPrecedingBackslashes($expr, $i) % 2 === 0) {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"' || $ch === '\'') {
                $inString = true;
                $stringChar = $ch;
                continue;
            }

            // Отслеживание глубины скобок
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
                continue;
            }

            // Ищем `?` и `:` только на верхнем уровне
            if ($depth === 0) {
                if ($ch === '?' && $questionPos === null) {
                    $questionPos = $i;
                } elseif ($ch === ':' && $questionPos !== null && $colonPos === null) {
                    $colonPos = $i;
                    break; // нашли оба разделителя
                }
            }
        }

        if ($questionPos === null || $colonPos === null) {
            return null;
        }

        $condition = trim(substr($expr, 0, $questionPos));
        $trueBranch = trim(substr($expr, $questionPos + 1, $colonPos - $questionPos - 1));
        $falseBranch = trim(substr($expr, $colonPos + 1));

        if ($condition === '' || $trueBranch === '' || $falseBranch === '') {
            return null;
        }

        return [$condition, $trueBranch, $falseBranch];
    }
}