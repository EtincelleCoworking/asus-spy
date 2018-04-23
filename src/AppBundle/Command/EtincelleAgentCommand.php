<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EtincelleAgentCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('etincelle:agent')
            ->addArgument('host', InputArgument::REQUIRED, 'Intranet Hostname')
            ->addArgument('location', InputArgument::REQUIRED, 'Location')
            ->addArgument('key', InputArgument::REQUIRED, 'Location key');
    }

    protected function getAgents(InputInterface $input)
    {
        $url = sprintf('https://%s/api/1.0/monitoring/%s/%s/agents',
            $input->getArgument('host'), $input->getArgument('location'), $input->getArgument('key'));
        //printf("URL: %s\n", $url);
        $ch = curl_init();
        //curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec($ch);

        curl_close($ch);

        return json_decode($server_output, true);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $agents = $this->getAgents($input);

        if (null === $agents) {
            $output->writeln(sprintf('<error>Unable to get list of agents from %s', $input->getArgument('host')));
            return false;
        }
        foreach ($agents as $ip => $className) {
            $className = "AppBundle\\Agent\\$className";
            $agent = new $className(array('ip' => $ip));
            $result = $agent->fire();
            $output->writeln(sprintf("IP: %15s - %s", $ip, ($result === false) ? '<error>OFFLINE</error>' : '<info>ONLINE</info>'));
            if ($result === false) {
                unset($agents[$ip]);
            } else {
                $agents[$ip] = $result;
            }
        }
        $output->writeln('');
        if(count($agents) == 0){
            $output->writeln('Nothing to upload');
            return true;
        }
        if (!$this->upload($input, $agents)) {
            $output->writeln(sprintf('<error>An error occured when uploading data to %s</error>', $input->getArgument('host')));
            return false;
        }
        $output->writeln(sprintf('<info>Upload Success to %s</info>', $input->getArgument('host')));

        return true;
    }

    protected function upload(InputInterface $input, $agents)
    {
        $data_string = json_encode($agents);
        $ch = curl_init();
        //curl_setopt($ch, CURLOPT_VERBOSE, true);
        $url = sprintf('https://%s/api/1.0/monitoring/%s/%s/agents',
            $input->getArgument('host'), $input->getArgument('location'), $input->getArgument('key'));
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string),
        ));
        $server_output = curl_exec($ch);
        curl_close($ch);

        return $server_output == count($agents);
    }
}


