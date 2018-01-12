<?php
namespace OnekO\Codeception\TestLink;

use Codeception\Event\FailEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Exception\ExtensionException;
use Codeception\Extension as CodeceptionExtension;
use Codeception\Lib\Parser;
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
    const ANNOTATION_STEP  = 'tl-step';

    const STATUS_SUCCESS    = 0;
    const STATUS_SKIPPED    = 1;
    const STATUS_INCOMPLETE = 2;
    const STATUS_FAILED     = 3;
    const STATUS_ERROR      = 4;

    const TESTLINK_STATUS_SUCCESS = 'p';
    const TESTLINK_STATUS_FAILED = 'f';
    const TESTLINK_STATUS_UNTESTED = 'n';
    const TESTLINK_STATUS_RETEST = 'x';
    const TESTLINK_STATUS_BLOCKED = 'b';

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
     * @var array
     */
    protected $project;

    /**
     * @var array
     */
    protected $plan;

    /**
     * @var array
     */
    protected $build;

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
        self::STATUS_SUCCESS    => self::TESTLINK_STATUS_SUCCESS,
        self::STATUS_SKIPPED    => self::TESTLINK_STATUS_UNTESTED,
        self::STATUS_INCOMPLETE => self::TESTLINK_STATUS_SUCCESS,
        self::STATUS_FAILED     => self::TESTLINK_STATUS_FAILED,
        self::STATUS_ERROR      => self::TESTLINK_STATUS_FAILED,
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
                $currentProject = $conn->execute(
                    'createTestProject',
                    [
                        'testprojectname' => $this->config['project'],
                        'testcaseprefix' => $this->getPrefix($this->config['project']),
                        'notes' => 'Created via Codeception Extension'
                    ]
                );
                echo 'Project created: ' . current($currentProject)['message'];
                $project = $conn->execute('getTestProjectByName', ['testprojectname' => $this->config['project']]);
            }
            if ($project['active'] === 0) {
                throw new ExtensionException(
                    $this,
                    'TestLink project id passed in the config is not active'
                );
            }

            $testPlan = current($conn->execute(
                'getTestPlanByName', [
                    'testprojectname' => $this->config['project'],
                    'testplanname' => $this->config['plan']
                ]
            ));
            if (!isset($testPlan['id'])) {
                $currentPlan = $conn->execute(
                    'createTestPlan',
                    [
                        'testprojectname' => $this->config['project'],
                        'testplanname' => $this->config['plan'],
                        'notes' => 'Created via Codeception Extension'
                    ]
                );
                echo 'Plan created: ' . current($currentPlan)['message'];
                $testPlan = current($conn->execute(
                    'getTestPlanByName', [
                        'testprojectname' => $this->config['project'],
                        'testplanname' => $this->config['plan']
                    ]
                ));
            }

            $builds = $conn->execute(
                'getBuildsForTestPlan', [
                    'testplanid' => $testPlan['id']
                ]
            );
            if (!is_array($builds) || count($builds) < 1) {
                $currentBuild = $conn->execute(
                    'createBuild',
                    [
                        'testplanid' => $testPlan['id'],
                        'buildname' => 'Auto',
                        'notes' => 'Created via Codeception Extension',
                        'active' => 1
                    ]
                );
                echo 'Build created: ' . current($currentBuild)['message'];
                $builds = $conn->execute(
                    'getBuildsForTestPlan', [
                        'testplanid' => $testPlan['id']
                    ]
                );
            }

            $this->project = $project;
            $this->plan = $testPlan;
            $this->build = $builds[count($builds) - 1];
        }

        // merge the statuses from the config over the default ones
        if (array_key_exists('status', $this->config)) {
            $this->statuses = array_merge($this->statuses, $this->config['status']);
        }
    }

    public function afterSuite(SuiteEvent $event)
    {
        $recorded = $this->getResults();
//        var_dump($recorded);
        // skip action if we don't have results or the Extension is disabled
        if (empty($recorded) || !$this->config['enabled']) {
            return;
        }

        foreach ($recorded as $suiteId => $results) {
            $suite = $this->getConnection()->execute('getTestSuite', [
                'testsuitename' => $suiteId,
                'prefix' => $this->project['prefix']
            ]);
//            var_dump('Obtenida: ', $suite);
            if (empty($suite)) {
                $resultNewSuite = $this->getConnection()->execute(
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
                echo 'Suite created:' . current($resultNewSuite)['message'];
                $suite = $this->getConnection()->execute('getTestSuite', [
                    'testsuitename' => $suiteId,
                    'prefix' => $this->project['prefix']
                ]);
            }
            if ($suite !== null) {
                $suite = current($suite);
            }
//            var_dump($suite);
            $cases = [];
            $result = $this->getConnection()->execute('getTestCasesForTestSuite', [
                'testprojectid' => $this->project['id'],
                'testsuiteid' => $suite['id'],
                'deep' => true
            ]);
            if (is_array($result)) {
                $cases = $result;
            }
//            var_dump($cases);
            foreach ($results as $testResult) {
//                var_dump($testResult);
                $testCase = false;
                foreach ($cases as $case) {
                    if ($case['name'] === $testResult['name']) {
//                        var_dump($case);
                        $testCase = current($this->getConnection()->execute('getTestCase', [
                            'testcaseid' => $case['id']
                        ]));
                    }
                }

                if ($testCase === false) {
                    $params = [
                        'testcasename' => $testResult['name'],
                        'testsuiteid' => $suite['id'],
                        'testprojectid' => $this->project['id'],
                        'authorlogin' => $this->config['author'],
                        'summary' => $testResult['summary'] !== null ? $testResult['summary'] : '',
                        'preconditions' => $testResult['preconditions'] !== null ? $testResult['preconditions'] : '',
                        'importance' => $testResult['importance'] !== null ? $testResult['importance'] : 2,
                        'executiontype' => $testResult['executionType'] !== null ? $testResult['executionType'] : 2,
                        'order' => $testResult['order'] !== null ? $testResult['order'] : '',
                        'status' => 7,
                        'steps' => $testResult['steps'] !== null ? $testResult['steps'] : [],
                        'estimatedexecduration' => round($testResult['elapsed'] / 60 / 1000, 2) // minutes
                    ];
                    $resultNewCase =  $this->getConnection()->execute('createTestCase', $params);
                    echo 'Sent to TestLink:' . current($resultNewCase)['message'];
                    $testCase = current($this->getConnection()->execute('getTestCase', [
                        'testcaseid' => current($resultNewCase)['additionalInfo']['id']
                    ]));
                }
//                var_dump($testCase);
                // Add case to test plan
                $resultAddTestToPlan = $this->getConnection()->execute('addTestCaseToTestPlan', [
                    'testprojectid' => $this->project['id'],
                    'testplanid' => $this->plan['id'],
                    'testcaseexternalid' => $testCase['full_tc_external_id'],
                    'overwrite' => 1,
                    'version' => 1
                ]);
//                var_dump($resultAddTestToPlan);

                $resultExecution = $this->getConnection()->execute('reportTCResult', [
                    'testcaseid' => $testCase['testcase_id'],
                    'testplanid' => $this->plan['id'],
                    'buildid' => $this->build['id'],
                    'status' => $testResult['status'],
                    'execduration' => round($testResult['elapsed'] / 60 / 1000, 2), // minutes
                ]);
//                var_dump($resultExecution);
                echo 'Result for "' . $testResult['name'] . '" sent';
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
     * @param int   $status TestLink Status ID
     * @param TestEvent $event
     */
    public function handleResult($status, TestEvent $event)
    {
        /** @var Cest $test */
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
                'status' => $status,
                'steps' => $this->getStepsForTest($test)
            ];
            $result['elapsed'] = $event->getTime();

            $this->results[$this->getSuiteForTest($test)][] = $result;
        }
        var_dump($this->results);
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
     * @param TestInterface $test
     *
     * @return array|null
     *
     * @codeCoverageIgnore
     */
    public function getStepsForTest(TestInterface $test)
    {
        if (!$test instanceof Cest) {
            return null;
        }

        $result = [];
        $steps = Annotation::forMethod($test->getTestClass(), $test->getTestMethod())->fetchAll($this::ANNOTATION_STEP);
        foreach ($steps as $i => $step) {
            $exploded = explode('|||', $step);
            $result[] = [
                'step_number' => $i + 1,
                'actions' => $exploded[0],
                'expected_results' => $exploded[1],
            ];
        }
        return $result;
    }

    /**
     * @param $str
     * @return bool|string
     */
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
