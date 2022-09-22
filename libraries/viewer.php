<?php

namespace Esyede;

defined('DS') or exit('No direct script access.');

use System\Str;

class Viewer
{
    private $file;
    private $folder;
    private $logdir;
    private $regexes = [
        'logs' => '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}([\+-]\d{4})?\].*/',
        'current_log' => [
            '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}([\+-]\d{4})?)\](?:.*?(\w+)\.|.*?)',
            ': (.*?)( in .*?:[0-9]+)?$/i'
        ],
        'files' => '/\{.*?\,.*?\}/i',
    ];

    private $css = [
        'debug' => 'info',
        'info' => 'info',
        'notice' => 'info',
        'warning' => 'warning',
        'error' => 'danger',
        'critical' => 'danger',
        'exception' => 'danger',
        'alert' => 'danger',
        'emergency' => 'danger',
        'processed' => 'info',
        'failed' => 'warning',
    ];

    private $icons = [
        'debug' => 'info-circle',
        'info' => 'info-circle',
        'notice' => 'info-circle',
        'warning' => 'exclamation-triangle',
        'error' => 'exclamation-triangle',
        'critical' => 'exclamation-triangle',
        'exception' => 'exclamation-triangle',
        'alert' => 'exclamation-triangle',
        'emergency' => 'exclamation-triangle',
        'processed' => 'info-circle',
        'failed' => 'exclamation-triangle'
    ];

    public function __construct()
    {
        $this->logdir = path('storage').'logs';
    }

    public function in($folder)
    {
        if (! Str::starts_with($folder, $this->logdir)) {
            $folder = $this->logdir.$folder;            
        }

        if (file_exists($folder)) {
            $this->folder = $folder;
        } elseif (is_array($this->logdir)) {
            foreach ($this->logdir as $value) {
                $path = $value.DS.$folder;

                if (file_exists($path)) {
                    $this->folder = $folder;
                    break;
                }
            }
        } else {
            $path = $this->logdir.DS.$folder;

            if (file_exists($path)) {
                $this->folder = $folder;
            }
        }
    }

    public function of($file)
    {
        $file = $this->path($file);

        if (file_exists($file)) {
            $this->file = $file;
        }
    }

    public function path($file)
    {
        if (file_exists($file)) {
            return $file;
        }

        if (is_array($this->logdir)) {
            foreach ($this->logdir as $folder) {
                if (file_exists($folder.DS.$file)) {
                    $file = $folder.DS.$file;
                    break;
                }
            }

            return $file;
        }

        $logdir .= $this->logdir.($this->folder ? DS.$this->folder : '');
        $file = $logdir.DS.$file;

        if (dirname($file) !== $logdir) {
            throw new \Exception(sprintf('No such log file: %s', $file));
        }

        return $file;
    }

    public function dirname()
    {
        return $this->folder;
    }

    public function filename()
    {
        return basename($this->file);
    }

    public function all()
    {
        $log = [];

        if (! $this->file) {
            $file = $this->folder ? $this->files_of() : $this->files();

            if (! count($file)) {
                return [];
            }

            $this->file = $file[0];
        }

        if (filesize($this->file) > 52428800) {
            return;
        }

        if (! is_readable($this->file)) {
            return [[
                'context' => '',
                'level' => '',
                'date' => null,
                'text' => 'Log file "'.$this->file.'" not readable',
                'stack' => '',
            ]];
        }

        $file = file_get_contents($this->file);

        preg_match_all($this->regex('logs'), $file, $headings);

        if (! is_array($headings)) {
            return $log;
        }

        $data = preg_split($this->regex('logs'), $file);

        if ($data[0] < 1) {
            array_shift($data);
        }

        foreach ($headings as $heading) {
            for ($i = 0, $j = count($heading); $i < $j; $i++) {
                $levels = $this->levels();

                foreach ($levels as $level) {
                    if (strpos(strtolower($heading[$i]), '.'.$level)
                    || strpos(strtolower($heading[$i]), $level.':')) {
                        $regex = $this->regex('current_log', 0).$level.$this->regex('current_log', 1);

                        preg_match($regex, $heading[$i], $current);

                        if (! isset($current[4])) {
                            continue;
                        }

                        $log[] = [
                            'context' => $current[3],
                            'level' => $level,
                            'folder' => $this->folder,
                            'level_class' => $this->css($level),
                            'level_img' => $this->icon($level),
                            'date' => $current[1],
                            'text' => $current[4],
                            'in_file' => isset($current[5]) ? $current[5] : null,
                            'stack' => preg_replace("/^\n*/", '', $data[$i]),
                        ];
                    }
                }
            }
        }

        if (empty($log)) {
            $lines = explode(PHP_EOL, $file);
            $log = [];

            foreach ($lines as $key => $line) {
                $log[] = [
                    'context' => '',
                    'level' => '',
                    'folder' => '',
                    'level_class' => '',
                    'level_img' => '',
                    'date' => $key + 1,
                    'text' => $line,
                    'in_file' => null,
                    'stack' => '',
                ];
            }
        }

        return array_reverse($log);
    }

    public function lists($path = null)
    {
        $items = [];
        $dir = $path ? $path : $this->logdir;
        $nodes = scandir($dir);

        foreach ($nodes as $node) {
            if ($node === '.' || $node === '..' || ! Str::ends_with($node, 'log.php')) {
                continue;
            }

            $path = $dir.DS.$node;

            if (is_dir($path)) {
                $items[$path] = $this->lists($path);
            } else {
                $items[] = $path;
            }
        }

        return $items;
    }

    public function folders($folder = '')
    {
        $folders = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->logdir.DS.$folder,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                $folders[] = $item->getRealPath();
            }
        }

        return array_merge($folders, [DS]);
    }

    public function files_of($basename = false)
    {
        return $this->files($basename, $this->folder);
    }

    public function files($basename = false, $folder = '')
    {
        $files = [];
        $regex = '*.log.php';
        $fullpath = $this->logdir;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullpath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if (! $item->isDir()
            && strtolower(pathinfo($item->getRealPath(), PATHINFO_EXTENSION)) === explode('.', $regex)[1]) {
                $files[] = $basename ? basename($item->getRealPath()) : $item->getRealPath();
            }
        }

        arsort($files);

        return array_values($files);
    }

    public function logdir()
    {
        return $this->logdir;
    }

    public static function tree($logdir, array $array)
    {
        foreach ($array as $k => $v) {
            if (is_dir($k)) {
                $items = explode(DS, $k);
                $show = Str::replace_last('.log.php', '', last($items));

                echo '<div class="list-group folder">
                    <a href="?f='.$this->encode($k).'">
                        <span class="fa fa-folder"></span> '.last(explode(DS, $show)).'
                    </a>
                </div>';

                if (is_array($v)) {
                    self::tree($logdir, $v);
                }
            } else {
                $items = explode(DS, $v);
                $show = Str::replace_last('.log.php', '', last($items));
                $folder = str_replace($logdir, '', rtrim(str_replace($show, '', $v), DS));
                $file = $v;

                echo '<div class="list-group">
                    <a href="?l='.$this->encode($file).'&f='.$this->encode($folder).'">
                        <span class="fa fa-file"></span> '.last(explode(DS, $show)).'
                    </a>
                </div>';
            }
        }
    }

    public function levels()
    {
        return array_keys($this->icons);
    }

    public function icon($level)
    {
        return $this->icons[$level];
    }

    public function css($level)
    {
        return $this->css[$level];
    }

    public function regex($regex, $index = null)
    {
        return is_null($index) ? $this->regexes[$regex] : $this->regexes[$regex][$index];
    }

    public function encode($value)
    {
        $value = urlencode($value);
        $len = mb_strlen($value, '8bit');
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord(substr($value, $i, 1)) + ord(substr(RAKIT_KEY, ($i % strlen(RAKIT_KEY)) - 1, 1)));
        }

        return base64_encode($out);
    }

    public function decode($value)
    {
        $value = base64_decode($value);
        $len = mb_strlen($value, '8bit');
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord(substr($value, $i, 1)) - ord(substr(RAKIT_KEY, ($i % strlen(RAKIT_KEY)) - 1, 1)));
        }

        return urldecode($out);
    }
}
