<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

use NW\WebService\References\Operations\Notification\Contractor;
use NW\WebService\References\Operations\Notification\Seller;
use NW\WebService\References\Operations\Notification\Employee;
use NW\WebService\References\Operations\Notification\Status;
use NW\WebService\References\Operations\Notification\ReferencesOperation;
use NW\WebService\References\Operations\Notification\NotificationEvents;

require_once 'functions.php';

class ReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;
    private const RESELLER_EMAIL_FROM = 2;

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $data = (array)$this->getRequest('data');
        $resellerId = $this->validateId($data['resellerId'], 'resellerId');
        $notificationType = $this->validateId($data['notificationType'], 'notificationType');

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => ['isSent' => false, 'message' => ''],
        ];

        // Логика обработки уведомлений
        $emailFrom = getResellerEmailFrom();
        $templateData = $this->prepareTemplateData($data);

        if ($notificationType === self::TYPE_NEW) {
            $this->sendEmployeeNotification($emailFrom, $templateData, $resellerId, $result);
            $this->sendClientNotification($emailFrom, Contractor::getById($resellerId), $templateData, $resellerId, $data, $result);
        } elseif ($notificationType === self::TYPE_CHANGE) {
            $this->sendEmployeeNotification($emailFrom, $templateData, $resellerId, $result);
            $this->sendClientNotification($emailFrom, Contractor::getById($resellerId), $templateData, $resellerId, $data, $result);
        }

        return $result;
    }

    private function validateId($id, $fieldName): int
    {
        if (!is_int($id)) {
            throw new Exception("Invalid {$fieldName}");
        }
        return $id;
    }

    private function sendEmployeeNotification(
        string $emailFrom,
        array $templateData,
        int $resellerId,
        array &$result
    ): void {
        $emails = getEmailsByPermit($resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
        if (!empty($emails)) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage(
                    [
                        [
                            'emailFrom' => $emailFrom,
                            'emailTo' => $email,
                            'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                            'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                        ],
                    ],
                    $resellerId,
                    NotificationEvents::CHANGE_RETURN_STATUS
                );
                $result['notificationEmployeeByEmail'] = true;
            }
        }
    }

    private function sendClientNotification(
        string $emailFrom,
        Contractor $client,
        array $templateData,
        int $resellerId,
        array $data,
        array &$result
    ): void {
        if (!empty($emailFrom) && !empty($client->email)) {
            MessagesClient::sendMessage(
                [
                    [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $client->email,
                        'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ],
                $resellerId,
                $client->id,
                NotificationEvents::CHANGE_RETURN_STATUS,
                (int)$data['differences']['to']
            );
            $result['notificationClientByEmail'] = true;
        }

        if (!empty($client->mobile)) {
            $error = '';
            $res = NotificationManager::send(
                $resellerId,
                $client->id,
                NotificationEvents::CHANGE_RETURN_STATUS,
                (int)$data['differences']['to'],
                $templateData,
                $error
            );

            $result['notificationClientBySms']['isSent'] = (bool)$res;

            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        }
    }

    private function prepareTemplateData(array $data): array
    {
        return [
            'resellerId' => $data['resellerId'],
            'notificationType' => $data['notificationType'],
            'differences' => $data['differences'] ?? []
        ];
    }
}
