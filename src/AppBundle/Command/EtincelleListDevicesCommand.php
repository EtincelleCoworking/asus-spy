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
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'ASUS Router IP', 'router.asus.com')
            ->addOption('username', null, InputOption::VALUE_OPTIONAL, 'Login', 'admin')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Password', 'admin')
            ->addArgument('api', InputArgument::REQUIRED, 'API Endpoint to post detected devices');
    }

    protected function getDevicesFromAsusRouter($host, $login, $password, OutputInterface $output)
    {
        $client = new Client();
        $login_url = sprintf('http://%s/Main_Login.asp', $host);
        $output->writeln(sprintf('<info>Fetching login page %s</info>', $login_url));
        $client->followRedirects(true);
        $crawler = $client->request('GET', $login_url);
        $form = $crawler->filter('form')->first()->form();
        $params = array('login_username' => $login, 'login_passwd' => $password);
        $params['login_authorization'] = base64_encode(sprintf('%s:%s', $params['login_username'], $params['login_passwd']));
        $params['next_page'] = 'index.asp';
        $output->writeln('<info>Submitting login page</info>');
        $client->submit($form, $params);
        $result = array();
        if (preg_match('/error_status/', $client->getResponse()->getContent())) {
            $output->writeln(sprintf('<error>Access denied to %s (with username = %s, password = %s)</error>', $host, $login, $password));
        } else {
            $output->writeln('<info>Grabbing connected hosts</info>');
            $client->request('GET', sprintf('http://%s/update_networkmapd.asp', $host));
            if (preg_match("/fromNetworkmapd = '([^']+)'/", $client->getResponse()->getContent(), $tokens)) {
                $items = preg_split('/<[0-9]>/', $tokens[1]);
                //print_r($items);
                array_shift($items);
                foreach ($items as $item) {
                    if (preg_match('/^([^>]+)>([^>]+)>([^>]+)>([^>]+)>([^>]+)>([^>]+)>$/', $item, $tokens)) {
                        //print_r($tokens);
                        $devices[$tokens[3]] = $tokens[1];
                    }
                }
            }
            //print_r($devices);
            $client->request('GET', sprintf('http://%s/update_clients.asp', $host));
            //echo $client->getResponse()->getContent();
            foreach (array(2, 5) as $network) {
                if (preg_match('/wlListInfo_' . $network . 'g: \[(\["[^"]*", "[^"]*", "[^"]*", "[^"]*"\](, )?)*\]/s', $client->getResponse()->getContent(), $tokens)) {
                    if (preg_match_all('/\["([^"]*)", "([^"]*)", "([^"]*)", "([^"]*)"\]/s', $tokens[0], $token)) {
                        // print_r($token);
                        $output->writeln(sprintf('<info>' . $network . 'G: %s</info>', implode(', ', $token[1])));
                        foreach ($token[1] as $mac) {
                            $result[$mac] = array(
                                'name' => isset($devices[$mac]) ? $devices[$mac] : '',
                                'mac' => $mac,
                                'lastSeen' => date('c'),
                            );
                        }
                    }
                }
            }
            $output->writeln('<info>Disconnecting</info>');
            $client->request('GET', sprintf('http://%s/Logout.asp', $host));
        }
        return $result;
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
        return $result;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result_router = $this->getDevicesFromAsusRouter($input->getOption('host'), $input->getOption('username'), $input->getOption('password'), $output);
        print_r($result_router);
        $result_nmap = $this->getDevicesFromNmap($output);
        print_r($result_nmap);
        $result = array_merge($result_nmap, $result_router);
        print_r($result);

        $output->writeln(sprintf('<comment>%d devices found</comment>', count($result)));
        $api = $input->getArgument('api');
        if (count($result) && $api) {

            $client = new Client();
            $client->request('POST', $api,
                array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($result));

            if ($client->getResponse()->getContent() == 'OK') {
                $output->writeln('<info>Devices sent to intranet successfully</info>');
            }
        }
    }

    protected function getIPs()
    {
        preg_match_all('/inet adr:([^ ]+)/m', `ifconfig`, $ips);
        return $ips[1];
    }
}


