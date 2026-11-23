<?php

namespace webdna\cookiebotembeds\controllers;

use Craft;
use craft\filters\UtilityAccess;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use webdna\cookiebotembeds\records\Sighting;
use webdna\cookiebotembeds\services\Report;
use webdna\cookiebotembeds\utilities\Report as ReportUtility;
use yii\web\Response;

/**
 * The report's clear action (BR-25): POST with CSRF, for users with access to the report utility.
 * A user without it gets 403 (UtilityAccess); a request without a valid CSRF token gets 400
 * (Craft's controller CSRF validation) (TN-15).
 */
class ReportController extends Controller
{
    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            [
                'class' => UtilityAccess::class,
                'utility' => ReportUtility::class,
            ],
        ]);
    }

    /**
     * Deletes every report row for every site, and starts a new throttle generation so the next
     * page views record again at once rather than an hour later.
     */
    public function actionClear(): ?Response
    {
        $this->requirePostRequest();

        Db::delete(Sighting::TABLE);
        Craft::$app->getCache()->set(Report::GENERATION_KEY, StringHelper::randomString(12));

        return $this->asSuccess(
            Craft::t('cookiebot-embeds', 'Report cleared.'),
            redirect: UrlHelper::cpUrl('utilities/' . ReportUtility::id()),
        );
    }
}
