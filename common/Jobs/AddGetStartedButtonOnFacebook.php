<?php namespace Common\Jobs;

use Exception;
use Common\Models\Bot;
use Common\Services\Facebook;
use Common\Services\FacebookAdapter;
use Common\Exceptions\DisallowedBotOperation;
use MongoDB\BSON\ObjectID;

class AddGetStartedButtonOnFacebook extends BaseJob
{

    /**
     * @type Bot
     */
    private $bot;
    protected $pushErrorsToFrontendOnFail = true;
    protected $frontendFailMessageBody = "Failed to add Get Started Button!";

    /**
     * AddGetStartedButtonOnFacebook constructor.
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

        try {
            $response = $FacebookAdapter->addGetStartedButton($this->bot);
        } catch (DisallowedBotOperation $e) {
            return;
        }
        $success = isset($response->result) && starts_with($response->result, "Successfully");
        if (! $success) {
            throw new Exception("{$this->frontendFailMessageBody}. Facebook response: {$response->result}");
        }
    }
}
