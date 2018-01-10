<?php
namespace OnekO\Codeception\TestLink;

use IXR\Client\Client;
use OnekO\Codeception\TestLink\Action\ActionInterface;
use OnekO\Codeception\TestLink\Exception\ActionNotFound;
use OnekO\Codeception\TestLink\Exception\CallException;

/**
 * Class Connection
 *
 * @package OnekO\Codeception\TestLink
 */
class Connection
{
    const API_PATH = '/lib/api/xmlrpc/v1/xmlrpc.php';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var ActionInterface[]
     */
    protected $actions;

    /** @var string */
    protected $apiKey;

    /**
     * @param string $apikey
     */
    public function setApiKey($apikey)
    {
        $this->apiKey = (string)$apikey;
    }

    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @param string $baseUri
     */
    public function connect($baseUri)
    {
        $this->setClient(
            new Client($baseUri . static::API_PATH)
        );
    }

    /**
     * @param Client $client
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param $method
     * @param $args
     * @return null|string
     */
    public function execute($method, $args)
    {
        var_dump($method, $args);
        $args['devKey'] = $this->getApiKey();
        $response = null;
        if ($this->client->query("tl.{$method}", $args)) {
            $response = $this->client->getResponse();
        }

        return $response;
    }

    /**
     * Magic caller which calls the relevant Action class
     *
     * @param string $name
     * @param array  $args
     */
    public function __call($name, array $args)
    {
        if (!isset($this->actions[$name])) {
            $action = __NAMESPACE__. '\\Action\\'. ucfirst($name);
            if (class_exists($action)) {
                $this->actions[$name] = new $action();
                $this->actions[$name]->setConnection($this);
            } else {
                throw new ActionNotFound(
                    sprintf(
                        '
                    The TestLink Connection couldn\'t locate an action class for "%s".',
                        $name
                    )
                );
            }
        }
        return call_user_func_array($this->actions[$name], $args);
    }
}
