<?php
declare(strict_types=1);

namespace CodeX\Exception;

use RuntimeException;

class Template extends RuntimeException
{
    public const int NOT_FOUND = 1;
    public const int SYNTAX_ERROR = 2;
    public const int FILTER_NOT_FOUND = 3;
    public const int FUNCTION_NOT_FOUND = 4;
    public const int UNSAFE_PATH = 5;
    public const int IO_ERROR = 6;
    public const int INVALID_VARIABLE = 7;

    public static function notFound(string $path): self
    {
        return new self('Шаблон не найден: ' . $path, self::NOT_FOUND);
    }

    public static function syntaxError(string $message): self
    {
        return new self('Синтаксическая ошибка шаблона: ' . $message, self::SYNTAX_ERROR);
    }

    public static function filterNotFound(string $name): self
    {
        return new self('Фильтр шаблона \'' . $name . '\' не зарегистрирован.', self::FILTER_NOT_FOUND);
    }

    public static function functionNotFound(string $name): self
    {
        return new self('Функция шаблона \'' . $name . '\' не зарегистрирована.', self::FUNCTION_NOT_FOUND);
    }

    public static function unsafePath(string $path): self
    {
        return new self('Попытка доступа за пределы директории шаблонов: ' . $path, self::UNSAFE_PATH);
    }

    public static function ioError(string $message): self
    {
        return new self('Ошибка файловой системы: ' . $message, self::IO_ERROR);
    }

    public static function invalidVariable(string $name): self
    {
        return new self('Недопустимое имя переменной: ' . $name, self::INVALID_VARIABLE);
    }
}