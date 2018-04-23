<?php

namespace AppBundle\Agent;

abstract class Agent
{
    function __construct($params)
    {
        $this->setParameters($params);
    }

    protected $params;

    protected function setParameters($params)
    {
        $this->params = $params;
    }

    protected function getParameter($name, $default = null)
    {
        return isset($this->params[$name]) ? $this->params[$name] : $default;
    }

    /**
     * @return mixed FALSE => equipment is offline
     *               array() => equipment is online
     *               array(...) => equipment is online and custom data is returned
     */
    abstract function fire();
}