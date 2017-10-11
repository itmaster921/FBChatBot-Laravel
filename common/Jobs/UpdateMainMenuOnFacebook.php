<?php namespace Common\Jobs;

use Exception;
use Common\Models\Bot;
use MongoDB\BSON\ObjectID;
use Common\Services\FacebookAdapter;
use Common\Services\FacebookMessageMapper;
use Common\Exceptions\DisallowedBotOperation;

class UpdateMainMenuOnFacebook extends BaseJob
{

    /**
     * @type Bot
     */
    private $bot;

    protected $pushErrorsToFrontendOnFail = true;
    protected $frontendFailMessageBody = "Failed to update the main menu on Facebook. Please try again later!";

    /**
     * UpdateMainMenuOnFacebook constructor.
     * @param Bot      $bot
     * @param ObjectID $userId
     */
    public function __construct(Bot $bot, ObjectID $userId)
    {
        $this->bot = $bot;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     * @param FacebookAdapter $FacebookAdapter
     * @throws Exception
     */
    public function handle(FacebookAdapter $FacebookAdapter)
    {
        $this->setSentryContext($this->bot->_id);
        $messages = (new FacebookMessageMapper($this->bot))->mapMainMenuButtons();

        try {
            $response = $FacebookAdapter->setPersistentMenu($this->bot, $messages);
        } catch (DisallowedBotOperation $e) {
            return;
        }

        $success = isset($response->result) && starts_with($response->result, "Successfully");

        if (! $success) {
            throw new Exception("{$this->frontendFailMessageBody}. Facebook response: {$response->result}");
        }
    }
}