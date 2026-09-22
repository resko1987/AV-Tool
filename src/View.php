<?php
declare(strict_types=1);
namespace AV;

class View
{
    private static string $templatesDir = '';

    public static function init(): void
    {
        self::$templatesDir = dirname(__DIR__) . '/templates';
    }

    public static function render(string $template, array $data = []): void
    {
        extract($data);
        $file = self::$templatesDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: $template");
        }
        ob_start();
        include $file;
        $content = ob_get_clean();
        echo $content;
    }

    public static function partial(string $name, array $data = []): void
    {
        extract($data);
        $file = self::$templatesDir . '/partials/' . $name . '.php';
        if (is_file($file)) {
            include $file;
        }
    }

    public static function layout(string $title, string $active, string $contentHtml, array $extra = []): void
    {
        $data = array_merge($extra, [
            'title'   => $title,
            'active'  => $active,
            'content' => $contentHtml,
        ]);
        self::render('layout', $data);
    }
}

View::init();
