<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Goutte\Client;


class EtincelleListDevicesCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('etincelle:list-devices')
            ->addArgument('api', InputArgument::REQUIRED, 'API Endpoint to post detected devices');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $api = $input->getArgument('api');

        $result = array();

        foreach (getIPs() as $ip) {
            if ($ip != '127.0.0.1') {
                $ipParts = explode('.', $ip);
                array_pop($ipParts);
                $range = implode('.', $ipParts) . '.0/24';
            }
        }

        $output->writeln(sprintf('<info>Inspecting %s</info>', $range));
        exec('nmap -sP ' . $range, $nmap);
        while (count($nmap) > 0) {
            $line = array_shift($nmap);
            if (preg_match('/Nmap scan report for (.+) \(([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\)/', $line, $tokens)) {
                $ip = $tokens[2];
                $name = $tokens[1];
                $line = array_shift($nmap);
                if (preg_match('/Host is up \(.+s latency\)\./', $line, $tokens)) {
                    $line = array_shift($nmap);
                    if (preg_match('/MAC Address: ([A-Z0-9]{2}:[A-Z0-9]{2}:[A-Z0-9]{2}:[A-Z0-9]{2}:[A-Z0-9]{2}:[A-Z0-9]{2}) \((.+)\)/', $line, $tokens)) {
                        $mac = $tokens[1];
                        $brand = $tokens[2];
                        // var_dump(array($ip, $name, $mac, $brand));

                        $result[$mac] = array(
                            'name' => $name,
                            'brand' => $brand,
                            'mac' => $mac,
                            'ip' => $ip,
                            'lastSeen' => date('c'),
                        );
                    }
                }
            }
        }


        print_r($result);

        $output->writeln(sprintf('<comment>%d devices found</comment>', count($result)));
        if (count($result) && $api) {

            $client = new Client();
            $client->request('POST', $api,
                array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($result));

            if ($client->getResponse()->getContent() == 'OK') {
                $output->writeln('<info>Devices sent to intranet successfully</info>');
            }
        }
    }

}

function getIPs()
{
    preg_match_all('/inet adr:([^ ]+)/m', `ifconfig`, $ips);
    return $ips[1];
}
