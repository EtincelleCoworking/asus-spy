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

        $crawler = $client->request('GET', sprintf('http://%s/Main_Login.asp', $host));

        $form = $crawler->filter('form')->first()->form();
        $params = array('login_username' => $login, 'login_passwd' => $password);
        $params['login_authorization'] = base64_encode(sprintf('%s:%s', $params['login_username'], $params['login_passwd']));
        $params['next_page'] = 'index.asp';
        $client->submit($form, $params);
//        var_dump($client->getResponse());
//        var_dump($client->getResponse()->getContent());

        if (preg_match('/error_status/', $client->getResponse()->getContent())) {
            $output->writeln(sprintf('<error>Access denied to %s (with username = %s, password = %s)</error>', $host, $login, $password));
        } else {
            $devices = array();
            $client->request('GET', sprintf('http://%s/update_networkmapd.asp', $host));
            if (preg_match("/^﻿fromNetworkmapd = '([^']+)'/", $client->getResponse()->getContent(), $tokens)) {
                $items = explode('<0>', $tokens[1]);
                array_shift($items);
                foreach ($items as $item) {
                    if (preg_match('/^([^>]+)>([^>]+)>([^>]+)>([^>]+)>([^>]+)>([^>]+)>$/', $item, $tokens)) {
                        //      print_r($tokens);
                        $devices[] = array(
                            'name' => $tokens[1],
//                        'ip' => $tokens[2],
                            'mac' => $tokens[3],
                            'lastSeen' => date('c'),
                        );
                    }
                }
            }

            $output->writeln(sprintf('<comment>%d devices found</comment>', count($devices)));
            if (count(count($devices))) {

                $client->request('POST', $api,
                    array(), array(), array('HTTP_CONTENT_TYPE' => 'application/json'), json_encode($devices));

                if ($client->getResponse()->getContent() == 'OK') {
                    $output->writeln('<info>Devices sent to intranet successfully</info>');
                }
            }
        }

    }

}
