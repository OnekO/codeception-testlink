<?php
namespace OnekO\Codeception\TestLink\Action;

use OnekO\Codeception\TestLink\Connection;

interface ActionInterface
{

    /**
     * @param Connection $conn
     */
    public function setConnection(Connection $conn);

    /**
     * @return mixed
     */
    public function __invoke();
}
