<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Exception;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Concurrency\Semaphore;
use Piwik\CronArchive\FixedSiteIds;
use Piwik\CronArchive\SharedSiteIds;
use Piwik\CronArchive\SiteArchivingInfo;
use Piwik\Jobs\Consumer;
use Piwik\Jobs\Impl\CliConsumer;
use Piwik\Jobs\Impl\DistributedQueue;
use Piwik\Jobs\Queue;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugins\CoreAdminHome\API as APICoreAdminHome;
use Piwik\Plugins\SitesManager\API as APISitesManager;

/**
 * ./console core:archive runs as a cron and is a useful tool for general maintenance,
 * and pre-process reports for a Fast dashboard rendering.
 */
class CronArchive
{
    const ARCHIVING_JOB_NAMESPACE = 'CronArchive';

    // the url can be set here before the init, and it will be used instead of --url=
    public static $url = false;

    // force-timeout-for-periods default (1 hour)
    const SECONDS_DELAY_BETWEEN_PERIOD_ARCHIVES = 3600;

    // force-all-periods default (7 days)
    const ARCHIVE_SITES_WITH_TRAFFIC_SINCE = 604800;

    // By default, will process last 52 days and months
    // It will be overwritten by the number of days since last archiving ran until completion.
    const DEFAULT_DATE_LAST = 52;

    // Since weeks are not used in yearly archives, we make sure that all possible weeks are processed
    const DEFAULT_DATE_LAST_WEEKS = 260;

    const DEFAULT_DATE_LAST_YEARS = 7;

    // Flag to know when the archive cron is calling the API
    const APPEND_TO_API_REQUEST = '&trigger=archivephp';

    // Flag used to record timestamp in Option::
    const OPTION_ARCHIVING_FINISHED_TS = "LastCompletedFullArchiving";

    // Name of option used to store starting timestamp
    const OPTION_ARCHIVING_STARTED_TS = "LastFullArchivingStartTime";

    // Show only first N characters from Piwik API output in case of errors
    const TRUNCATE_ERROR_MESSAGE_SUMMARY = 6000;

    // archiving  will be triggered on all websites with traffic in the last $shouldArchiveOnlySitesWithTrafficSince seconds
    private $shouldArchiveOnlySitesWithTrafficSince;

    // By default, we only process the current week/month/year at most once an hour
    public $processPeriodsMaximumEverySeconds;
    public $todayArchiveTimeToLive;
    public $websiteDayHasFinishedSinceLastRun = array();
    public $idSitesInvalidatedOldReports = array();
    private $shouldArchiveOnlySpecificPeriods = array();
    /**
     * @var SharedSiteIds|FixedSiteIds
     */
    private $websites = array();
    private $allWebsites = array();
    private $segments = array();
    private $piwikUrl = false;
    private $token_auth = false;
    private $visitsToday = 0;
    private $requests = 0;
    private $output = '';
    public $archiveAndRespectTTL = true;

    public $startTime;

    private $lastSuccessRunTimestamp = false;
    private $errors = array();
    private $isCoreInited = false;

    const NO_ERROR = "no error";

    public $testmode = false;

    /**
     * The list of IDs for sites for whom archiving should be initiated. If supplied, only these
     * sites will be archived.
     *
     * @var int[]
     */
    public $shouldArchiveSpecifiedSites = array();

    /**
     * The list of IDs of sites to ignore when launching archiving. Archiving will not be launched
     * for any site whose ID is in this list (even if the ID is supplied in {@link $shouldArchiveSpecifiedSites}
     * or if {@link $shouldArchiveAllSites} is true).
     *
     * @var int[]
     */
    public $shouldSkipSpecifiedSites = array();

    /**
     * If true, archiving will be launched for every site.
     *
     * @var bool
     */
    public $shouldArchiveAllSites = false;

    /**
     * If true, xhprof will be initiated for the archiving run. Only for development/testing.
     *
     * @var bool
     */
    public $shouldStartProfiler = false;

    /**
     * If HTTP requests are used to initiate archiving, this controls whether invalid SSL certificates should
     * be accepted or not by each request.
     *
     * @var bool
     */
    public $acceptInvalidSSLCertificate = false;

    /**
     * If set to true, scheduled tasks will not be run.
     *
     * @var bool
     */
    public $disableScheduledTasks = false;

    /**
     * The amount of seconds between non-day period archiving. That is, if archiving has been launched within
     * the past [$forceTimeoutPeriod] seconds, Piwik will not initiate archiving for week, month and year periods.
     *
     * @var int|false
     */
    public $forceTimeoutPeriod = false;

    /**
     * If supplied, archiving will be launched for sites that have had visits within the last [$shouldArchiveAllPeriodsSince]
     * seconds. If set to `true`, the value defaults to {@link ARCHIVE_SITES_WITH_TRAFFIC_SINCE}.
     *
     * @var int|bool
     */
    public $shouldArchiveAllPeriodsSince = false;

    /**
     * If supplied, archiving will be launched only for periods that fall within this date range. For example,
     * `"2012-01-01,2012-03-15"` would result in January 2012, February 2012 being archived but not April 2012.
     *
     * @var string|false eg, `"2012-01-01,2012-03-15"`
     */
    public $restrictToDateRange = false;

    /**
     * A list of periods to launch archiving for. By default, day, week, month and year periods
     * are considered. This variable can limit the periods to, for example, week & month only.
     *
     * @var string[] eg, `array("day","week","month","year")`
     */
    public $restrictToPeriods = array();

    /**
     * Forces CronArchive to retrieve data for the last [$dateLastForced] periods when initiating archiving.
     * When archiving weeks, for example, if 10 is supplied, the API will be called w/ last10. This will potentially
     * initiate archiving for the last 10 weeks.
     *
     * @var int|false
     */
    public $dateLastForced = false;

    private $websitesWithVisitsSinceLastRun = 0;
    private $skippedPeriodsArchivesWebsite = 0;
    private $skippedDayArchivesWebsites = 0;
    private $skipped = 0;
    private $processed = 0;
    private $archivedPeriodsArchivesWebsite = 0;

    /**
     * TODO
     *
     * @var Queue
     */
    private $queue;

    /**
     * TODO
     *
     * @var Consumer|null
     */
    private $consumer;

    /**
     * TODO
     *
     * @var SiteArchivingInfo
     */
    private $siteArchivingInfo;

    /**
     * Returns the option name of the option that stores the time core:archive was last executed.
     *
     * @param int $idSite
     * @param string $period
     * @return string
     */
    public static function lastRunKey($idSite, $period)
    {
        return "lastRunArchive" . $period . "_" . $idSite;
    }

    /**
     * Constructor.
     *
     * @param string|false $piwikUrl The URL to the Piwik installation to initiate archiving for. If `false`,
     *                               we determine it using the current request information.
     *
     *                               If invoked via the command line, $piwikUrl cannot be false.
     * TODO: update
     */
    public function __construct($piwikUrl = false, $queue = null, $consumer = null)
    {
        $this->initLog();
        $this->initPiwikHost($piwikUrl);

        if (empty($queue)) {
            $queue = new DistributedQueue(self::ARCHIVING_JOB_NAMESPACE);

            if (empty($consumer)) {
                $consumer = new CliConsumer($queue);
            }
        }

        $this->siteArchivingInfo = new SiteArchivingInfo($this);

        $this->queue = $queue;
        $this->consumer = $consumer;

        $self = $this;
        $this->consumer->setOnJobsFinishedCallback(function ($responses) use ($self) {
            foreach ($responses as $url => $response) {
                $self->responseFinished($url, $response);
            }
        });

        $this->startTime = time();
    }

    /**
     * Initializes and runs the cron archiver.
     */
    public function main()
    {
        $this->init();
        $this->run();
        $this->runScheduledTasks();
        $this->end();
    }

    public function init()
    {
        // Note: the order of methods call matters here.
        $this->initCore();
        $this->initTokenAuth();
        $this->initCheckCli();
        $this->initStateFromParameters();
        Piwik::setUserHasSuperUserAccess(true);

        $this->logInitInfo();
        $this->checkPiwikUrlIsValid();
        $this->logArchiveTimeoutInfo();

        // record archiving start time
        Option::set(self::OPTION_ARCHIVING_STARTED_TS, time());

        $this->segments = $this->initSegmentsToArchive();
        $this->allWebsites = APISitesManager::getInstance()->getAllSitesId();

        if(!empty($this->shouldArchiveOnlySpecificPeriods)) {
            $this->log("- Will process the following periods: " . implode(", ", $this->shouldArchiveOnlySpecificPeriods) . " (--force-periods)");
        }

        $websitesIds = $this->initWebsiteIds();
        $this->filterWebsiteIds($websitesIds);
        $this->websites = $websitesIds; // TODO change docs

        if ($this->shouldStartProfiler) {
            \Piwik\Profiler::setupProfilerXHProf($mainRun = true);
            $this->log("XHProf profiling is enabled.");
        }

        /**
         * This event is triggered after a CronArchive instance is initialized.
         *
         * @param array $websiteIds The list of website IDs this CronArchive instance is processing.
         *                          This will be the entire list of IDs regardless of whether some have
         *                          already been processed.
         */
        Piwik::postEvent('CronArchive.init.finish', array($this->websites->getInitialSiteIds()));
    }

    public function runScheduledTasksInTrackerMode()
    {
        $this->initCore();
        $this->initTokenAuth();
        $this->logInitInfo();
        $this->checkPiwikUrlIsValid();
        $this->runScheduledTasks();
    }

    /**
     * Main function, runs archiving on all websites with new activity
     */
    public function run()
    {
        $this->logSection("START");
        $this->log("Starting Piwik reports archiving...");

        /**
         * Algorithm is:
         * - queue day archiving jobs for a site
         * - when a site finishes archiving for day, queue other requests including:
         *   * period archiving
         *   * segments archiving for day
         *   * segments archiving for periods
         *
         * TODO: be more descriptive
         */

        if (!$this->isContinuationOfArchivingJob()) {
            Semaphore::deleteLike("CronArchive%");

            foreach ($this->websites as $idSite) {
                $this->queueDayArchivingJobsForSite($idSite);
            }
        }

        // we allow the consumer to be empty in case another server does the actual job processing
        if (empty($this->consumer)) {
            return;
        }

        $this->consumer->startConsuming($finishWhenNoJobs = true);

            /** TODO: deal w/ these events
             * This event is triggered before the cron archiving process starts archiving data for a single
             * site.
             *
             * @param int $idSite The ID of the site we're archiving data for.
             */
            //Piwik::postEvent('CronArchive.archiveSingleSite.start', array($idSite));
            /**
             * This event is triggered immediately after the cron archiving process starts archiving data for a single
             * site.
             *
             * @param int $idSite The ID of the site we're archiving data for.
             */
            //Piwik::postEvent('CronArchive.archiveSingleSite.finish', array($idSite, $completed));

        $this->logSummary();
    }

    public function logSummary()
    {
        $this->log("Done archiving!");

        $this->logSection("SUMMARY");
        $this->log("Total visits for today across archived websites: " . $this->visitsToday);

        $totalWebsites = count($this->allWebsites);
        $this->skipped = $totalWebsites - $this->websitesWithVisitsSinceLastRun;
        $this->log("Archived today's reports for {$this->websitesWithVisitsSinceLastRun} websites");
        $this->log("Archived week/month/year for {$this->archivedPeriodsArchivesWebsite} websites");
        $this->log("Skipped {$this->skipped} websites: no new visit since the last script execution");
        $this->log("Skipped {$this->skippedDayArchivesWebsites} websites day archiving: existing daily reports are less than {$this->todayArchiveTimeToLive} seconds old");
        $this->log("Skipped {$this->skippedPeriodsArchivesWebsite} websites week/month/year archiving: existing periods reports are less than {$this->processPeriodsMaximumEverySeconds} seconds old");
        $this->log("Total API requests: {$this->requests}");

        //DONE: done/total, visits, wtoday, wperiods, reqs, time, errors[count]: first eg.
        $percent = $this->websites->getNumSites() == 0
            ? ""
            : " " . round($this->processed * 100 / $this->websites->getNumSites(), 0) . "%";
        $this->log("done: " .
            $this->processed . "/" . $this->websites->getNumSites() . "" . $percent . ", " .
            $this->visitsToday . " vtoday, $this->websitesWithVisitsSinceLastRun wtoday, {$this->archivedPeriodsArchivesWebsite} wperiods, " .
            $this->requests . " req, " /* TODO . round($timer->getTimeMs()) */ . " ms, " .
            (empty($this->errors)
                ? self::NO_ERROR
                : (count($this->errors) . " errors."))
        );
        // TODO: $this->log($timer->__toString());
    }

    /**
     * End of the script
     */
    public function end()
    {
        if (empty($this->errors)) {
            // No error -> Logs the successful script execution until completion
            Option::set(self::OPTION_ARCHIVING_FINISHED_TS, time());
            return;
        }

        $this->logSection("SUMMARY OF ERRORS");
        foreach ($this->errors as $error) {
            // do not logError since errors are already in stderr
            $this->log("Error: " . $error);
        }
        $summary = count($this->errors) . " total errors during this script execution, please investigate and try and fix these errors.";
        $this->logFatalError($summary);
    }

    public function logFatalError($m)
    {
        $this->logError($m);
        exit(1);
    }

    public function runScheduledTasks()
    {
        $this->logSection("SCHEDULED TASKS");

        if ($this->disableScheduledTasks) {
            $this->log("Scheduled tasks are disabled with --disable-scheduled-tasks");
            return;
        }

        $this->log("Starting Scheduled tasks... ");

        $tasksOutput = $this->request("?module=API&method=CoreAdminHome.runScheduledTasks&format=csv&convertToUnicode=0&token_auth=" . $this->token_auth);
        if ($tasksOutput == \Piwik\DataTable\Renderer\Csv::NO_DATA_AVAILABLE) {
            $tasksOutput = " No task to run";
        }
        $this->log($tasksOutput);
        $this->log("done");
        $this->logSection("");
    }

    /**
     * Checks the config file is found.
     *
     * @param $piwikUrl
     * @throws Exception
     */
    protected function initConfigObject($piwikUrl)
    {
        // HOST is required for the Config object
        $parsed = parse_url($piwikUrl);
        Url::setHost($parsed['host']);

        Config::getInstance()->clear();

        try {
            Config::getInstance()->checkLocalConfigFound();
        } catch (Exception $e) {
            throw new Exception("The configuration file for Piwik could not be found. " .
                "Please check that config/config.ini.php is readable by the user " .
                get_current_user());
        }
    }

    /**
     * Returns base URL to process reports for the $idSite on a given $period
     */
    private function getVisitsRequestUrl($idSite, $period, $date)
    {
        $url = "?module=API&method=API.get&idSite=$idSite&period=$period&date=" . $date . "&format=php&token_auth=" . $this->token_auth;
        if($this->shouldStartProfiler) {
            $url .= "&xhprof=2";
        }
        if ($this->testmode) {
            $url .= "&testmode=1";
        }
        return $url;
    }

    private function initSegmentsToArchive()
    {
        $segments = \Piwik\SettingsPiwik::getKnownSegmentsToArchive();
        if (empty($segments)) {
            return array();
        }
        $this->log("- Will pre-process " . count($segments) . " Segments for each website and each period: " . implode(", ", $segments));
        return $segments;
    }

    // TODO: make sure to deal w/ $this->requests/$this->processed & other metrics

    private function getSegmentsForSite($idSite)
    {
        $segmentsAllSites = $this->segments;
        $segmentsThisSite = \Piwik\SettingsPiwik::getKnownSegmentsToArchiveForSite($idSite);
        if (!empty($segmentsThisSite)) {
            $this->log("Will pre-process the following " . count($segmentsThisSite) . " Segments for this website (id = $idSite): " . implode(", ", $segmentsThisSite));
        }
        $segments = array_unique(array_merge($segmentsAllSites, $segmentsThisSite));
        return $segments;
    }

    /**
     * Logs a section in the output
     */
    private function logSection($title = "")
    {
        $this->log("---------------------------");
        if(!empty($title)) {
            $this->log($title);
        }
    }

    public function log($m)
    {
        $this->output .= $m . "\n";
        try {
            Log::info($m);

            flush();
        } catch(Exception $e) {
            print($m . "\n");
        }
    }

    public function logError($m)
    {
        if (!defined('PIWIK_ARCHIVE_NO_TRUNCATE')) {
            $m = substr($m, 0, self::TRUNCATE_ERROR_MESSAGE_SUMMARY);
        }
        $m = str_replace(array("\n", "\t"), " ", $m);
        $this->errors[] = $m;
        Log::error($m);

        flush();
    }

    private function logNetworkError($url, $response)
    {
        $message = "Got invalid response from API request: $url. ";
        if (empty($response)) {
            $message .= "The response was empty. This usually means a server error. This solution to this error is generally to increase the value of 'memory_limit' in your php.ini file. Please check your Web server Error Log file for more details.";
        } else {
            $message .= "Response was '$response'";
        }
        $this->logError($message);
        return false;
    }

    // TODO: go through each method and see if it still needs to be called. eg, request() shouldn't be, but its code needs to be dealt w/
    /**
     * Issues a request to $url
     */
    private function request($url)
    {
        $url = $this->piwikUrl . $url . self::APPEND_TO_API_REQUEST;

        if($this->shouldStartProfiler) { // TODO: redundancy w/ above
            $url .= "&xhprof=2";
        }

        if ($this->testmode) {
            $url .= "&testmode=1";
        }

        try {
            $cliMulti  = new CliMulti();
            $cliMulti->setAcceptInvalidSSLCertificate($this->acceptInvalidSSLCertificate);
            $responses = $cliMulti->request(array($url));

            $response  = !empty($responses) ? array_shift($responses) : null;
        } catch (Exception $e) {
            return $this->logNetworkError($url, $e->getMessage());
        }

        if ($this->checkResponse($response, $url)) {
            return $response;
        }

        return false;
    }

    private function checkResponse($response, $url)
    {
        if (empty($response)
            || stripos($response, 'error')
        ) {
            return $this->logNetworkError($url, $response);
        }
        return true;
    }

    /**
     * Configures Piwik\Log so messages are written in output
     */
    private function initLog()
    {
        $config = Config::getInstance();

        $log = $config->log;
        $log['log_only_when_debug_parameter'] = 0;
        $log[Log::LOG_WRITERS_CONFIG_OPTION][] = "screen";

        $config->log = $log;

        // Make sure we log at least INFO (if logger is set to DEBUG then keep it)
        $logLevel = Log::getInstance()->getLogLevel();
        if ($logLevel < Log::INFO) {
            Log::getInstance()->setLogLevel(Log::INFO);
        }
    }

    /**
     * Script does run on http:// ONLY if the SU token is specified
     */
    private function initCheckCli()
    {
        if (Common::isPhpCliMode()) {
            return;
        }
        $token_auth = Common::getRequestVar('token_auth', '', 'string');
        if ($token_auth !== $this->token_auth
            || strlen($token_auth) != 32
        ) {
            die('<b>You must specify the Super User token_auth as a parameter to this script, eg. <code>?token_auth=XYZ</code> if you wish to run this script through the browser. </b><br>
                However it is recommended to run it <a href="http://piwik.org/docs/setup-auto-archiving/">via cron in the command line</a>, since it can take a long time to run.<br/>
                In a shell, execute for example the following to trigger archiving on the local Piwik server:<br/>
                <code>$ /path/to/php /path/to/piwik/console core:archive --url=http://your-website.org/path/to/piwik/</code>');
        }
    }

    /**
     * Init Piwik, connect DB, create log & config objects, etc.
     */
    private function initCore()
    {
        try {
            FrontController::getInstance()->init();
            $this->isCoreInited = true;
        } catch (Exception $e) {
            throw new Exception("ERROR: During Piwik init, Message: " . $e->getMessage());
        }
    }

    public function isCoreInited()
    {
        return $this->isCoreInited;
    }

    /**
     * Initializes the various parameters to the script, based on input parameters.
     *
     */
    private function initStateFromParameters()
    {
        $this->todayArchiveTimeToLive = Rules::getTodayArchiveTimeToLive();
        $this->processPeriodsMaximumEverySeconds = $this->getDelayBetweenPeriodsArchives();
        $this->lastSuccessRunTimestamp = Option::get(self::OPTION_ARCHIVING_FINISHED_TS);
        $this->shouldArchiveOnlySitesWithTrafficSince = $this->isShouldArchiveAllSitesWithTrafficSince();
        $this->shouldArchiveOnlySpecificPeriods = $this->getPeriodsToProcess();

        if($this->shouldArchiveOnlySitesWithTrafficSince === false) {
            // force-all-periods is not set here
            if (empty($this->lastSuccessRunTimestamp)) {
                // First time we run the script
                $this->shouldArchiveOnlySitesWithTrafficSince = self::ARCHIVE_SITES_WITH_TRAFFIC_SINCE;
            } else {
                // there was a previous successful run
                $this->shouldArchiveOnlySitesWithTrafficSince = time() - $this->lastSuccessRunTimestamp;
            }
        }  else {
            // force-all-periods is set here
            $this->archiveAndRespectTTL = false;

            if($this->shouldArchiveOnlySitesWithTrafficSince === true) {
                // force-all-periods without value
                $this->shouldArchiveOnlySitesWithTrafficSince = self::ARCHIVE_SITES_WITH_TRAFFIC_SINCE;
            }
        }
    }

    public function filterWebsiteIds(&$websiteIds)
    {
        // Keep only the websites that do exist
        $websiteIds = array_intersect($websiteIds, $this->allWebsites);

        /**
         * Triggered by the **core:archive** console command so plugins can modify the list of
         * websites that the archiving process will be launched for.
         *
         * Plugins can use this hook to add websites to archive, remove websites to archive, or change
         * the order in which websites will be archived.
         *
         * @param array $websiteIds The list of website IDs to launch the archiving process for.
         */
        Piwik::postEvent('CronArchive.filterWebsiteIds', array(&$websiteIds));
    }

    /**
     *  Returns the list of sites to loop over and archive.
     *  @return array
     */
    public function initWebsiteIds()
    {
        if(count($this->shouldArchiveSpecifiedSites) > 0) {
            $this->log("- Will process " . count($this->shouldArchiveSpecifiedSites) . " websites (--force-idsites)");

            return $this->shouldArchiveSpecifiedSites;
        }
        if ($this->shouldArchiveAllSites) {
            $this->log("- Will process all " . count($this->allWebsites) . " websites");
            return $this->allWebsites;
        }

        $websiteIds = array_merge(
            $this->addWebsiteIdsWithVisitsSinceLastRun(),
            $this->getWebsiteIdsToInvalidate()
        );
        $websiteIds = array_merge($websiteIds, $this->addWebsiteIdsInTimezoneWithNewDay($websiteIds));
        return array_unique($websiteIds);
    }

    private function initTokenAuth()
    {
        $superUser = Db::get()->fetchRow("SELECT login, token_auth
                                          FROM " . Common::prefixTable("user") . "
                                          WHERE superuser_access = 1
                                          ORDER BY date_registered ASC");
        $this->token_auth = $superUser['token_auth'];
    }

    private function initPiwikHost($piwikUrl = false)
    {
        // If core:archive command run as a web cron, we use the current hostname+path
        if (empty($piwikUrl)) {
            if (!empty(self::$url)) {
                $piwikUrl = self::$url;
            } else {
                // example.org/piwik/
                $piwikUrl = SettingsPiwik::getPiwikUrl();
            }
        }

        if (!$piwikUrl) {
            $this->logFatalErrorUrlExpected();
        }

        if(!\Piwik\UrlHelper::isLookLikeUrl($piwikUrl)) {
            // try adding http:// in case it's missing
            $piwikUrl = "http://" . $piwikUrl;
        }

        if(!\Piwik\UrlHelper::isLookLikeUrl($piwikUrl)) {
            $this->logFatalErrorUrlExpected();
        }

        // ensure there is a trailing slash
        if ($piwikUrl[strlen($piwikUrl) - 1] != '/' && !Common::stringEndsWith($piwikUrl, 'index.php')) {
            $piwikUrl .= '/';
        }

        $this->initConfigObject($piwikUrl);

        if (Config::getInstance()->General['force_ssl'] == 1) {
            $piwikUrl = str_replace('http://', 'https://', $piwikUrl);
        }

        if (!Common::stringEndsWith($piwikUrl, 'index.php')) {
            $piwikUrl .= 'index.php';
        }

        $this->piwikUrl = $piwikUrl;
    }

    private function updateIdSitesInvalidatedOldReports()
    {
        $this->idSitesInvalidatedOldReports = APICoreAdminHome::getWebsiteIdsToInvalidate();
    }

    /**
     * Return All websites that had reports in the past which were invalidated recently
     * (see API CoreAdminHome.invalidateArchivedReports)
     * eg. when using Python log import script
     *
     * @return array
     */
    private function getWebsiteIdsToInvalidate()
    {
        $this->updateIdSitesInvalidatedOldReports();

        if (count($this->idSitesInvalidatedOldReports) > 0) {
            $ids = ", IDs: " . implode(", ", $this->idSitesInvalidatedOldReports);
            $this->log("- Will process " . count($this->idSitesInvalidatedOldReports)
                . " other websites because some old data reports have been invalidated (eg. using the Log Import script) "
                . $ids);
        }

        return $this->idSitesInvalidatedOldReports;
    }

    /**
     * Returns all sites that had visits since specified time
     *
     * @return string
     */
    private function addWebsiteIdsWithVisitsSinceLastRun()
    {
        $sitesIdWithVisits = APISitesManager::getInstance()->getSitesIdWithVisits(time() - $this->shouldArchiveOnlySitesWithTrafficSince);
        $websiteIds = !empty($sitesIdWithVisits) ? ", IDs: " . implode(", ", $sitesIdWithVisits) : "";
        $prettySeconds = \Piwik\MetricsFormatter::getPrettyTimeFromSeconds( $this->shouldArchiveOnlySitesWithTrafficSince, true, false);
        $this->log("- Will process " . count($sitesIdWithVisits) . " websites with new visits since "
            . $prettySeconds
            . " "
            . $websiteIds);
        return $sitesIdWithVisits;
    }

    /**
     * Returns the list of timezones where the specified timestamp in that timezone
     * is on a different day than today in that timezone.
     *
     * @return array
     */
    private function getTimezonesHavingNewDay()
    {
        $timestamp = $this->lastSuccessRunTimestamp;
        $uniqueTimezones = APISitesManager::getInstance()->getUniqueSiteTimezones();
        $timezoneToProcess = array();
        foreach ($uniqueTimezones as &$timezone) {
            $processedDateInTz = Date::factory((int)$timestamp, $timezone);
            $currentDateInTz = Date::factory('now', $timezone);

            if ($processedDateInTz->toString() != $currentDateInTz->toString()) {
                $timezoneToProcess[] = $timezone;
            }
        }
        return $timezoneToProcess;
    }

    /**
     * Returns the list of websites in which timezones today is a new day
     * (compared to the last time archiving was executed)
     *
     * @param $websiteIds
     * @return array Website IDs
     */
    private function addWebsiteIdsInTimezoneWithNewDay($websiteIds)
    {
        $timezones = $this->getTimezonesHavingNewDay();
        $websiteDayHasFinishedSinceLastRun = APISitesManager::getInstance()->getSitesIdFromTimezones($timezones);
        $websiteDayHasFinishedSinceLastRun = array_diff($websiteDayHasFinishedSinceLastRun, $websiteIds);
        $this->websiteDayHasFinishedSinceLastRun = $websiteDayHasFinishedSinceLastRun;
        if (count($websiteDayHasFinishedSinceLastRun) > 0) {
            $ids = !empty($websiteDayHasFinishedSinceLastRun) ? ", IDs: " . implode(", ", $websiteDayHasFinishedSinceLastRun) : "";
            $this->log("- Will process " . count($websiteDayHasFinishedSinceLastRun)
                . " other websites because the last time they were archived was on a different day (in the website's timezone) "
                . $ids);
        }
        return $websiteDayHasFinishedSinceLastRun;
    }

    /**
     *  Test that the specified piwik URL is a valid Piwik endpoint.
     */
    private function checkPiwikUrlIsValid()
    {
        $response = $this->request("?module=API&method=API.getDefaultMetricTranslations&format=original&serialize=1");
        $responseUnserialized = @unserialize($response);
        if ($response === false
            || !is_array($responseUnserialized)
        ) {
            $this->logFatalError("The Piwik URL {$this->piwikUrl} does not seem to be pointing to a Piwik server. Response was '$response'.");
        }
    }

    private function logInitInfo()
    {
        $this->logSection("INIT");
        $this->log("Piwik is installed at: {$this->piwikUrl}");
        $this->log("Running Piwik " . Version::VERSION . " as Super User");
    }

    private function logArchiveTimeoutInfo()
    {
        $this->logSection("NOTES");

        // Recommend to disable browser archiving when using this script
        if (Rules::isBrowserTriggerEnabled()) {
            $this->log("- If you execute this script at least once per hour (or more often) in a crontab, you may disable 'Browser trigger archiving' in Piwik UI > Settings > General Settings. ");
            $this->log("  See the doc at: http://piwik.org/docs/setup-auto-archiving/");
        }
        $this->log("- Reports for today will be processed at most every " . $this->todayArchiveTimeToLive
            . " seconds. You can change this value in Piwik UI > Settings > General Settings.");
        $this->log("- Reports for the current week/month/year will be refreshed at most every "
            . $this->processPeriodsMaximumEverySeconds . " seconds.");

        // Try and not request older data we know is already archived
        if ($this->lastSuccessRunTimestamp !== false) {
            $dateLast = time() - $this->lastSuccessRunTimestamp;
            $this->log("- Archiving was last executed without error " . \Piwik\MetricsFormatter::getPrettyTimeFromSeconds($dateLast, true, $isHtml = false) . " ago");
        }
    }

    /**
     * Returns the delay in seconds, that should be enforced, between calling archiving for Periods Archives.
     * It can be set by --force-timeout-for-periods=X
     *
     * @return int
     */
    private function getDelayBetweenPeriodsArchives()
    {
        if (empty($this->forceTimeoutPeriod)) {
            return self::SECONDS_DELAY_BETWEEN_PERIOD_ARCHIVES;
        }

        // Ensure the cache for periods is at least as high as cache for today
        if ($this->forceTimeoutPeriod > $this->todayArchiveTimeToLive) {
            return $this->forceTimeoutPeriod;
        }

        $this->log("WARNING: Automatically increasing --force-timeout-for-periods from {$this->forceTimeoutPeriod} to "
            . $this->todayArchiveTimeToLive
            . " to match the cache timeout for Today's report specified in Piwik UI > Settings > General Settings");
        return $this->todayArchiveTimeToLive;
    }

    private function isShouldArchiveAllSitesWithTrafficSince()
    {
        if (empty($this->shouldArchiveAllPeriodsSince)) {
            return false;
        }
        if (is_numeric($this->shouldArchiveAllPeriodsSince)
            && $this->shouldArchiveAllPeriodsSince > 1
        ) {
            return (int)$this->shouldArchiveAllPeriodsSince;
        }
        return true;
    }

    /**
     * @param $idSite
     */
    protected function setSiteIsArchived($idSite)
    {
        $websiteIdsInvalidated = APICoreAdminHome::getWebsiteIdsToInvalidate();
        if (count($websiteIdsInvalidated)) {
            $found = array_search($idSite, $websiteIdsInvalidated);
            if ($found !== false) {
                unset($websiteIdsInvalidated[$found]);
                Option::set(APICoreAdminHome::OPTION_INVALIDATED_IDSITES, serialize($websiteIdsInvalidated));
            }
        }
    }

    private function logFatalErrorUrlExpected()
    {
        $this->logFatalError("./console core:archive expects the argument 'url' to be set to your Piwik URL, for example: --url=http://example.org/piwik/ "
            . "\n--help for more information");
    }

    private function getVisitsLastPeriodFromApiResponse($stats)
    {
        if(empty($stats)) {
            return 0;
        }
        $today = end($stats);
        return $today['nb_visits'];
    }

    private function getVisitsFromApiResponse($stats)
    {
        if(empty($stats)) {
            return 0;
        }
        $visits = 0;
        foreach($stats as $metrics) {
            if(empty($metrics['nb_visits'])) {
                continue;
            }
            $visits += $metrics['nb_visits'];
        }
        return $visits;
    }

    /**
     * @param $idSite
     * @param $period
     * @param $lastTimestampWebsiteProcessed
     * @return float|int|true
     */
    private function getApiDateParameter($idSite, $period, $lastTimestampWebsiteProcessed = false)
    {
        $dateRangeForced = $this->getDateRangeToProcess();
        if(!empty($dateRangeForced)) {
            return $dateRangeForced;
        }
        return $this->getDateLastN($idSite, $period, $lastTimestampWebsiteProcessed);
    }

    /**
     * @param $idSite
     * @param $period
     * @param $date
     * @param $visitsInLastPeriods
     * @param $visitsToday
     * @param $timer
     */
    private function logArchivedWebsite($idSite, $period, $date, $segment, $visitsInLastPeriods, $visitsToday)
    {
        if(substr($date, 0, 4) === 'last') {
            $visitsInLastPeriods = (int)$visitsInLastPeriods . " visits in last " . $date . " " . $period . "s, ";
            $thisPeriod = $period == "day" ? "today" : "this " . $period;
            $visitsInLastPeriod = (int)$visitsToday . " visits " . $thisPeriod . ", ";
        } else {
            $visitsInLastPeriods = (int)$visitsInLastPeriods . " visits in " . $period . "s included in: $date, ";
            $visitsInLastPeriod = '';
        }

        $this->log("Archived website id = $idSite, period = $period, "
            . $visitsInLastPeriods
            . $visitsInLastPeriod
            . " [segment = $segment]"
            ); // TODO: used to use $timer
    }

    private function getDateRangeToProcess()
    {
        if (empty($this->restrictToDateRange)) {
            return false;
        }
        if (strpos($this->restrictToDateRange, ',') === false) {
            throw new Exception("--force-date-range expects a date range ie. YYYY-MM-DD,YYYY-MM-DD");
        }
        return $this->restrictToDateRange;
    }

    /**
     * @return array
     */
    private function getPeriodsToProcess()
    {
        $this->restrictToPeriods = array_intersect($this->restrictToPeriods, $this->getDefaultPeriodsToProcess());
        $this->restrictToPeriods = array_intersect($this->restrictToPeriods, PeriodFactory::getPeriodsEnabledForAPI());
        return $this->restrictToPeriods;
    }

    /**
     * @return array
     */
    private function getDefaultPeriodsToProcess()
    {
        return array('day', 'week', 'month', 'year');
    }

    /**
     * @param $idSite
     * @return bool
     */
    private function isOldReportInvalidatedForWebsite($idSite)
    {
        return in_array($idSite, $this->idSitesInvalidatedOldReports);
    }

    private function shouldProcessPeriod($period)
    {
        if(empty($this->shouldArchiveOnlySpecificPeriods)) {
            return true;
        }
        return in_array($period, $this->shouldArchiveOnlySpecificPeriods);
    }

    /**
     * @param $idSite
     * @param $period
     * @param $lastTimestampWebsiteProcessed
     * @return string
     */
    private function getDateLastN($idSite, $period, $lastTimestampWebsiteProcessed)
    {
        $dateLastMax = self::DEFAULT_DATE_LAST;
        if ($period == 'year') {
            $dateLastMax = self::DEFAULT_DATE_LAST_YEARS;
        } elseif ($period == 'week') {
            $dateLastMax = self::DEFAULT_DATE_LAST_WEEKS;
        }
        if (empty($lastTimestampWebsiteProcessed)) {
            $lastTimestampWebsiteProcessed = strtotime(\Piwik\Site::getCreationDateFor($idSite));
        }

        // Enforcing last2 at minimum to work around timing issues and ensure we make most archives available
        $dateLast = floor((time() - $lastTimestampWebsiteProcessed) / 86400) + 2;
        if ($dateLast > $dateLastMax) {
            $dateLast = $dateLastMax;
        }

        if (!empty($this->dateLastForced)) {
            $dateLast = $this->dateLastForced;
        }
        return "last" . $dateLast;
    }

    private function shouldSkipWebsite($idSite)
    {
        return in_array($idSite, $this->shouldSkipSpecifiedSites);
    }

    // TODO: need to log time of archiving for websites (in summary)
    /**
     * @param $idSite
     * @return void
     */
    private function queueDayArchivingJobsForSite($idSite)
    {
        if ($this->shouldSkipWebsite($idSite)) {
            $this->log("Skipped website id $idSite, found in --skip-idsites");

            ++$this->skipped;
            return;
        }

        if ($idSite <= 0) {
            $this->log("Found strange site ID: '$idSite', skipping");

            ++$this->skipped;
            return;
        }

        $this->updateIdSitesInvalidatedOldReports();

        // Test if we should process this website
        if ($this->siteArchivingInfo->getShouldSkipDayArchive($idSite)) {
            $this->log("Skipped website id $idSite, already done "
                . $this->siteArchivingInfo->getElapsedTimeSinceLastArchiving($idSite, $pretty = true)
                . " ago");

            $this->skippedDayArchivesWebsites++;
            $this->skipped++;

            return;
        }

        if (!$this->shouldProcessPeriod("day")) {
            // skip day archiving and proceed to period processing
            $this->queuePeriodAndSegmentArchivingFor($idSite);
            return;
        }

        // Remove this website from the list of websites to be invalidated
        // since it's now just about to being re-processed, makes sure another running cron archiving process
        // does not archive the same idSite
        if ($this->isOldReportInvalidatedForWebsite($idSite)) {
            $this->setSiteIsArchived($idSite);
        }

        // when some data was purged from this website
        // we make sure we query all previous days/weeks/months
        $processDaysSince = $this->siteArchivingInfo->getLastTimestampWebsiteProcessedDay($idSite);
        if ($this->isOldReportInvalidatedForWebsite($idSite)
            // when --force-all-websites option,
            // also forces to archive last52 days to be safe
            || $this->shouldArchiveAllSites
        ) {
            $processDaysSince = false;
        }

        $date = $this->getApiDateParameter($idSite, "day", $processDaysSince);
        $this->queue->enqueue(array($this->getVisitsRequestUrl($idSite, "day", $date)));
    }

    private function queuePeriodAndSegmentArchivingFor($idSite)
    {
        $dayDate = $this->getApiDateParameter($idSite, 'day', $this->siteArchivingInfo->getLastTimestampWebsiteProcessedDay($idSite));
        $this->queueSegmentsArchivingFor($idSite, 'day', $dayDate);

        foreach (array('week', 'month', 'year') as $period) {
            if (!$this->shouldProcessPeriod($period)) {
                /* TODO:
                // if any period was skipped, we do not mark the Periods archiving as successful
                */
                continue;
            }

            $date = $this->getApiDateParameter($idSite, $period, $this->siteArchivingInfo->getLastTimestampWebsiteProcessedPeriods($idSite));

            $url  = $this->piwikUrl;
            $url .= $this->getVisitsRequestUrl($idSite, $period, $date);
            $url .= self::APPEND_TO_API_REQUEST;

            $this->queue->enqueue(array($url));

            $this->queueSegmentsArchivingFor($idSite, $period, $date);
        }
    }

    /**
     * @return void
     */
    public function responseFinished($urlString, $textResponse)
    {
        $url = UrlHelper::getArrayFromQueryString($urlString);
        if (empty($url['idSite'])
            || empty($url['date'])
            || empty($url['period'])
        ) {
            return;
        }

        // TODO: rename Consumer to Processor
        // TODO: if another job processor is run on another machine, it won't execute this logic...

        $idSite = $url['idSite'];
        $date   = $url['date'];
        $period = $url['period'];
        $segment = empty($url['segment']) ? null : $url['segment'];

        $response = @unserialize($textResponse);

        $visits = $visitsLast = 0;
        $isResponseValid = true;

        if (empty($textResponse)
            || !$this->checkResponse($textResponse, $urlString)
            || !is_array($response)
            || count($response) == 0
        ) {
            $isResponseValid = false;
        } else {
            $visits = $this->getVisitsLastPeriodFromApiResponse($response);
            $visitsLast = $this->getVisitsFromApiResponse($response);
        }

        if ($isResponseValid) {
            $this->siteArchivingInfo->getActiveRequestsSemaphore($idSite)->decrement();
        }

        // if archiving for a 'day' period finishes, check if there are visits and if so,
        // launch archiving for other periods and segments for the site
        if ($url['period'] === 'day') {
            if (!$isResponseValid) {
                $this->logError("Empty or invalid response '$textResponse' for website id $idSite, skipping period and segment archiving");
                $this->skipped++;
                return;
            }

            $shouldArchivePeriods = $this->siteArchivingInfo->getShouldArchivePeriods($idSite);

            // If there is no visit today and we don't need to process this website, we can skip remaining archives
            if ($visits == 0
                && !$shouldArchivePeriods
            ) {
                $this->log("Skipped website id $idSite, no visit today");
                $this->skipped++;
                return;
            }

            if ($visitsLast == 0
                && !$shouldArchivePeriods
                && $this->shouldArchiveAllSites
            ) {
                $this->log("Skipped website id $idSite, no visits in the last " . $date . " days");
                $this->skipped++;
                return;
            }

            if (!$shouldArchivePeriods) {
                $this->log("Skipped website id $idSite periods processing, already done "
                    . $this->siteArchivingInfo->getElapsedTimeSinceLastArchiving($idSite, $pretty = true)
                    . " ago");
                $this->skippedDayArchivesWebsites++;
                $this->skipped++;
                return;
            }

            // mark 'day' period as successfully archived
            Option::set($this->lastRunKey($idSite, "day"), time());

            $this->siteArchivingInfo->getFailedRequestsSemaphore($idSite)->decrement();

            $this->visitsToday += $visits;
            $this->websitesWithVisitsSinceLastRun++;

            $this->queuePeriodAndSegmentArchivingFor($idSite); // TODO: all queuing must increase site's active request semaphore
        } else {
            if (!$isResponseValid) {
                $this->logError("Error unserializing the following response from $urlString: " . $textResponse);

                return;
            }

            $failedRequestsCount = $this->siteArchivingInfo->getFailedRequestsSemaphore($idSite);
            $failedRequestsCount->decrement();

            if ($failedRequestsCount->isEqual(0)) {
                Option::set($this->lastRunKey($idSite, "periods"), time());

                $this->archivedPeriodsArchivesWebsite++; // TODO: need to double check all metrics are counted correctly
                                                         // for example, this incremented only when success or always?
            }
        }

        $this->logArchivedWebsite($idSite, $period, $date, $segment, $visits, $visitsLast); // TODO no timer

        if ($this->siteArchivingInfo->getActiveRequestsSemaphore($idSite)->isEqual(0)) {
            $processedWebsitesCount = $this->siteArchivingInfo->getProcessedWebsitesSemaphore();
            $processedWebsitesCount->increment();

            Log::info("Archived website id = $idSite, "
                //. $requestsWebsite . " API requests, " TODO: necessary to report?
                // TODO: . $timerWebsite->__toString()
                . " [" . $processedWebsitesCount->get() . "/"
                . count($this->websites)
                . " done]");
        }
    }

    private function queueSegmentsArchivingFor($idSite, $period, $date)
    {
        $baseUrl  = $this->piwikUrl;
        $baseUrl .= $this->getVisitsRequestUrl($idSite, $period, $date);

        foreach ($this->getSegmentsForSite($idSite) as $segment) {
            $urlWithSegment = $baseUrl . '&segment=' . urlencode($segment);

            $this->queue->enqueue(array($urlWithSegment));
        }
        // $cliMulti->setAcceptInvalidSSLCertificate($this->acceptInvalidSSLCertificate); // TODO: support in consumer
    }

    private function isContinuationOfArchivingJob()
    {
        return $this->queue->peek() > 0;
    }
}