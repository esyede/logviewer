<?php

defined('DS') or exit('No direct script access.');

class Logviewer_Log_Controller extends Controller
{
    private $viewer;

    public function __construct()
    {
        // Proteksi route dengan middleware
        $middlewares = Config::get('logviewer::main.middleware');
        $middlewares = array_merge($middlewares, ['auth']);
        // $this->middleware('before', $middlewares);

        $this->viewer = new \Esyede\Viewer();
    }

    public function action_index()
    {
        $folder_files = [];

        if (Input::get('f')) {
            $this->viewer->setFolder(base64_decode(Input::get('f')));
            $folder_files = $this->viewer->getFolderFiles(true);
        }
        if (Input::get('l')) {
            $this->viewer->setFile(base64_decode(Input::get('l')));
        }

        if (false !== ($halt = $this->halt())) {
            return $halt;
        }

        $data = [
            'logs' => $this->viewer->all(),
            'folders' => $this->viewer->getFolders(),
            'current_folder' => $this->viewer->getFolderName(),
            'folder_files' => $folder_files,
            'files' => $this->viewer->getFiles(true),
            'current_file' => $this->viewer->getFileName(),
            'standard' => true,
            'structure' => $this->viewer->foldersAndFiles(),
            'log_dir' => $this->viewer->getLogDir(),

        ];

        if (Request::wants_json()) {
            return Response::json($data);
        }

        if (is_array($data['logs']) && count($data['logs']) > 0) {
            $first = reset($data['logs']);
            if ($first) {
                if (! $first['context'] && ! $first['level']) {
                    $data['standard'] = false;
                }
            }
        }

        return View::make('logviewer::log', $data);
    }

    private function halt()
    {
        if (Input::get('f')) {
            $this->viewer->setFolder(base64_decode(Input::get('f')));
        }

        if (Input::get('dl')) {
            return Response::download($this->realpath('dl'));
        } elseif (Input::has('clean')) {
            Storage::put($this->realpath('clean'), '');
            return Redirect::to(Request::referrer());
        } elseif (Input::has('del')) {
            Storage::delete($this->realpath('del'));
            return Redirect::to(Request::uri());
        } elseif (Input::has('delall')) {
            $files = ($this->viewer->getFolderName())
                ? $this->viewer->getFolderFiles(true)
                : $this->viewer->getFiles(true);

            foreach ($files as $file) {
                Storage::delete($this->viewer->pathToLogFile($file));
            }

            return Redirect::to(Request::uri());
        }

        return false;
    }

    private function realpath($param)
    {
        return $this->viewer->pathToLogFile(base64_decode(Input::get($param)));
    }
}
