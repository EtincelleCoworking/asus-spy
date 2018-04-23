<?php

namespace AppBundle\Agent;

class Oki extends Agent
{
    const  TIMEOUT_IN_SEC = 10;

    protected function getInkStatus($ip)
    {

        $ctx = stream_context_create(array('http' =>
            array(
                'timeout' => self::TIMEOUT_IN_SEC,
            )
        ));
        $content = @file_get_contents(sprintf('http://%s/status.htm', $ip), false, $ctx);
        if(false === $content){
            return false;
        }
        $result = array();
        if (preg_match_all('/<input type="hidden" name="AVAILABEL([A-Z]+)TONER" value="(\d+)">/', $content, $tokens)) {
            foreach ($tokens[1] as $index => $color) {
                $result[$color] = $tokens[2][$index];
            }
        }
        return $result;
    }

    function fire()
    {
        $result = $this->getInkStatus($this->getParameter('ip'));
        return $result;
    }
}