<?php namespace std\filesTransfer\controllers\main;

class Remote extends \Controller
{
    public function getAppRoot()
    {
        return $this->app->root;
    }
}
