<?php
/**
 * Inline Games - Telegram Bot (@inlinegamesbot)
 *
 * (c) 2017 Jack'lul <jacklulcat@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Bot\Helper\Monolog\Handler;

use Bot\Helper\Debug;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * Class TelegramBotAdminHandler
 *
 * @package Bot\Helper\Monolog\Handler
 */
class TelegramBotAdminHandler extends AbstractProcessingHandler
{
    /**
     * Telegram object
     *
     * @var \Longman\TelegramBot\Telegram
     */
    private $telegram;

    /**
     * Bot ID
     *
     * @var int
     */
    private $bot_id;

    /**
     * Last error's timestamp
     *
     * @var int
     */
    private $last_timestamp = null;

    /**
     * TelegramHandler constructor
     *
     * @param Telegram $telegram
     * @param bool|int $level
     * @param bool     $bubble
     */
    public function __construct(Telegram $telegram, $level = Logger::ERROR, $bubble = true)
    {
        $this->telegram = $telegram;
        $this->bot_id = $telegram->getBotId();

        parent::__construct($level, $bubble);
    }

    /**
     * Send log message to bot admins
     *
     * @param  array $record
     * @return bool
     */
    protected function write(array $record)
    {
        if (time() == $this->last_timestamp) {
            sleep(1);       // prevent hitting Telegram's flood block
        }

        $cached_error_file = sys_get_temp_dir() . '/' . $this->bot_id . '_last_error.tmp';

        if (!is_writable($cached_error_file)) {
            if (!is_dir(DATA_PATH . '/tmp')) {
                mkdir(DATA_PATH . '/tmp', 0755, true);
            }

            $cached_error_file = DATA_PATH . '/tmp/' . $this->bot_id . '_last_error.tmp';
        }

        if (file_exists($cached_error_file)) {
            $previous_reports = file_get_contents($cached_error_file);
            $previous_reports = json_decode($previous_reports, true);
        }

        $message_check = preg_split("/\\r\\n|\\r|\\n/", $record['message'])[0];

        // Do not send already reported error messages for 1 day
        if (isset($previous_reports) && is_array($previous_reports)) {
            foreach ($previous_reports as $previous_report) {
                if ($previous_report['message'] === $message_check && $previous_report['time'] + 86400 > $record['datetime']->format('U')) {
                    Debug::print('Log report prevented because the message is a duplicate of the previous report');
                    return false;
                }
            }
        } else {
            $previous_reports = [];
        }

        $success = false;
        foreach ($this->telegram->getAdminList() as $admin) {
            if ($admin != $this->bot_id) {
                $result = Request::sendMessage([
                    'chat_id' => $admin,
                    'text' => '<b>' . $record['level_name'] . ' (' . $record['datetime']->format('H:i:s d-m-Y') . ')</b>' . PHP_EOL . '<code>' . htmlentities($record['message']) . '</code>',
                    'parse_mode' => 'HTML',
                ]);

                if ($result->isOk()) {
                    $success = true;
                }
            }
        }

        if ($success) {
            array_push($previous_reports, ['message' => $message_check, 'time' => $record['datetime']->format('U')]);

            while (count($previous_reports) > 100) {
                array_shift($previous_reports);
            }

            file_put_contents($cached_error_file, json_encode($previous_reports));
        } else {
            print($record['message']);  // log error to console instead
        }

        $this->last_timestamp = time();

        return true;
    }
}
