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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $host = $input->getOption('host');
        $login = $input->getOption('username');
        $password = $input->getOption('password');
        $api = $input->getArgument('api');
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

//        var_dump($client->getResponse());
//        var_dump($client->getResponse()->getContent());

        if (preg_match('/error_status/', $client->getResponse()->getContent())) {
            $output->writeln(sprintf('<error>Access denied to %s (with username = %s, password = %s)</error>', $host, $login, $password));
        } else {
            $devices = array();
            $result = array();
            $output->writeln('<info>Grabbing connected hosts</info>');
            $client->request('GET', sprintf('http://%s/update_networkmapd.asp', $host));
            if (preg_match("/^﻿fromNetworkmapd = '([^']+)'/", $client->getResponse()->getContent(), $tokens)) {
                $items = explode('<0>', $tokens[1]);
                array_shift($items);
                foreach ($items as $item) {
                    if (preg_match('/^([^>]+)>([^>]+)>([^>]+)>([^>]+)>([^>]+)>([^>]+)>$/', $item, $tokens)) {
                        //      print_r($tokens);
                        $devices[$tokens[3]] = array(
                            'name' => $tokens[1],
//                        'ip' => $tokens[2],
                            'mac' => $tokens[3],
                            'lastSeen' => date('c'),
                        );
                    }
                }
            }
           // print_r($devices);
//            $client->request('GET', sprintf('http://%s/update_clients.asp', $host));
//            if (preg_match('/wlListInfo_2g: \[(\[".*", ".*", ".*", ".*"\](, )?)*\]/s', $client->getResponse()->getContent(), $tokens)) {
//                if (preg_match_all('/\["([^"]*)", "([^"]*)", "([^"]*)", "([^"]*)"\]/s', $tokens[1], $token)) {
//                    foreach ($devices as $mac => $device) {
//                        if (in_array($mac, $token[1])) {
//                            $result[] = $device;
//                        }
//                    }
//                }
//            }
//
            $result = array_values($devices);
//            print_r($result);


            $output->writeln(sprintf('<comment>%d devices found</comment>', count($result)));
            if (count($result) && $api) {

                $client->request('POST', $api,
                    array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($result));

                if ($client->getResponse()->getContent() == 'OK') {
                    $output->writeln('<info>Devices sent to intranet successfully</info>');
                }
            }
        }

    }

}
