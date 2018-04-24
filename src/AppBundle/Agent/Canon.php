<?php

namespace AppBundle\Agent;

use Goutte\Client;

class Canon extends Agent
{
    protected function getInkStatus($ip)
    {
        $client = new Client();
        $login_url = sprintf('http://%s/login.html', $ip);
        //$output->writeln(sprintf('<info>Fetching login page %s</info>', $login_url));
        $client->followRedirects(true);
        $crawler = $client->request('GET', $login_url);
        $form = $crawler->filter('form')->first()->form();
        $params = array('i0012' => 1);
        $client->submit($form, $params);

        $client->request('GET', sprintf('http://%s/portal_top.html', $ip));

        $result = array();
        if (preg_match_all('/<div class="tonerRemain"><div class="Remaining(\d+)" id="([a-z]+)">/', $client->getResponse()->getContent(), $tokens)) {
            foreach ($tokens[2] as $index => $color) {
                $result[$color] = $tokens[1][$index];
            }
        }
        return $result;
    }

    function fire()
    {
        return $this->getInkStatus($this->getParameter('ip'));
    }
}