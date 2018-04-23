<?php

namespace AppBundle\Agent;

class Ping extends Agent
{
    function fire()
    {
        exec(sprintf('ping -c1 -W1 %s', $this->getParameter('ip')), $output, $return_var);
        if($return_var === 0){
            return array();
        }

        return false;
    }
}