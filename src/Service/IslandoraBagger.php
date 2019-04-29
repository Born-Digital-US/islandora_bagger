<?php
// src/Service/IslandoraBagger.php
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require 'vendor/scholarslab/bagit/lib/bagit.php';

class IslandoraBagger
{
    private $params;

    public function __construct($settings, $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function createBag($nid)
    {
        // Set some configuration defaults.
        $this->settings['http_timeout'] = (!isset($this->settings['http_timeout'])) ?
            60 : $this->settings['http_timeout'];
        $this->settings['verify_ca'] = (!isset($this->settings['verify_ca'])) ?
            true : $this->settings['verify_ca'];
        $this->settings['hash_algorithm'] = (!isset($this->settings['hash_algorithm'])) ?
            'sha1' : $this->settings['hash_algorithm'];

        if (!file_exists($this->settings['output_dir'])) {
            mkdir($this->settings['output_dir']);
        }
        if (!file_exists($this->settings['temp_dir'])) {
            mkdir($this->settings['temp_dir']);
        }

        $client = new \GuzzleHttp\Client();

        // Get the node's UUID from Drupal.
        $drupal_url = $this->settings['drupal_base_url'] . '/node/' . $nid . '?_format=json';
        $response = $client->get($drupal_url);
        $response_body = (string) $response->getBody();
        $node_json = $response_body;
        $body_array = json_decode($response_body, true);
        $uuid = $body_array['uuid'][0]['value'];

        if ($this->settings['bag_name'] == 'uuid') {
            $bag_name = $uuid;
        } else {
            $bag_name = $nid;
        }

        // Create directories.
        $bag_dir = $this->settings['output_dir'] . DIRECTORY_SEPARATOR . $bag_name;
        if (!file_exists($bag_dir)) {
            mkdir($bag_dir);
        }
        $bag_temp_dir = $this->settings['temp_dir'] . DIRECTORY_SEPARATOR . $bag_name;
        if (!file_exists($bag_temp_dir)) {
            mkdir($bag_temp_dir);
        }

        // Create the Bag.
        $bag_info = array();
        $bag = new \BagIt($bag_dir, true, true, true, $bag_info);
        $bag->setHashEncoding($this->settings['hash_algorithm']);

        // Add tags registered in the config file.
        foreach ($this->settings['bag-info'] as $key => $value) {
            $bag->setBagInfoData($key, $value);
        }

        // Execute registered plugins.
        foreach ($this->settings['plugins'] as $plugin) {
            $plugin_name = 'App\Plugin\\' . $plugin;
            $bag_plugin = new $plugin_name($this->settings, $this->logger);
            $bag = $bag_plugin->execute($bag, $bag_temp_dir, $nid, $node_json);
        }

        $bag->update();
        $this->removeDir($bag_temp_dir);

        $package = isset($this->settings['serialize']) ? $this->settings['serialize'] : false;
        if ($package) {
            $bag->package($bag_dir, $package);
            $this->removeDir($bag_dir);
            $bag_name = $bag_name . '.' . $package;
        }

        if ($this->settings['log_bag_creation']) {
            $this->logger->info(
                "Bag created.",
                array(
                    'node URL' => $this->settings['drupal_base_url'] . '/node/' . $nid,
                    'node UUID' => $uuid,
                    'Bag location' => $this->settings['output_dir'],
                    'Bag name' => $bag_name
                )
            );
        }

        // @todo: Return Bag directory path on success or false failure to command here.
        return $bag_dir;
    }

    /**
     * Deletes a directory and all of its contents.
     *
     * @param $dir string
     *   Path to the directory.
     *
     * @return bool
     *   True if the directory was deleted, false if not.
     *
     */
    protected function removeDir($dir)
    {
        // @todo: Add list here of invalid $dir values, e.g., /, /tmp.
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}
