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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = $this->getDevicesFromOpnSense($input->getOption('firewall_ip'), $input->getOption('opnsense_api_key'), $input->getOption('opnsense_api_secret'), $output);
        print_r($result);

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
}


