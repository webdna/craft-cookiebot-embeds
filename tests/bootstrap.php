<?php

// The unit tests need Yii's and Craft's classes loaded, not a running app: nothing under test may
// call Craft::$app (profile, Traps).
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Craft's FileHelper reads its MIME types (PageFilter::isHtml) from @app/config/mimeTypes.php, a
// file that calls Craft::getAlias(). Craft sets @app to its own src directory when it boots; here
// the class is loaded and the alias set by hand, still with no app.
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
Yii::setAlias('@app', dirname(__DIR__) . '/vendor/craftcms/cms/src');
