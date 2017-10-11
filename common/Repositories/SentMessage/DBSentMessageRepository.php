<?php namespace Common\Repositories\SentMessage;

use Carbon\Carbon;
use Common\Models\Bot;
use Common\Models\Card;
use Common\Models\Button;
use Common\Models\Message;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDatetime;
use Common\Models\Subscriber;
use Common\Models\SentMessage;
use Common\Models\MessageRevision;
use Illuminate\Support\Collection;
use Common\Repositories\DBAssociatedWithBotRepository;

class DBSentMessageRepository extends DBAssociatedWithBotRepository implements SentMessageRepositoryInterface
{

    /**
     * @return string
     */
    public function model()
    {
        return SentMessage::class;
    }

    /**
     * Mark all messages sent to a subscriber before a specific date as delivered.
     * @param Subscriber  $subscriber
     * @param UTCDatetime $dateTime
     */
    public function markAsDelivered(Subscriber $subscriber, UTCDatetime $dateTime)
    {
        SentMessage::where('subscriber_id', $subscriber->_id)->whereNull('delivered_at')->where('sent_at', '<=', $dateTime)->update([
            'delivered_at' => $dateTime
        ]);
    }

    /**
     * Mark all messages sent to a subscriber before a specific date as read.
     * @param Subscriber  $subscriber
     * @param UTCDatetime $dateTime
     */
    public function markAsRead(Subscriber $subscriber, UTCDatetime $dateTime)
    {
        SentMessage::where('subscriber_id', $subscriber->_id)->whereNull('read_at')->where('sent_at', '<=', $dateTime)->update([
            'read_at' => $dateTime
        ]);

        //If delivered at is null, set to $dateTime
        SentMessage::where('subscriber_id', $subscriber->_id)->whereNotNull('read_at')->whereNull('delivered_at')->update([
            'delivered_at' => $dateTime
        ]);
    }

    /**
     * @param SentMessage $sentMessage
     * @param array       $cardOrButtonPath
     * @param UTCDatetime $dateTime
     */
    public function recordClick(SentMessage $sentMessage, array $cardOrButtonPath, UTCDatetime $dateTime)
    {
        $path = $this->normalizePath($sentMessage, $cardOrButtonPath) . '.clicks';
        $sentMessage->push($path, $dateTime);
    }

    /**
     * @param SentMessage|Message $model
     * @param array               $path
     * @return string
     * @throws \Exception
     */
    protected function normalizePath($model, array $path)
    {
        if (! $path) {
            return '';
        }

        $index = null;

        $container = $path[0];
        $messages = is_array($model)? $model[$container] : $model->{$container};

        foreach ($messages as $i => $message) {
            if ((string)$message['id'] == $path[1]) {
                $index = $i;
                break;
            }
        }

        if (is_null($index)) {
            throw new \Exception("Invalid button/card path");
        }

        $ret = "{$container}.{$index}";
        if ($temp = $this->normalizePath($messages[$index], array_slice($path, 2))) {
            $ret .= '.' . $temp;
        }

        return $ret;
    }

    /**
     * @param Bot    $bot
     * @param Carbon $startDateTime
     * @param Carbon $endDateTime
     * @return int
     */
    public function totalMessageClicksForBot(Bot $bot, Carbon $startDateTime = null, Carbon $endDateTime = null)
    {
        $matchFilters = [
            '$and' => [
                ['bot_id' => $bot->_id],
                ['buttons' => ['$exists' => true]],
            ]
        ];

        if ($startDateTime) {
            $matchFilters['$and'][] = ['sent_at' => ['$gte' => mongo_date($startDateTime)]];
        }

        if ($endDateTime) {
            $matchFilters['$and'][] = ['sent_at' => ['$lt' => mongo_date($endDateTime)]];
        }

        if (! $startDateTime && ! $endDateTime) {
            $matchFilters['$and'][] = ['sent_at' => ['$ne' => null]];
        }

        $filter = [
            ['$match' => $matchFilters],
            ['$project' => ['buttons' => 1]],
            ['$unwind' => '$buttons'],
            ['$group' => ['_id' => null, 'count' => ['$sum' => ['$size' => '$buttons.clicks']]]]
        ];

        $ret = SentMessage::raw()->aggregate($filter)->toArray();

        return count($ret)? $ret[0]->count : 0;
    }

    /**
     * @param Bot         $bot
     * @param Carbon|null $startDateTime
     * @param Carbon|null $endDateTime
     * @return int
     */
    public function perSubscriberMessageClicksForBot(Bot $bot, Carbon $startDateTime = null, Carbon $endDateTime = null)
    {
        $matchFilters = ['$and' => []];

        if ($startDateTime) {
            $matchFilters['$and'][] = ["buttons.clicks" => ['$gte' => mongo_date($startDateTime)]];
        }

        if ($endDateTime) {
            $matchFilters['$and'][] = ["buttons.clicks" => ['$lt' => mongo_date($endDateTime)]];
        }

        if (! $startDateTime && ! $endDateTime) {
            $matchFilters['$and'][] = ["buttons.clicks.0" => ['$exists' => true]];
        }

        $aggregate = [
            ['$match' => ['$and' => [['bot_id' => $bot->_id], ['buttons' => ['$exists' => true]]]]],
            ['$project' => ['buttons' => 1, 'subscriber_id' => 1]],
            ['$unwind' => '$buttons'],
            ['$match' => $matchFilters],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => null, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Bot         $bot
     * @param Carbon|null $startDateTime
     * @param Carbon|null $endDateTime
     * @return int
     */
    public function totalSentForBot(Bot $bot, Carbon $startDateTime = null, Carbon $endDateTime = null)
    {
        $filters = [['key' => 'bot_id', 'operator' => '=', 'value' => $bot->_id]];

        if ($startDateTime) {
            $filters[] = ['key' => 'sent_at', 'operator' => '>=', 'value' => $startDateTime];
        }

        if ($endDateTime) {
            $filters[] = ['key' => 'sent_at', 'operator' => '<', 'value' => $endDateTime];
        }

        if (! $startDateTime && ! $endDateTime) {
            $matchFilters['$and'][] = ['sent_at' => ['$ne' => null]];
        }

        return $this->count($filters);
    }

    /**
     * @param ObjectID $messageId
     * @param array    $columns
     * @return Collection
     */
    public function getAllForMessage(ObjectID $messageId, $columns = ['*'])
    {
        $filter = [['key' => 'message_id', 'operator' => '=', 'value' => $messageId]];

        return $this->getAll($filter, [], $columns);
    }

    /**
     * @param Subscriber $subscriber
     * @return bool
     */
    public function wasContacted24HoursAfterLastInteraction(Subscriber $subscriber)
    {
        $copy = $subscriber->last_interaction_at->addDay(1);

        return SentMessage::where('subscriber_id', $subscriber->_id)->where('sent_at', '>=', $copy)->exists();
    }

    /**
     * @param Collection $subscribers
     * @return array
     */
    public function followupFilter(Collection $subscribers)
    {
        $subscriberIds = $subscribers->pluck('_id')->toArray();
        $subscribersCopy = $subscribers->keyBy('_id');

        $aggregate = [
            ['$sort' => ['subscriber_id' => 1, 'sent_at' => -1]],
            ['$match' => ['subscriber_id' => ['$in' => $subscriberIds]]],
            ['$group' => ['_id' => '$subscriber_id', 'sent_at' => ['$first' => '$sent_at']]],
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        $ret = [];

        foreach ($result as $document) {
            $lastInteractionAt = $subscribersCopy->get((string)$document->_id)->last_interaction_at;
            $compare = $lastInteractionAt->copy()->addDay();
            $sentAt = carbon_date($document->sent_at);
            if ($sentAt->lt($compare)) {
                $ret[] = $document->_id;
            }
        }

        return $ret;
    }

    /**
     * @param MessageRevision $revision
     * @return int
     */
    public function totalSentForRevision(MessageRevision $revision)
    {
        $filter = [['key' => 'revision_id', 'operator' => '=', 'value' => $revision->_id]];

        return $this->count($filter);
    }

    /**
     * @param MessageRevision $revision
     * @return int
     */
    public function perSubscriberSentForRevision(MessageRevision $revision)
    {
        $aggregate = [
            ['$match' => ['revision_id' => $revision->_id]],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => 1, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param MessageRevision $revision
     * @return int
     */
    public function totalDeliveredForRevision(MessageRevision $revision)
    {
        $filter = [
            ['key' => 'revision_id', 'operator' => '=', 'value' => $revision->_id],
            ['key' => 'delivered_at', 'operator' => '!=', 'value' => null]
        ];

        return $this->count($filter);
    }

    /**
     * @param MessageRevision $revision
     * @return int
     */
    public function perSubscriberDeliveredForRevision(MessageRevision $revision)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['revision_id' => $revision->_id],
                        ['delivered_at' => ['$ne' => null]],
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => 1, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param MessageRevision $revision
     * @return int
     */
    public function totalReadForRevision(MessageRevision $revision)
    {
        $filter = [
            ['key' => 'revision_id', 'operator' => '=', 'value' => $revision->_id],
            ['key' => 'read_at', 'operator' => '!=', 'value' => null]
        ];

        return $this->count($filter);
    }

    /**
     * @param MessageRevision $revision
     * @return int
     */
    public function perSubscriberReadForRevision(MessageRevision $revision)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['revision_id' => $revision->_id],
                        ['read_at' => ['$ne' => null]],
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => 1, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Card            $card
     * @param MessageRevision $revision
     * @return int
     */
    public function totalCardClicksForRevision(Card $card, MessageRevision $revision)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['revision_id' => $revision->_id],
                        ['cards.id' => $card->id],
                    ]
                ]
            ],
            ['$project' => ['cards' => 1]],
            ['$unwind' => '$cards'],
            ['$match' => ['cards.id' => $card->id]],
            ['$group' => ['_id' => null, 'count' => ['$sum' => ['$size' => '$cards.clicks']]]]
        ];

        $ret = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($ret)? $ret[0]->count : 0;
    }

    /**
     * @param Card            $card
     * @param MessageRevision $revision
     * @return int
     */
    public function perSubscriberCardClicksForRevision(Card $card, MessageRevision $revision)
    {

        $aggregate = [
            ['$match' => ['revision_id' => $revision->_id]],
            ['$project' => ['cards' => 1, 'subscriber_id' => 1]],
            ['$unwind' => '$cards'],
            [
                '$match' => [
                    '$and' => [
                        ["cards.id" => $card->id],
                        ["cards.clicks.0" => ['$exists' => true]]
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => null, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Button          $button
     * @param MessageRevision $revision
     * @return int
     */
    public function totalTextMessageButtonClicksForRevision(Button $button, MessageRevision $revision)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['revision_id' => $revision->_id],
                        ['buttons.id' => $button->id],
                    ]
                ]
            ],
            ['$project' => ['buttons' => 1]],
            ['$unwind' => '$buttons'],
            ['$match' => ['buttons.id' => $button->id]],
            ['$group' => ['_id' => null, 'count' => ['$sum' => ['$size' => '$buttons.clicks']]]]
        ];

        $ret = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($ret)? $ret[0]->count : 0;
    }

    /**
     * @param Button          $button
     * @param MessageRevision $revision
     * @return int
     */
    public function perSubscriberTextMessageButtonClicksForRevision(Button $button, MessageRevision $revision)
    {
        $aggregate = [
            ['$match' => ['revision_id' => $revision->_id]],
            ['$project' => ['buttons' => 1, 'subscriber_id' => 1]],
            ['$unwind' => '$buttons'],
            [
                '$match' => [
                    '$and' => [
                        ["buttons.id" => $button->id],
                        ["buttons.clicks.0" => ['$exists' => true]]
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => null, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Button          $button
     * @param Card            $card
     * @param MessageRevision $revision
     * @return int
     */
    public function totalCardButtonClicksForRevision(Button $button, Card $card, MessageRevision $revision)
    {
        $filter = [
            [
                '$match' => [
                    '$and' => [
                        ['revision_id' => $revision->_id],
                        ["cards.id" => $card->id],
                        ["cards.buttons.id" => $button->id]
                    ]
                ]
            ],
            ['$project' => ['cards' => 1]],
            ['$unwind' => '$cards'],
            ['$match' => ['cards.id' => $card->id]],
            ['$unwind' => '$cards.buttons'],
            ['$match' => ['cards.buttons.id' => $button->id]],
            ['$group' => ['_id' => null, 'count' => ['$sum' => ['$size' => '$cards.buttons.clicks']]]]
        ];

        $ret = SentMessage::raw()->aggregate($filter)->toArray();

        return count($ret)? $ret[0]->count : 0;
    }

    /**
     * @param Button          $button
     * @param Card            $card
     * @param MessageRevision $revision
     * @return int
     */
    public function perSubscriberCardButtonClicksForRevision(Button $button, Card $card, MessageRevision $revision)
    {
        $aggregate = [
            ['$match' => ['revision_id' => $revision->_id]],
            ['$project' => ['cards' => 1, 'subscriber_id' => 1]],
            ['$unwind' => '$cards'],
            ['$match' => ['cards.id' => $card->id]],
            ['$unwind' => '$cards.buttons'],
            [
                '$match' => [
                    '$and' => [
                        ['cards.buttons.id' => $button->id],
                        ['cards.buttons.clicks.0' => ['$exists' => true]],
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => null, 'count' => ['$sum' => 1]]]
        ];


        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }


    /**
     * @param Message $message
     * @return int
     */
    public function totalSentForMessage(Message $message)
    {
        $filter = [['key' => 'message_id', 'operator' => '=', 'value' => $message->id]];

        return $this->count($filter);
    }

    /**
     * @param Message $message
     * @return int
     */
    public function perSubscriberSentForMessage(Message $message)
    {
        $aggregate = [
            ['$match' => ['message_id' => $message->id]],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => 1, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Message $message
     * @return int
     */
    public function totalDeliveredForMessage(Message $message)
    {
        $filter = [
            ['key' => 'message_id', 'operator' => '=', 'value' => $message->id],
            ['key' => 'delivered_at', 'operator' => '!=', 'value' => null]
        ];

        return $this->count($filter);
    }

    /**
     * @param Message $message
     * @return int
     */
    public function perSubscriberDeliveredForMessage(Message $message)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['message_id' => $message->id],
                        ['delivered_at' => ['$ne' => null]],
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => 1, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Message $message
     * @return int
     */
    public function totalReadForMessage(Message $message)
    {
        $filter = [
            ['key' => 'message_id', 'operator' => '=', 'value' => $message->id],
            ['key' => 'read_at', 'operator' => '!=', 'value' => null]
        ];

        return $this->count($filter);
    }

    /**
     * @param Message $message
     * @return int
     */
    public function perSubscriberReadForMessage(Message $message)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['message_id' => $message->id],
                        ['read_at' => ['$ne' => null]],
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => 1, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Card    $card
     * @param Message $message
     * @return int
     */
    public function totalCardClicksForMessage(Card $card, Message $message)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['message_id' => $message->id],
                        ['cards.id' => $card->id],
                    ]
                ]
            ],
            ['$project' => ['cards' => 1]],
            ['$unwind' => '$cards'],
            ['$match' => ['cards.id' => $card->id]],
            ['$group' => ['_id' => null, 'count' => ['$sum' => ['$size' => '$cards.clicks']]]]
        ];

        $ret = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($ret)? $ret[0]->count : 0;
    }

    /**
     * @param Card    $card
     * @param Message $message
     * @return int
     */
    public function perSubscriberCardClicksForMessage(Card $card, Message $message)
    {

        $aggregate = [
            ['$match' => ['message_id' => $message->id]],
            ['$project' => ['cards' => 1, 'subscriber_id' => 1]],
            ['$unwind' => '$cards'],
            [
                '$match' => [
                    '$and' => [
                        ["cards.id" => $card->id],
                        ["cards.clicks.0" => ['$exists' => true]]
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => null, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Button  $button
     * @param Message $message
     * @return int
     */
    public function totalTextMessageButtonClicksForMessage(Button $button, Message $message)
    {
        $aggregate = [
            [
                '$match' => [
                    '$and' => [
                        ['message_id' => $message->id],
                        ['buttons.id' => $button->id],
                    ]
                ]
            ],
            ['$project' => ['buttons' => 1]],
            ['$unwind' => '$buttons'],
            ['$match' => ['buttons.id' => $button->id]],
            ['$group' => ['_id' => null, 'count' => ['$sum' => ['$size' => '$buttons.clicks']]]]
        ];

        $ret = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($ret)? $ret[0]->count : 0;
    }

    /**
     * @param Button  $button
     * @param Message $message
     * @return int
     */
    public function perSubscriberTextMessageButtonClicksForMessage(Button $button, Message $message)
    {
        $aggregate = [
            ['$match' => ['message_id' => $message->id]],
            ['$project' => ['buttons' => 1, 'subscriber_id' => 1]],
            ['$unwind' => '$buttons'],
            [
                '$match' => [
                    '$and' => [
                        ["buttons.id" => $button->id],
                        ["buttons.clicks.0" => ['$exists' => true]]
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => null, 'count' => ['$sum' => 1]]]
        ];

        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param Button  $button
     * @param Card    $card
     * @param Message $message
     * @return int
     */
    public function totalCardButtonClicksForMessage(Button $button, Card $card, Message $message)
    {
        $filter = [
            [
                '$match' => [
                    '$and' => [
                        ['message_id' => $message->id],
                        ["cards.id" => $card->id],
                        ["cards.buttons.id" => $button->id]
                    ]
                ]
            ],
            ['$project' => ['cards' => 1]],
            ['$unwind' => '$cards'],
            ['$match' => ['cards.id' => $card->id]],
            ['$unwind' => '$cards.buttons'],
            ['$match' => ['cards.buttons.id' => $button->id]],
            ['$group' => ['_id' => null, 'count' => ['$sum' => ['$size' => '$cards.buttons.clicks']]]]
        ];

        $ret = SentMessage::raw()->aggregate($filter)->toArray();

        return count($ret)? $ret[0]->count : 0;
    }

    /**
     * @param Button  $button
     * @param Card    $card
     * @param Message $message
     * @return int
     */
    public function perSubscriberCardButtonClicksForMessage(Button $button, Card $card, Message $message)
    {
        $aggregate = [
            ['$match' => ['message_id' => $message->id]],
            ['$project' => ['cards' => 1, 'subscriber_id' => 1]],
            ['$unwind' => '$cards'],
            ['$match' => ['cards.id' => $card->id]],
            ['$unwind' => '$cards.buttons'],
            [
                '$match' => [
                    '$and' => [
                        ['cards.buttons.id' => $button->id],
                        ['cards.buttons.clicks.0' => ['$exists' => true]],
                    ]
                ]
            ],
            ['$group' => ['_id' => '$subscriber_id']],
            ['$group' => ['_id' => null, 'count' => ['$sum' => 1]]]
        ];


        $result = SentMessage::raw()->aggregate($aggregate)->toArray();

        return count($result)? $result[0]->count : 0;
    }

    /**
     * @param ObjectID $id
     * @param int      $cardIndex
     */
    public function recordCardClick(ObjectID $id, $cardIndex)
    {
        SentMessage::where('_id', $id)->push("cards.{$cardIndex}.clicks", mongo_date());
    }

    /**
     * @param ObjectID $id
     * @param int      $buttonIndex
     */
    public function recordTextButtonClick(ObjectID $id, $buttonIndex)
    {
        SentMessage::where('_id', $id)->push("buttons.{$buttonIndex}.clicks", mongo_date());
    }

    /**
     * @param ObjectID $id
     * @param int      $cardIndex
     * @param int      $buttonIndex
     */
    public function recordCardButtonClick(ObjectID $id, $cardIndex, $buttonIndex)
    {
        SentMessage::where('_id', $id)->push("cards.{$cardIndex}.buttons.{$buttonIndex}.clicks", mongo_date());
    }
}
