<?php namespace std\filesTransfer\controllers;

class Main extends \Controller
{
    private $sourceEnv;

    private $targetEnv;

    public function __create()
    {
        if ($direction = $this->data('direction')) {
            if ($envs = $this->parseDirection($direction)) {
                list($sourceEnv, $targetEnv) = $envs;

                $this->sourceEnv = $sourceEnv;
                $this->targetEnv = $targetEnv;
            }
        } else {
            $sourceEnvName = $this->data('source'); // todo [app:]env
            $targetEnvName = $this->data('target'); // todo [app:]env

            if ($sourceEnv = \ewma\apps\models\Env::where('app_id', 0)->where('name', $sourceEnvName)->first()) {
                $this->sourceEnv = $sourceEnv;
            }

            if ($targetEnv = \ewma\apps\models\Env::where('app_id', 0)->where('name', $targetEnvName)->first()) {
                $this->targetEnv = $targetEnv;
            }
        }

        if (null === $this->sourceEnv) {
            $this->lock('not defined source');
        }

        if (null === $this->targetEnv) {
            $this->lock('not defined target');
        }
    }

    private function parseDirection($direction)
    {
        $exploded = explode('2', $direction);

        if (count($exploded) == 2) {
            $sourceEnvShortName = $exploded[0];
            $targetEnvShortName = $exploded[1];

            $sourceEnv = \ewma\apps\models\Env::where('app_id', 0)->where('short_name', $sourceEnvShortName)->first();
            $targetEnv = \ewma\apps\models\Env::where('app_id', 0)->where('short_name', $targetEnvShortName)->first();

            if ($sourceEnv && $targetEnv) {
                return [$sourceEnv, $targetEnv];
            }
        }
    }

    public function run()
    {
        start_time($this->_nodeId());

        $sourceEnv = $this->sourceEnv;
        $targetEnv = $this->targetEnv;

        $paths = l2a($this->data('paths'));
        diff($paths, '');

        $sourceRemote = remote($sourceEnv->name);
        $targetRemote = remote($targetEnv->name);

        if ($sourceRemote && $targetRemote && count($paths)) {
            $this->log($sourceEnv->name . ' -> ' . $targetEnv->name . ': ' . a2l($paths));

            //

            $currentEnv = \ewma\apps\models\Env::where('app_id', 0)->where('name', $this->_env())->first();
            $currentServer = $currentEnv->server;

            $sourceServer = $sourceEnv->server;
            $targetServer = $targetEnv->server;

            $errors = [];

            $sourcePathPrefix = '';
            $targetPathPrefix = '';

            if (!$sourceAppRoot = $this->getAppRoot($sourceRemote)) {
                $errors[] = 'has not source app root';
            }

            if (!$targetAppRoot = $this->getAppRoot($targetRemote)) {
                $errors[] = 'has not target app root';
            }

            $sourceIsRemote = $sourceServer != $currentServer;
            $targetIsRemote = $targetServer != $currentServer;

            if ($sourceServer == $targetServer) {
                if ($sourceIsRemote) { // R-R
                    $pattern = 'ssh {source_ssh} rsync -r {source_path} {target_path}';
                } else { // L-L
                    $pattern = 'rsync -r {source_path} {target_path}';
                }
            } else {
                if ($sourceIsRemote && $targetIsRemote) { // R1-R2
                    $pattern = 'ssh {source_ssh} rsync -rz {source_path} {target_ssh}:{target_path}';
                } else {
                    if ($sourceIsRemote) { // R-L
                        $pattern = 'rsync -rz {source_ssh}:{source_path} {target_path}';
                    } else { // L-R
                        $pattern = 'rsync -rz {source_path} {target_ssh}:{target_path}';
                    }
                }
            }

            $sourceSsh = '';
            if (false !== strpos($pattern, '{source_ssh}')) {
                if (!$sourceSsh = ewmas()->getSshConnectionString($sourceServer)) {
                    $errors[] = 'has not ssh connection to source server';
                }
            }

            $targetSsh = '';
            if (false !== strpos($pattern, '{target_ssh}')) {
                if (!$targetSsh = ewmas()->getSshConnectionString($targetServer)) {
                    $errors[] = 'has not ssh connection to source server';
                }
            }

            if (!$errors) {
                foreach ($paths as $path) {
                    $sourceAbsPath = '/' . path($sourceAppRoot, $path);
                    $targetAbsPath = '/' . path($targetAppRoot, path_slice($path, 0, -1));

                    $command = strtr($pattern, [
                        '{source_ssh}'  => $sourceSsh,
                        '{target_ssh}'  => $targetSsh,
                        '{source_path}' => $sourceAbsPath,
                        '{target_path}' => $targetAbsPath
                    ]);

                    if ($command) {
                        $this->log($command);
                        exec($command, $result);
                    }
                }
            }

            return [
                'errors'   => $errors,
                'source'   => $sourceEnv,
                'target'   => $targetEnv,
                'command'  => $command,
                'duration' => end_time($this->_nodeId(), true),
                'result'   => $result
            ];
        }
    }

    private function getAppRoot(\ewma\remoteCall\Remote $remote)
    {
        return $remote->call('\std\filesTransfer~remote:getAppRoot');
    }
}
