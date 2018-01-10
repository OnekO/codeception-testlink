<?php
namespace OnekO\Codeception\TestLink;

use Codeception\Event\FailEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Exception\ExtensionException;
use Codeception\Extension as CodeceptionExtension;
use Codeception\Test\Cest;
use Codeception\Test\Test;
use Codeception\TestInterface;
use Codeception\Util\Annotation;

class Extension extends CodeceptionExtension
{
    const ANNOTATION_SUITE = 'tl-suite';
    const ANNOTATION_SUITE_PREFIX = 'tl-suite-prefix';
    const ANNOTATION_CASE  = 'tl-case';
    const ANNOTATION_SUMMARY  = 'tl-summary';
    const ANNOTATION_PRECONDITIONS  = 'tl-preconditions';
    const ANNOTATION_IMPORTANCE  = 'tl-importance';
    const ANNOTATION_EXECUTION_TYPE  = 'tl-execution-type';
    const ANNOTATION_ORDER  = 'tl-order';

    const STATUS_SUCCESS    = 'success';
    const STATUS_SKIPPED    = 'skipped';
    const STATUS_INCOMPLETE = 'incomplete';
    const STATUS_FAILED     = 'failed';
    const STATUS_ERROR      = 'error';

    const TESTRAIL_STATUS_SUCCESS = 1;
    const TESTRAIL_STATUS_FAILED = 5;
    const TESTRAIL_STATUS_UNTESTED = 3;
    const TESTRAIL_STATUS_RETEST = 4;
    const TESTRAIL_STATUS_BLOCKED = 2;

    public static $events = [
        Events::SUITE_AFTER     => 'afterSuite',

        Events::TEST_SUCCESS    => 'success',
        Events::TEST_SKIPPED    => 'skipped',
        Events::TEST_INCOMPLETE => 'incomplete',
        Events::TEST_FAIL       => 'failed',
        Events::TEST_ERROR      => 'errored',
    ];

    /**
     * @var Connection
     */
    protected $conn;

    /**
     * @var int
     */
    protected $project;

    /**
     * @var int
     */
    protected $plan;

    /**
     * @var array
     */
    protected $results = [];

    /**
     * @var array
     */
    protected $config = [ 'enabled' => true ];

    /**
     * @var array
     */
    protected $statuses = [
        self::STATUS_SUCCESS    => self::TESTRAIL_STATUS_SUCCESS,
        self::STATUS_SKIPPED    => self::TESTRAIL_STATUS_UNTESTED,
        self::STATUS_INCOMPLETE => self::TESTRAIL_STATUS_SUCCESS,
        self::STATUS_FAILED     => self::TESTRAIL_STATUS_FAILED,
        self::STATUS_ERROR      => self::TESTRAIL_STATUS_FAILED,
    ];

    /**
     * @throws ExtensionException
     */
    public function _initialize()
    {
        // we only care to do these things if the extension is enabled
        if ($this->config['enabled']) {
            $conn = $this->getConnection();
            $project = $conn->execute('getTestProjectByName', ['testprojectname' => $this->config['project']]);
            if ($project === null) {
                $currentPlan = $conn->execute(
                    'createTestProject',
                    [
                        'testprojectname' => $this->config['project'],
                        'testcaseprefix' => $this->getPrefix($this->config['project']),
                        'notes' => 'Created via Codeception Extension'
                    ]
                );
            }
            if ($project['active'] === 0) {
                throw new ExtensionException(
                    $this,
                    'TestLink project id passed in the config is not active'
                );
            }

            $this->project = $project;
        }

        // merge the statuses from the config over the default ones
        if (array_key_exists('status', $this->config)) {
            $this->statuses = array_merge($this->statuses, $this->config['status']);
        }
    }

    public function afterSuite(SuiteEvent $event)
    {
        $recorded = $this->getResults();
        var_dump($recorded);
        // skip action if we don't have results or the Extension is disabled
        if (empty($recorded) || !$this->config['enabled']) {
            return;
        }

        foreach ($recorded as $suiteId => $results) {
            $suite = $this->getConnection()->execute('getTestSuite', [
                'testsuitename' => $suiteId,
                'prefix' => $this->project['prefix']
            ]);
            if ($suite === null) {
                $suite = $this->getConnection()->execute(
                    'createTestSuite',
                    [
                        'testprojectname' => $this->config['project'],
                        'testsuitename' => $suiteId,
                        'prefix' => $this->project['prefix'],
                        'details' => 'Created via Codeception Extension',
                        'checkduplicatedname' => 1,
                        'actiononduplicatedname' => 'generate_new',
                    ]
                );
            }
            if ($suite !== null) {
                $suite = current($suite);
            }
            var_dump($suite);
            $cases = [];
            $result = $this->getConnection()->execute('getTestCasesForTestSuite', [
                'testprojectid' => $this->project['id'],
                'testsuiteid' => $suite['id'],
                'deep' => true
            ]);
            if (is_array($result)) {
                $cases = $result;
            }
            var_dump($cases);
            foreach ($results as $testResult) {
                var_dump($testResult);
                $caseExists = false;
                foreach ($cases as $case) {
                    if ($case['name'] === $testResult['name']) {
                        $caseExists = true;
                    }
                }

                if ($caseExists === false) {
                    $params = [
                        'testcasename' => $testResult['name'],
                        'testsuiteid' => $suite['id'],
                        'testprojectid' => $this->project['id'],
                        'authorlogin' => $this->config['author'],
                        'summary' => $testResult['summary'] !== null ? $testResult['summary'] : '',
                        'preconditions' => $testResult['preconditions'] !== null ? $testResult['preconditions'] : '',
                        'importance' => $testResult['importance'] !== null ? $testResult['importance'] : '',
                        'executionType' => $testResult['executionType'] !== null ? $testResult['executionType'] : '',
                        'order' => $testResult['order'] !== null ? $testResult['order'] : '',
                        'steps' => [],
                        'estimatedexecduration' => $testResult['elapsed']
                    ];
                    var_dump('Creo nuevo case', $params);
                    $resultNewCase =  $this->getConnection()->execute('createTestCase', $params);
                    var_dump($resultNewCase);
                }
            }
        }
    }

    /**
     * @param TestEvent $event
     */
    public function success(TestEvent $event)
    {
        $this->handleResult(
            $this->statuses[$this::STATUS_SUCCESS],
            $event
        );
    }

    /**
     * @param TestEvent $event
     */
    public function skipped(TestEvent $event)
    {
        $this->handleResult(
            $this->statuses[$this::STATUS_SKIPPED],
            $event
        );
    }

    /**
     * @param TestEvent $event
     */
    public function incomplete(TestEvent $event)
    {
        $this->handleResult(
            $this->statuses[$this::STATUS_INCOMPLETE],
            $event
        );
    }

    /**
     * @param FailEvent $event
     */
    public function failed(FailEvent $event)
    {
        $this->handleResult(
            $this->statuses[$this::STATUS_FAILED],
            $event
        );
    }

    /**
     * @param FailEvent $event
     */
    public function errored(FailEvent $event)
    {
        $this->handleResult(
            $this->statuses[$this::STATUS_ERROR],
            $event
        );
    }

    /**
     * @return array
     */
    public function getResults()
    {
        return $this->results;
    }

    /**
     * @return Connection
     */
    public function getConnection()
    {
        if (!$this->conn) {
            $newConn = new Connection();
            $newConn->setApiKey($this->config['apikey']);
            $newConn->connect($this->config['url']);
            $this->conn = $newConn;
        }
        return $this->conn;
    }

    /**
     * Inject an overlay config.  mostly useful for unit testing
     *
     * @param array $config
     */
    public function injectConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * @param int   $case   TestLink Case ID
     * @param int   $status TestLink Status ID
     * @param array $other  Array of other elements to add to the result (comments, elapsed, etc)
     */
    public function handleResult($status, TestEvent $event)
    {
        $test = $event->getTest();
        if (!$test instanceof Cest) {
            return;
        }

        $case = $this->getCaseForTest($test);
        if ($case) {
            $result = [
                'name' => $case,
                'summary' => $this->getSummaryForTest($test),
                'preconditions' => $this->getPreconditionsForTest($test),
                'importance' => $this->getImportanceForTest($test),
                'executionType' => $this->getExecutionTypeForTest($test),
                'order' => $this->getOrderForTest($test),
                'status_id' => $status,
            ];

            $result['elapsed'] = $this->formatTime($event->getTime());

            $this->results[$this->getSuiteForTest($test)][] = $result;
        }
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getSuiteForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forClass($test->getTestClass())->fetch($this::ANNOTATION_SUITE);
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getSuitePrefixForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forClass($test->getTestClass())->fetch($this::ANNOTATION_SUITE_PREFIX);
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getCaseForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forMethod($test->getTestClass(), $test->getTestMethod())->fetch($this::ANNOTATION_CASE);
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getSummaryForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forMethod($test->getTestClass(), $test->getTestMethod())->fetch($this::ANNOTATION_SUMMARY);
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getPreconditionsForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forMethod($test->getTestClass(), $test->getTestMethod())->fetch($this::ANNOTATION_PRECONDITIONS);
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getImportanceForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forMethod($test->getTestClass(), $test->getTestMethod())->fetch($this::ANNOTATION_IMPORTANCE);
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getExecutionTypeForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forMethod($test->getTestClass(), $test->getTestMethod())->fetch($this::ANNOTATION_EXECUTION_TYPE);
    }

    /**
     * @param TestInterface $test
     *
     * @return int|null
     *
     * @codeCoverageIgnore
     */
    public function getOrderForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        return Annotation::forMethod($test->getTestClass(), $test->getTestMethod())->fetch($this::ANNOTATION_ORDER);
    }

    /**
     * Formats a float seconds to a format that TestLink recognizes.  Will parse to hours, minutes, and seconds.
     *
     * @param float $time
     *
     * @return string
     */
    public function formatTime($time)
    {
        // TestLink doesn't support subsecond times
        if ($time < 1.0) {
            return '0s';
        }

        $formatted = '';
        $intTime = round($time);
        $intervals = [
            'h' => 3600,
            'm' => 60,
            's' => 1,
        ];

        foreach ($intervals as $suffix => $divisor) {
            if ($divisor > $intTime) {
                continue;
            }

            $amount = floor($intTime / $divisor);
            $intTime -= $amount * $divisor;
            $formatted .= $amount.$suffix.' ';
        }

        return trim($formatted);
    }

    protected function getPrefix($str)
    {
        $str = strtoupper($str);
        $tmp = explode(' ', $str);
        if (count($tmp) < 3) {
            return substr($str, 0, 3);
        }
        $prefix = '';
        foreach (array_slice($tmp, 0,3) as $substr) {
            $prefix .= substr(preg_replace('#[aeiou\s]+#i', '', $substr), 0, 1);
        }
        return $prefix;
    }
}
