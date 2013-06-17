<?php

/**
 * This is only an example file showing how to extend the default AppLoader
 * You don't need to copy it
 */

use WMC\AppLoader\AppLoader as BaseLoader;

class AppLoader extends BaseLoader
{
    protected function processOptions()
    {
        if (empty($this->options['some_option'])) {
            $this->options['some_option'] = 'some_value';
        }

        parent::processOptions();
    }

    protected function beforeKernel()
    {
        $this->enforceSomeValue();

        parent::beforeKernel();
    }

    protected function enforceSomeValue()
    {
        if ($this->options['some_option']) {
            // Some action
        }
    }
}