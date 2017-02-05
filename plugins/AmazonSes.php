<?php
/**
 * AmazonSes plugin for phplist.
 *
 * This file is a part of AmazonSes Plugin.
 *
 * This plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @category  phplist
 *
 * @author    Duncan Cameron
 * @copyright 2016-2017 Duncan Cameron
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License, Version 3
 */

/**
 * Registers the plugin with phplist.
 */
if (!interface_exists('EmailSender')) {
    echo 'AmazonSes plugin requires phplist 3.3.0+';

    return;
}

class AmazonSes extends phplistPlugin implements EmailSender
{
    const VERSION_FILE = 'version.txt';

    private $totalSuccess = 0;
    private $totalFailure = 0;
    private $useMulti = false;
    private $multiLimit = 0;
    private $mc = null;
    private $calls = [];

    /*
     *  Inherited variables
     */
    public $name = 'Amazon SES Plugin';
    public $authors = 'Duncan Cameron';
    public $description = 'Use Amazon SES to send emails';
    public $documentationUrl = 'https://resources.phplist.com/plugin/amazonses';
    public $settings = [
        'amazonses_secret_key' => [
            'value' => '',
            'description' => 'AWS secret access key',
            'type' => 'text',
            'allowempty' => false,
            'category' => 'Amazon SES',
        ],
        'amazonses_access_key' => [
            'value' => '',
            'description' => 'AWS access key ID',
            'type' => 'text',
            'allowempty' => false,
            'category' => 'Amazon SES',
        ],
        'amazonses_endpoint' => [
            'value' => 'https://email.us-east-1.amazonaws.com/',
            'description' => 'SES endpoint',
            'type' => 'text',
            'allowempty' => false,
            'category' => 'Amazon SES',
        ],
        'amazonses_multi' => [
            'value' => false,
            'description' => 'Whether to use curl multi to send emails concurrently',
            'type' => 'boolean',
            'allowempty' => true,
            'category' => 'Amazon SES',
        ],
        'amazonses_multi_limit' => [
            'value' => 4,
            'min' => 2,
            'max' => 32,
            'description' => 'The maximum number of emails to send concurrently when using curl multi, (between 2 and 32)',
            'type' => 'integer',
            'allowempty' => false,
            'category' => 'Amazon SES',
        ],
        'amazonses_multi_log' => [
            'value' => false,
            'description' => 'Whether to create a log file showing all curl multi transfers',
            'type' => 'boolean',
            'allowempty' => true,
            'category' => 'Amazon SES',
        ],
        'amazonses_curl_verbose' => [
            'value' => false,
            'description' => 'Whether to generate verbose curl output (use only for debugging)',
            'type' => 'boolean',
            'allowempty' => true,
            'category' => 'Amazon SES',
        ],
    ];

    private function initialiseCurl()
    {
        global $tmpdir;

        if (($curl = curl_init()) === false) {
            throw new Exception('Amazon SES unable to create curl handle');
        }
        curl_setopt($curl, CURLOPT_URL, getConfig('amazonses_endpoint'));
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_DNS_USE_GLOBAL_CACHE, true);
        curl_setopt($curl, CURLOPT_USERAGENT, NAME . ' (phpList version ' . VERSION . ', http://www.phplist.com/)');
        curl_setopt($curl, CURLOPT_POST, 1);

        if (getConfig('amazonses_curl_verbose')) {
            curl_setopt($curl, CURLOPT_VERBOSE, true);
            $log = fopen(sprintf('%s/curl_%s.log', $tmpdir, date('Y-m-d')), 'a+');
            curl_setopt($curl, CURLOPT_STDERR, $log);
        }

        return $curl;
    }

    private function sesRequest($phplistmailer, $messageheader, $messagebody)
    {
        global $message_envelope;

        $messageheader = preg_replace('/' . $phplistmailer->LE . '$/', '', $messageheader);
        $rawMessage = base64_encode($messageheader . $phplistmailer->LE . $phplistmailer->LE . $messagebody);

        return [
            'Action' => 'SendRawEmail',
            'Source' => $message_envelope,
            'Destinations.member.1' => $phplistmailer->destinationemail,
            'RawMessage.Data' => $rawMessage,
        ];
    }

    private function httpHeaders()
    {
        $date = date('r');
        $aws_signature = base64_encode(hash_hmac('sha256', $date, $this->secretKey, true));

        return [
            'Host: ' . $this->host,
            'Content-Type: application/x-www-form-urlencoded',
            'Date: ' . $date,
            sprintf(
                'X-Amzn-Authorization: AWS3-HTTPS AWSAccessKeyId=%s,Algorithm=HMACSHA256,Signature=%s',
                $this->accessKey,
                $aws_signature
            ),
        ];
    }

    /**
     * Waits for a call to complete.
     *
     * @param array $call
     */
    private function waitForCallToComplete(array $call)
    {
        $manager = $call['manager'];
        $code = $manager->code;

        if ($code == 200) {
            ++$this->totalSuccess;
        } else {
            ++$this->totalFailure;
            logEvent(sprintf('Amazon SES status %s email %s', $code, $call['email']));
        }
    }

    /**
     * Waits for each outstanding call to complete.
     * Writes the sequence of calls to a log file.
     */
    private function completeCalls()
    {
        global $tmpdir;

        while (count($this->calls) > 0) {
            $this->waitForCallToComplete(array_shift($this->calls));
        }

        if (getConfig('amazonses_multi_log')) {
            file_put_contents("$tmpdir/multicurl.log", $this->mc->getSequence()->renderAscii());
        }
        logEvent(sprintf('Amazon SES multi-curl successes: %d, failures: %d', $this->totalSuccess, $this->totalFailure));
    }

    /**
     * Send an email using the Amazon SES API.
     * This method uses curl multi to send multiple emails concurrently.
     *
     * @param PHPlistMailer $phplistmailer mailer instance
     * @param string        $messageheader the message http headers
     * @param string        $messagebody   the message body
     *
     * @return bool success/failure
     */
    private function multiSend($phplistmailer, $messageheader, $messagebody)
    {
        if ($this->mc === null) {
            $this->mc = JMathai\PhpMultiCurl\MultiCurl::getInstance();
            register_shutdown_function([$this, 'shutdown']);
        }

        /*
         * if the limit has been reached then wait for the oldest call
         * to complete
         */
        if (count($this->calls) == $this->multiLimit) {
            $this->waitForCallToComplete(array_shift($this->calls));
        }

        $curl = $this->initialiseCurl();
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->httpHeaders());
        $sesRequest = $this->sesRequest($phplistmailer, $messageheader, $messagebody);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($sesRequest, null, '&'));

        $this->calls[] = [
            'manager' => $this->mc->addCurl($curl),
            'email' => $phplistmailer->destinationemail,
        ];

        return true;
    }

    /**
     * Send an email using the Amazon SES API.
     * This method uses curl directly with an optimisation of re-using
     * the curl handle.
     *
     * @param PHPlistMailer $phplistmailer mailer instance
     * @param string        $messageheader the message http headers
     * @param string        $messagebody   the message body
     *
     * @return bool success/failure
     */
    private function singleSend($phplistmailer, $messageheader, $messagebody)
    {
        static $curl = null;

        if ($curl === null) {
            $curl = $this->initialiseCurl();
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->httpHeaders());
        $sesRequest = $this->sesRequest($phplistmailer, $messageheader, $messagebody);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($sesRequest, null, '&'));

        $res = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($res === false || $status != 200) {
            $error = curl_error($curl);
            logEvent(sprintf('Amazon SES status: %s, result: %s, curl error: %s', $status, strip_tags($res), $error));
            curl_close($curl);
            $curl = null;

            return false;
        }

        return true;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->coderoot = dirname(__FILE__) . '/' . 'AmazonSes' . '/';
        parent::__construct();
        $this->version = (is_file($f = $this->coderoot . self::VERSION_FILE))
            ? file_get_contents($f)
            : '';
    }

    /**
     * On plugin activation.
     */
    public function activate()
    {
        parent::activate();
        $this->useMulti = (bool) getConfig('amazonses_multi');
        $this->multiLimit = (int) getConfig('amazonses_multi_limit');
        $this->host = parse_url(getConfig('amazonses_endpoint'), PHP_URL_HOST);
        $this->accessKey = getConfig('amazonses_access_key');
        $this->secretKey = getConfig('amazonses_secret_key');
    }

    /**
     * Provide the dependencies for enabling this plugin.
     *
     * @return array
     */
    public function dependencyCheck()
    {
        return [
            'PHP version 5.4.0 or greater' => version_compare(PHP_VERSION, '5.4') > 0,
            'curl extension installed' => extension_loaded('curl'),
            'Common Plugin installed' => phpListPlugin::isEnabled('CommonPlugin'),
            'phpList 3.3.0 or greater' => version_compare(VERSION, '3.3') > 0,
        ];
    }

    /**
     * Complete any outstanding multi-curl calls.
     * Any emails sent after this point will use single send.
     */
    public function shutdown()
    {
        if ($this->mc !== null) {
            $this->completeCalls();
            $this->mc = null;
            $this->useMulti = false;
        }
    }

    /**
     * Send an email using the Amazon SES API.
     * This method redirects to send single or multiple emails.
     *
     * @see
     *
     * @param PHPlistMailer $phplistmailer mailer instance
     * @param string        $messageheader the message http headers
     * @param string        $messagebody   the message body
     *
     * @return bool success/failure
     */
    public function send(PHPlistMailer $phplistmailer, $messageheader, $messagebody)
    {
        try {
            return $this->useMulti
                ? $this->multiSend($phplistmailer, $messageheader, $messagebody)
                : $this->singleSend($phplistmailer, $messageheader, $messagebody);
        } catch (Exception $e) {
            logEvent($e->getMessage());

            return false;
        }
    }

    /**
     * This hook is called within the processqueue shutdown() function.
     *
     * For command line processqueue phplist exits in its shutdown function
     * therefore need to explicitly call our plugin
     */
    public function processSendStats($sent = 0, $invalid = 0, $failed_sent = 0, $unconfirmed = 0, $counters = array())
    {
        $this->shutdown();
    }
}
