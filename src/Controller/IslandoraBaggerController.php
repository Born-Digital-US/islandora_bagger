<?php
// src/Controller/IslandoraBaggerController.php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\IslandoraBagger;

use Psr\Log\LoggerInterface;

// For now, until we figure out how to pass in config data.
use Symfony\Component\Yaml\Yaml;

class IslandoraBaggerController extends AbstractController
{
    public function create(Request $request, LoggerInterface $logger)
    {
        $this->application_directory = dirname(__DIR__, 2);

        $nid = $request->headers->get('Islandora-Node-ID');

        // Get POSTed YAML from request body.
        $body = $request->getContent();
        $yaml_path = $this->application_directory . '/var/islandora_bagger.' . $nid . '.yml';
        file_put_contents($yaml_path, $body);

        // If we create the Bag here, we risk timeouts. Add request to the queue.
        // @todo: If this method fails (returned false), log that.
        $this->writeToQueue($nid, $yaml_path);

        // @todo: what do we want in the response data?
        $data = array(
            'Entry for node ' . $nid . ' using configuration at ' . $yaml_path . ' added to queue.'
        );
        $response = new JsonResponse($data);
        return $response;
    }

    private function writeToQueue($nid, $yaml_path)
    {
        // Write the request to the queue.
        $fp = fopen($this->application_directory . '/var/islandora_bagger.queue', "a+");
        if (flock($fp, LOCK_EX)) {
            // nid\tpath_to_yaml\n
            fwrite($fp, "$nid\t$yaml_path\n");
            fflush($fp);
            flock($fp, LOCK_UN);
            return true;
        }
        fclose($fp);
    }
}
