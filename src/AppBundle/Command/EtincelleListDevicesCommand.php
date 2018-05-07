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
        $devices = array();
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
                        $devices[strtolower($tokens[3])] = array(
                            'name' => $tokens[1],
                            'brand' => '',
                            'mac' => $tokens[3],
                            'ip' => $tokens[2],
                            'lastSeen' => date('c'),
                        );
                    }
                }
            }
//            print_r($devices);
//            foreach ($devices as $mac => $device) {
//                $cmd_result = array();
//                $cmd = sprintf('arp -an "%s"', $device['ip']);
//                $output->writeln(sprintf('CMD: %s', $cmd));
//
//                exec($cmd, $cmd_result, $cmd_status);
//
//                $cmd_result = array_values(array_filter($cmd_result));
//                $cmd_result = implode($cmd_result, '');
//                // If the result line in the output is not empty, parse it.
//                if ($cmd_result) {
//                    if (preg_match(sprintf("/\\(%s\\) at ([a-e0-9]{2}(:[a-e0-9]{2}){5})/", $device['ip']), $cmd_result, $matches)) {
//                        if(strtolower($matches[1]) == strtolower($mac)){
//                            $cmd_result = array();
//                            $cmd = sprintf('ping -c 1 -W 1 "%s"', $device['ip']);
//                            exec($cmd, $cmd_result, $cmd_status);
//
//                            $is_live = false;
//
//                            $cmd_result = array_values(array_filter($cmd_result));
//                            $cmd_result = implode($cmd_result, '');
//                            // If the result line in the output is not empty, parse it.
//                            if ($cmd_result) {
//                                // Search for a 'time' value in the result line.
//                                if (preg_match("/1 packets transmitted, 1 received/", $cmd_result, $matches)) {
//                                    $is_live = true;
//                                }
//                            }
//
//
//                            if (!$is_live) {
//                                $output->writeln(sprintf('Host %s is dead', $device['ip']));
//                                unset($devices[$mac]);
//                            } else {
//                                $output->writeln(sprintf('Host %s is live', $device['ip']));
//                            }
//                        }else{
//                            $output->writeln(sprintf('IP/Mac mismatch (%s / %s) for IP: %s', strtolower($matches[1]), strtolower($mac), $device['ip']));
//                            unset($devices[$mac]);
//                        }
//                    }else{
//                        $output->writeln(sprintf('Unable to find Mac address for IP: %s - %s', $device['ip'], $cmd_result));
//                        unset($devices[$mac]);
//                    }
//                    // If there's a result and it's greater than 0, return the latency.
//                }
//
//
//            }
            /*
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
            */
            $output->writeln('<info>Disconnecting</info>');
            $client->request('GET', sprintf('http://%s/Logout.asp', $host));
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
//        $result_asus = $this->getDevicesFromAsusRouter($input->getOption('host'), $input->getOption('username'), $input->getOption('password'), $output);
//        print_r($result_asus);
//        $result = array();
//                $output->writeln(sprintf('CMD: %s', $cmd));
//
//        exec('arp-scan -l', $cmd_result, $cmd_status);
//        print_r($cmd_result);
//        foreach ($cmd_result as $line) {
//            if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f])/', $line, $tokens)) {
//                $_ip = $tokens[1];
//                $_mac = $tokens[2];
//                if (isset($result_asus[$_mac])) {
//                    $result[$_mac] = $result_asus[$_mac];
//                } else {
//                    $result[$_mac] = array(
//                        'name' => '',
//                        'brand' => '',
//                        'mac' => $_mac,
//                        'ip' => $_ip,
//                        'lastSeen' => date('c'),
//                    );
//                }
//            }
//        }

//        $result_nmap = $this->getDevicesFromNmap($output);
//        print_r($result_nmap);
//        $result = $result_asus;
//        foreach ($result_nmap as $mac => $data) {
//            foreach ($data as $k => $v) {
//                $result[$mac][$k] = $v;
//            }
//        }
        $result = $this->getDevicesFromNmap($output);
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
        if(preg_match_all('/inet add?r:([^ ]+)/m', `ifconfig`, $ips)){

            return $ips[1];
        }
        if(preg_match_all('/inet (192\.[^ ]+)/m', `ifconfig`, $ips)){
            return $ips[1];
        }
    }
}


