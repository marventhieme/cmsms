<?php

declare(strict_types=1);

namespace NotificationChannels\Cmsms;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Arr;
use NotificationChannels\Cmsms\Exceptions\CouldNotSendNotification;

class CmsmsClient
{
    public const GATEWAY_URL = 'https://gw.cmtelecom.com/v1.0/message';

    public function __construct(
        protected GuzzleClient $client,
        protected string $productToken,
    ) {
    }

    public function send(CmsmsMessage $message, string $recipient): void
    {
        if (is_null(Arr::get($message->toXmlArray(), 'FROM'))) {
            $message->originator(config('services.cmsms.originator'));
        }

        $response = $this->client->request('POST', static::GATEWAY_URL, [
            'body' => $this->buildMessageJson($message, $recipient),
            'headers' => [
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ],
        ]);

        /**
         * If error code is 0, the message was sent successfully.
         */
        $body = $response->getBody()->getContents();
        $errorCode = Arr::get(json_decode($body, true), 'errorCode');
        if ($errorCode !== 0) {
            throw CouldNotSendNotification::serviceRespondedWithAnError($body);
        }
    }

    /**
     * See: https://developers.cm.com/messaging/reference/messages_sendmessage-1
     */
    public function buildMessageJson(CmsmsMessage $message, string $recipient): string
    {
        $encodingDetectionType = config('services.cmsms.encoding_detection_type', 'AUTO');

        $body = [
            'content' => $message->getBody(),
        ];
        if (strtoupper($encodingDetectionType) === 'AUTO') {
            $body['type'] = 'AUTO';
        }

        $json = [
            'messages' => [
                'authentication' => [
                    'productToken' => $this->productToken,
                ],
                'msg' => [[
                    'body' => $body,
                    'to' => [[
                        'number' => $recipient,
                    ]],
                    'dcs' => strtoupper($encodingDetectionType) === 'AUTO' ? 0 : $encodingDetectionType,
                    'from' => $message->getOriginator(),
                ]],
            ],
        ];

        return json_encode($json);
    }
}
