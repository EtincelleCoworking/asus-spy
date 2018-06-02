<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Goutte\Client;

class EtincelleListDevicesOpnSenseCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('etincelle:list-devices-opn-sense')
            ->addOption('firewall_ip', null, InputOption::VALUE_OPTIONAL, 'Firewall IP', '')
            ->addOption('opnsense_api_key', null, InputOption::VALUE_OPTIONAL, 'Login', '')
            ->addOption('opnsense_api_secret', null, InputOption::VALUE_OPTIONAL, 'Password', '')
            ->addArgument('api', InputArgument::REQUIRED, 'API Endpoint to post detected devices');
    }

    protected function getDevicesFromOpnSense($host, $key, $secret, OutputInterface $output)
    {
        $curl = curl_init();
        $url = "http://$host/api/captiveportal/session/list";
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, $key . ':' . $secret);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($curl);

        curl_close($curl);

        $devices = array();
        foreach (json_decode($result) as $item) {
            $devices[$item->macAddress] = array(
                'email' => $item->userName,
                'name' => '',
                'brand' => '',
                'mac' => $item->macAddress,
                'ip' => $item->ipAddress,
                'lastSeen' => date('c', $item->last_accessed),
            );
        }


        return $devices;
    }

    protected function getDevicesFromNmap(OutputInterface $output)
    {
        $range = null;

        foreach ($this->getIPs() as $ip) {
            if ($ip != '127.0.0.1') {
                $ipParts = explode('.', $ip);
                array_pop($ipParts);
                $range = implode('.', $ipParts) . '.0/24';
            }
        }
        if (!$range) {
            $output->writeln('<error>Unable to find network IP</error>');
            return false;
        }
        $result = array();
        $output->writeln(sprintf('<info>Inspecting %s</info>', $range));
        exec('nmap -sP ' . $range, $nmap);
        print_r($nmap);
        while (count($nmap) > 0) {
            $line = array_shift($nmap);
            if (preg_match('/Nmap scan report for (.+)( \(([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\))?/', $line, $tokens)) {
                if (isset($tokens[2])) {
                    $ip = $tokens[2];
                    $name = $tokens[1];
                } else {
                    $ip = $tokens[1];
                    $name = '';
                }
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
                    } else {
                        $output->writeln(sprintf('<info>No match for %s</info>', $line));
                    }
                } else {
                    $output->writeln(sprintf('<info>No match for %s</info>', $line));
                }
            } else {
                $output->writeln(sprintf('<info>No match for %s</info>', $line));
            }
        }
        return $result;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = $this->getDevicesFromNmap($output);
        //print_r($result);

        $opnsense_data = $this->getDevicesFromOpnSense($input->getOption('firewall_ip'), $input->getOption('opnsense_api_key'), $input->getOption('opnsense_api_secret'), $output);
        print_r($opnsense_data);
        foreach ($opnsense_data as $mac => $item) {
            $mac = strtoupper($mac);
            if (isset($result[$mac])) {
                $resulwt[$mac]['email'] = $item['email'];
            }
        }

        //print_r($result);
        $output->writeln(sprintf('<comment>%d devices found</comment>', count($result)));
        $api = $input->getArgument('api');
        if (count($result) && $api) {

            $client = new Client();
            $client->request('POST', $api,
                array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($result));

            if ($client->getResponse()->getContent() == 'OK') {
                $output->writeln('<info>Devices sent to intranet successfully</info>');
            } else {
                $output->writeln($client->getResponse()->getContent());
            }
        }
    }


    protected function getIPs()
    {
        if (preg_match_all('/inet add?r:([^ ]+)/m', `ifconfig`, $ips)) {

            return $ips[1];
        }
        if (preg_match_all('/inet (192\.[^ ]+)/m', `ifconfig`, $ips)) {
            return $ips[1];
        }
    }
}


