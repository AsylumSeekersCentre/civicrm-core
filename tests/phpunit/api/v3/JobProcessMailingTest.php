<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * File for the CiviCRM APIv3 job functions
 *
 * @package CiviCRM_APIv3
 * @subpackage API_Job
 *
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 */

/**
 * Class api_v3_JobTest
 * @group headless
 * @group civimail
 */
class api_v3_JobProcessMailingTest extends CiviUnitTestCase {
  protected $_apiversion = 3;

  public $_entity = 'Job';
  public $_params = [];
  private $_groupID;
  private $_email;

  protected $defaultSettings;

  /**
   * @var CiviMailUtils
   */
  private $_mut;

  public function setUp(): void {
    parent::setUp();
    // DGW
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;
    $this->_groupID = $this->groupCreate();
    $this->_email = 'test@test.test';
    $this->_params = [
      'subject' => 'Accidents in cars cause children',
      'body_text' => 'BEWARE children need regular infusions of toys. Santa knows your {domain.address}. There is no {action.optOutUrl}.',
      'name' => 'mailing name',
      'created_id' => 1,
      'groups' => ['include' => [$this->_groupID]],
      'scheduled_date' => 'now',
    ];
    $this->defaultSettings = [
      // int, #mailings to send
      'mailings' => 1,
      // int, #contacts to receive mailing
      'recipients' => 20,
      // int, #concurrent cron jobs
      'workers' => 1,
      // int, #times to spawn all the workers
      'iterations' => 1,
      // int, #extra seconds each cron job should hold lock
      'lockHold' => 0,
      // int, max# recipients to send in a given cron run
      'mailerBatchLimit' => 0,
      // int, max# concurrent jobs
      'mailerJobsMax' => 0,
      // int, max# recipients in each job
      'mailerJobSize' => 0,
      // int, microseconds separating messages
      'mailThrottleTime' => 0,
    ];
    $this->_mut = new CiviMailUtils($this, TRUE);
    $this->callAPISuccess('mail_settings', 'get', ['api.mail_settings.create' => ['domain' => 'chaos.org']]);
  }

  /**
   */
  public function tearDown(): void {
    $this->_mut->stop();
    CRM_Utils_Hook::singleton()->reset();
    $this->cleanupMailingTest();
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;
    parent::tearDown();
  }

  public function testBasic(): void {
    $this->createContactsInGroup(10, $this->_groupID);
    Civi::settings()->add([
      'mailerBatchLimit' => 2,
    ]);
    $mailing = $this->callAPISuccess('Mailing', 'create', $this->_params);
    $this->_mut->assertRecipients([]);
    $this->callAPISuccess('job', 'process_mailing', []);
    $mailing = $this->callAPISuccessGetSingle('Mailing', ['id' => $mailing['id'], 'version' => 4]);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($mailing['start_date'])));
    $this->assertNull($mailing['end_date']);
    $this->assertEquals('Running', $mailing['status']);
    $this->_mut->assertRecipients($this->getRecipients(1, 2));
    // This is a watered-down test that previously checked the forward action
    $this->_mut->checkAllMailLog([
      'civicrm/mailing/optout&reset=1&j',
      'Return-Path: b',
      'chaos.org',
      'From: "FIXME" <info@EXAMPLE.ORG>',
      'Subject: Accidents in cars cause children',
      'List-Unsubscribe: <mailto:u.',
      '@chaos.org>',
      'Precedence: bulk',
      'X-CiviMail-Bounce: b',
      'Content-Type: text/plain; charset=utf-8',
      'Content-Transfer-Encoding: 8bit',
    ]);
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;
    $this->callAPISuccess('Job', 'process_mailing', []);
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;
    $this->callAPISuccess('Job', 'process_mailing', []);
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;
    $this->callAPISuccess('Job', 'process_mailing', []);
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;
    $this->callAPISuccess('Job', 'process_mailing', []);
    $updatedMailing = $this->callAPISuccessGetSingle('Mailing', ['id' => $mailing['id'], 'version' => 4]);
    $this->assertEquals($mailing['start_date'], $updatedMailing['start_date']);
    $this->assertEquals(date('Y-m-d'), date('Y-m-d', strtotime($updatedMailing['end_date'])));
    $this->assertEquals('Complete', $updatedMailing['status']);
  }

  /**
   * Test that a contact deleted after the mailing is queued is not emailed.
   */
  public function testDeletedRecipient(): void {
    $this->createContactsInGroup(2, $this->_groupID);
    $this->callAPISuccess('Mailing', 'create', $this->_params);
    $this->callAPISuccess('Contact', 'delete', ['id' => $this->callAPISuccessGetValue('GroupContact', ['return' => 'contact_id', 'options' => ['limit' => 1, 'sort' => 'id DESC']])]);
    $this->callAPISuccess('job', 'process_mailing');
    $this->_mut->assertRecipients($this->getRecipients(1, 1));
  }

  /**
   * Test what happens when a contact is set to decesaed
   */
  public function testDeceasedRecipient(): void {
    $contactID = $this->individualCreate(['first_name' => 'test dead recipeint', 'email' => 'mailtestdead@civicrm.org']);
    $this->callAPISuccess('group_contact', 'create', [
      'contact_id' => $contactID,
      'group_id' => $this->_groupID,
      'status' => 'Added',
    ]);
    $this->createContactsInGroup(2, $this->_groupID);
    Civi::settings()->add([
      'mailerBatchLimit' => 2,
    ]);
    $mailing = $this->callAPISuccess('mailing', 'create', $this->_params);
    $this->assertEquals(3, $this->callAPISuccess('MailingRecipients', 'get', ['mailing_id' => $mailing['id']])['count']);
    $this->_mut->assertRecipients([]);
    $this->callAPISuccess('Contact', 'create', ['id' => $contactID, 'is_deceased' => 1, 'contact_type' => 'Individual']);
    $this->callAPISuccess('job', 'process_mailing', []);
    // Check that the deceased contact is not found in the mailing.
    $this->_mut->assertRecipients($this->getRecipients(1, 2));

  }

  /**
   * Test that "multiple bulk email recipients" setting is respected.
   */
  public function testMultipleBulkRecipients(): void {
    Civi::settings()->add([
      'civimail_multiple_bulk_emails' => 1,
    ]);
    $contactID = $this->individualCreate(['first_name' => 'test recipient']);
    $email1 = $this->callAPISuccess('email', 'create', [
      'contact_id' => $contactID,
      'email' => 'mail1@example.org',
      'is_bulkmail' => 1,
    ]);
    $email2 = $this->callAPISuccess('email', 'create', [
      'contact_id' => $contactID,
      'email' => 'mail2@example.org',
      'is_bulkmail' => 1,
    ]);
    $this->callAPISuccess('group_contact', 'create', [
      'contact_id' => $contactID,
      'group_id' => $this->_groupID,
      'status' => 'Added',
    ]);
    $mailing = $this->callAPISuccess('mailing', 'create', $this->_params);
    $this->assertEquals(2, $this->callAPISuccess('MailingRecipients', 'get', ['mailing_id' => $mailing['id']])['count']);
    $this->callAPISuccess('job', 'process_mailing', []);
    $this->_mut->assertRecipients([['mail1@example.org'], ['mail2@example.org']]);
    // Don't leave data lying around for other tests to screw up on.
    $this->callAPISuccess('Email', 'delete', ['id' => $email1['id']]);
    $this->callAPISuccess('Email', 'delete', ['id' => $email2['id']]);
  }

  /**
   * Test pause and resume on Mailing.
   */
  public function testPauseAndResumeMailing(): void {
    $this->createContactsInGroup(10, $this->_groupID);
    Civi::settings()->add([
      'mailerBatchLimit' => 2,
    ]);
    $this->_mut->clearMessages();
    //Create a test mailing and check if the status is set to Scheduled.
    $result = $this->callAPISuccess('mailing', 'create', $this->_params);
    $jobs = $this->callAPISuccess('mailing_job', 'get', ['mailing_id' => $result['id']]);
    $this->assertEquals('Scheduled', $jobs['values'][$jobs['id']]['status']);

    //Pause the mailing.
    CRM_Mailing_BAO_MailingJob::pause($result['id']);
    $jobs = $this->callAPISuccess('mailing_job', 'get', ['mailing_id' => $result['id']]);
    $this->assertEquals('Paused', $jobs['values'][$jobs['id']]['status']);
    $mailing = $this->callAPISuccessGetSingle('Mailing', ['id' => $result['id'], 'version' => 4]);
    $this->assertEquals('Paused', $mailing['status']);

    //Verify if Paused mailing isn't considered in process_mailing job.
    $this->callAPISuccess('job', 'process_mailing', []);
    //Check if mail log is empty.
    $this->_mut->assertMailLogEmpty();
    $jobs = $this->callAPISuccess('mailing_job', 'get', ['mailing_id' => $result['id']]);
    $this->assertEquals('Paused', $jobs['values'][$jobs['id']]['status']);

    //Resume should set the status back to Scheduled.
    CRM_Mailing_BAO_MailingJob::resume($result['id']);
    $jobs = $this->callAPISuccess('mailing_job', 'get', ['mailing_id' => $result['id']]);
    $this->assertEquals('Scheduled', $jobs['values'][$jobs['id']]['status']);

    //Execute the job and it should send the mailing to the recipients now.
    $this->callAPISuccess('job', 'process_mailing', []);
    $this->_mut->assertRecipients($this->getRecipients(1, 2));
    // Ensure that loading the report produces no errors.
    $report = CRM_Mailing_BAO_Mailing::report($result['id']);
    // dev/mailing#56 dev/mailing#57 Ensure that for completed mailings the jobs array is not empty.
    $this->assertTrue(!empty($report['jobs']));
    // Ensure that mailing name is correctly stored in the report.
    $this->assertEquals('mailing name', $report['mailing']['name']);
  }

  /**
   * Test mail when in non-production environment.
   *
   */
  public function testMailNonProductionRun(): void {
    $origMailingBackend = Civi::settings()->get('mailing_backend');

    // Test in non-production mode.
    $params = [
      'environment' => 'Staging',
    ];
    $this->callAPISuccess('Setting', 'create', $params);
    //Assert if outbound mail is disabled.
    $mailingBackend = Civi::settings()->get('mailing_backend');
    $this->assertEquals($mailingBackend['outBound_option'], CRM_Mailing_Config::OUTBOUND_OPTION_DISABLED);

    $this->createContactsInGroup(10, $this->_groupID);
    Civi::settings()->add([
      'mailerBatchLimit' => 2,
    ]);
    $this->callAPISuccess('mailing', 'create', $this->_params);
    $this->_mut->assertRecipients([]);
    $result = $this->callAPIFailure('job', 'process_mailing', []);
    $this->assertEquals($result['error_message'], "Job has not been executed as it is a Staging (non-production) environment.");

    // Test with runInNonProductionEnvironment param.
    try {
      // Do not call with callAPIFailure because we want to test the exception here
      civicrm_api3('job', 'process_mailing', ['runInNonProductionEnvironment' => TRUE]);
      $this->fail('should not be reachable');
    }
    catch (\CRM_Core_Exception $e) {
      $this->assertEquals('Outbound mail has been disabled', substr($e->getMessage(), 0, 31));
      $statuses = CRM_Core_Session::singleton()->getStatus();
      $hasExpectedMessage = FALSE;
      foreach ($statuses as $status) {
        if (str_contains($status['text'], 'Outbound emails have been disabled')) {
          $hasExpectedMessage = TRUE;
          break;
        }
      }
      if (!$hasExpectedMessage) {
        $this->fail('Status messages should contain "Outbound mail has been disabled"');
      }
    }

    $jobId = $this->callAPISuccessGetValue('Job', [
      'return' => "id",
      'api_action' => "group_rebuild",
    ]);
    $this->callAPISuccess('Job', 'create', [
      'id' => $jobId,
      'parameters' => "runInNonProductionEnvironment=TRUE",
    ]);
    $jobManager = new CRM_Core_JobManager();
    $jobManager->executeJobById($jobId);

    //Assert if outbound mail is still disabled.
    $mailingBackend = Civi::settings()->get('mailing_backend');
    $this->assertEquals($mailingBackend['outBound_option'], CRM_Mailing_Config::OUTBOUND_OPTION_DISABLED);

    // Test in production mode.
    Civi::settings()->set('mailing_backend', $origMailingBackend);
    $params = [
      'environment' => 'Production',
    ];
    $this->callAPISuccess('Setting', 'create', $params);
    $this->callAPISuccess('job', 'process_mailing', []);
    $this->_mut->assertRecipients($this->getRecipients(1, 2));
  }

  public static function concurrencyExamples(): array {
    $es = [];

    // Launch 3 workers, but mailerJobsMax limits us to 1 worker.
    $es[0] = [
      [
        'recipients' => 20,
        'workers' => 3,
        // FIXME: lockHold is unrealistic/unrepresentative. In reality, this situation fails because
        // the data.* locks trample the worker.* locks. However, setting lockHold allows us to
        // approximate the behavior of what would happen *if* the lock-implementation didn't suffer
        // trampling effects.
        'lockHold' => 10,
        'mailerBatchLimit' => 4,
        'mailerJobsMax' => 1,
      ],
      [
        // 2 jobs which produce 0 messages
        0 => 2,
        // 1 job which produces 4 messages
        4 => 1,
      ],
      4,
    ];

    // Launch 3 workers, but mailerJobsMax limits us to 2 workers.
    $es[1] = [
    // Settings.
      [
        'recipients' => 20,
        'workers' => 3,
        // FIXME: lockHold is unrealistic/unrepresentative. In reality, this situation fails because
        // the data.* locks trample the worker.* locks. However, setting lockHold allows us to
        // approximate the behavior of what would happen *if* the lock-implementation didn't suffer
        // trampling effects.
        'lockHold' => 10,
        'mailerBatchLimit' => 5,
        'mailerJobsMax' => 2,
      ],
      // Tallies.
      [
        // 1 job which produce 0 messages
        0 => 1,
        // 2 jobs which produce 5 messages
        5 => 2,
      ],
      // Total sent.
      10,
    ];

    // Launch 3 workers and saturate them (mailerJobsMax=3)
    $es[2] = [
      // Settings.
      [
        'recipients' => 20,
        'workers' => 3,
        'mailerBatchLimit' => 6,
        'mailerJobsMax' => 3,
      ],
      // Tallies.
      [
        // 3 jobs which produce 6 messages
        6 => 3,
      ],
      // Total sent.
      18,
    ];

    // Launch 4 workers and saturate them (mailerJobsMax=0)
    $es[3] = [
      // Settings.
      [
        'recipients' => 20,
        'workers' => 4,
        'mailerBatchLimit' => 6,
        'mailerJobsMax' => 0,
      ],
      // Tallies.
      [
        // 3 jobs which produce 6 messages
        6 => 3,
        // 1 job which produces 2 messages
        2 => 1,
      ],
      // Total sent.
      20,
    ];

    // Launch 1 worker, 3 times in a row. Deliver everything.
    $es[4] = [
      // Settings.
      [
        'recipients' => 10,
        'workers' => 1,
        'iterations' => 3,
        'mailerBatchLimit' => 7,
      ],
      // Tallies.
      [
        // 1 job which produces 7 messages
        7 => 1,
        // 1 job which produces 3 messages
        3 => 1,
        // 1 job which produces 0 messages
        0 => 1,
      ],
      // Total sent.
      10,
    ];

    // Launch 2 worker, 3 times in a row. Deliver everything.
    $es[5] = [
      // Settings.
      [
        'recipients' => 10,
        'workers' => 2,
        'iterations' => 3,
        'mailerBatchLimit' => 3,
      ],
      // Tallies.
      [
        // 3 jobs which produce 3 messages
        3 => 3,
        // 1 job which produces 1 messages
        1 => 1,
        // 2 jobs which produce 0 messages
        0 => 2,
      ],
      // Total sent.
      10,
    ];

    // For two mailings, launch 1 worker, 5 times in a row. Deliver everything.
    $es[6] = [
      // Settings.
      [
        'mailings' => 2,
        'recipients' => 10,
        'workers' => 1,
        'iterations' => 5,
        'mailerBatchLimit' => 6,
      ],
      // Tallies.
      [
        // x6 => x4+x2 => x6 => x2 => x0
        // 3 jobs which produce 6 messages
        6 => 3,
        // 1 job which produces 2 messages
        2 => 1,
        // 1 job which produces 0 messages
        0 => 1,
      ],
      // Total sent.
      20,
    ];

    return $es;
  }

  /**
   * Setup various mail configuration options (eg $mailerBatchLimit,
   * $mailerJobMax) and spawn multiple worker threads ($workers).
   * Allow the threads to complete. (Optionally, repeat the above
   * process.) Finally, check to see if the right number of
   * jobs delivered the right number of messages.
   *
   * @param array $settings
   *   An array of settings (eg mailerBatchLimit, workers). See comments
   *   for $this->defaultSettings.
   * @param array $expectedTallies
   *    A listing of the number cron-runs keyed by their size.
   *    For example, array(10=>2) means that there 2 cron-runs
   *    which delivered 10 messages each.
   * @param int $expectedTotal
   *    The total number of contacts for whom messages should have
   *    been sent.
   * @dataProvider concurrencyExamples
   */
  public function testConcurrency($settings, $expectedTallies, $expectedTotal) {
    $settings = array_merge($this->defaultSettings, $settings);

    $this->createContactsInGroup($settings['recipients'], $this->_groupID);
    Civi::settings()->add(CRM_Utils_Array::subset($settings, [
      'mailerBatchLimit',
      'mailerJobsMax',
      'mailThrottleTime',
    ]));

    for ($i = 0; $i < $settings['mailings']; $i++) {
      $this->callAPISuccess('mailing', 'create', $this->_params);
    }

    $this->_mut->assertRecipients([]);

    $allApiResults = [];
    for ($iterationId = 0; $iterationId < $settings['iterations']; $iterationId++) {
      $apiCalls = $this->createExternalAPI();
      $apiCalls->addEnv(['CIVICRM_CRON_HOLD' => $settings['lockHold']]);
      for ($workerId = 0; $workerId < $settings['workers']; $workerId++) {
        $apiCalls->addCall('job', 'process_mailing', []);
      }
      $apiCalls->start();
      $this->assertEquals($settings['workers'], $apiCalls->getRunningCount());

      $apiCalls->wait();
      $allApiResults = array_merge($allApiResults, $apiCalls->getResults());
    }

    $actualTallies = $this->tallyApiResults($allApiResults);
    $this->assertEquals($expectedTallies, $actualTallies, 'API tallies should match.' . print_r([
      'expectedTallies' => $expectedTallies,
      'actualTallies' => $actualTallies,
      'apiResults' => $allApiResults,
    ], TRUE));
    $this->_mut->assertRecipients($this->getRecipients(1, $expectedTotal / $settings['mailings'], 'nul.example.com', $settings['mailings']));
    $this->assertEquals(0, $apiCalls->getRunningCount());
  }

  /**
   * Check that the earlier iterations's activity targets on the recorded
   * BulkEmail activity don't get wiped out by subsequent iterations when
   * using batches.
   *
   * @dataProvider getBooleanDataProvider
   *
   * @param bool $isBulk
   */
  public function testBatchActivityTargets(bool $isBulk): void {
    $loggedInUserId = $this->createLoggedInUser();

    \Civi::settings()->set('mailerBatchLimit', 2);
    \Civi::settings()->set('civimail_multiple_bulk_emails', $isBulk);

    // We have such small batches we need to lower this interval to get it
    // to create the activity.
    $old_sync_interval = \Civi::settings()->get('civimail_sync_interval');
    \Civi::settings()->set('civimail_sync_interval', 1);

    $this->createContactsInGroup(6, $this->_groupID);
    $mailing = $this->callAPISuccess('mailing', 'create', $this->_params + ['scheduled_id' => $loggedInUserId]);
    $this->callAPISuccess('Job', 'process_mailing', []);
    $bulkEmailActivity = $this->callAPISuccess('Activity', 'getsingle', [
      'source_record_id' => $mailing['id'],
      'activity_type_id' => 'Bulk Email',
      'return' => ['target_contact_id'],
    ]);
    // After first batch, should have two targets
    $this->assertCount(2, $bulkEmailActivity['target_contact_id']);

    // Because we're running in script mode need to reset this static
    // to get it to process the other batches.
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;

    $this->callAPISuccess('job', 'process_mailing', []);
    $bulkEmailActivity = $this->callAPISuccess('Activity', 'getsingle', [
      'source_record_id' => $mailing['id'],
      'activity_type_id' => 'Bulk Email',
      'return' => ['target_contact_id'],
    ]);
    // After second batch, should have four targets
    $this->assertCount(4, $bulkEmailActivity['target_contact_id']);

    // restore setting
    \Civi::settings()->set('civimail_sync_interval', $old_sync_interval);
  }

  /**
   * Create contacts in group.
   *
   * @param int $count
   * @param int $groupID
   * @param string $domain
   */
  public function createContactsInGroup($count, $groupID, $domain = 'nul.example.com') {
    for ($i = 1; $i <= $count; $i++) {
      $contactID = $this->individualCreate(['first_name' => $count, 'email' => 'mail' . $i . '@' . $domain]);
      $this->callAPISuccess('group_contact', 'create', [
        'contact_id' => $contactID,
        'group_id' => $groupID,
        'status' => 'Added',
      ]);
    }
  }

  /**
   * Construct the list of email addresses for $count recipients.
   *
   * @param int $start
   * @param int $count
   * @param string $domain
   * @param int $mailings
   *
   * @return array
   */
  public function getRecipients($start, $count, $domain = 'nul.example.com', $mailings = 1) {
    $recipients = [];
    for ($m = 0; $m < $mailings; $m++) {
      for ($i = $start; $i < ($start + $count); $i++) {
        $recipients[][0] = 'mail' . $i . '@' . $domain;
      }
    }
    return $recipients;
  }

  protected function cleanupMailingTest() {
    $this->quickCleanup([
      'civicrm_mailing',
      'civicrm_mailing_job',
      'civicrm_mailing_spool',
      'civicrm_mailing_group',
      'civicrm_mailing_recipients',
      'civicrm_mailing_event_queue',
      'civicrm_mailing_event_bounce',
      'civicrm_mailing_event_delivered',
      'civicrm_group',
      'civicrm_group_contact',
      'civicrm_contact',
      'civicrm_activity_contact',
      'civicrm_activity',
    ]);
    Civi::settings()->set('mailerBatchLimit', 0);
  }

  /**
   * Categorize results based on (a) whether they succeeded
   * and (b) the number of messages sent.
   *
   * @param array $apiResults
   * @return array
   *   One key 'error' for all failures.
   *   A separate key for each distinct quantity.
   */
  protected function tallyApiResults($apiResults) {
    $ret = [];
    foreach ($apiResults as $apiResult) {
      $key = !empty($apiResult['is_error']) ? 'error' : $apiResult['values']['processed'];
      $ret[$key] = !empty($ret[$key]) ? 1 + $ret[$key] : 1;
    }
    return $ret;
  }

}
