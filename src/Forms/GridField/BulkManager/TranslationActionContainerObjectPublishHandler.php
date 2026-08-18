<?php

namespace S2Hub\TextAssistant\Forms\GridField\BulkManager;

use Colymba\BulkManager\BulkAction\Handler;
use Colymba\BulkTools\HTTPBulkToolsResponse;
use S2Hub\TextAssistant\Models\TranslationAction;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\DataList;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\State\FluentState;

class TranslationActionContainerObjectPublishHandler extends Handler
{
    private static $url_segment = 'publish';

    private static $allowed_actions = [
        'publish',
    ];

    private static $url_handlers = [
        '' => 'publish',
    ];

    protected $label = 'Publish';

    protected $recordLimit = 25;

    public function getI18nLabel()
    {
        return _t(self::class . '.TITLE', "Publish");
    }

    public function publish(HTTPRequest $request)
    {
        TranslationAction::config()->set('allow_logging', false);
        $response = new HTTPBulkToolsResponse(false, $this->gridField);
        $records = $this->getRecords();

        $count = 0;

        foreach ($records as $record) {

            if ($record instanceof TranslationAction) {

                $actions = DataList::create(TranslationAction::class)
                    ->filter([
                        'ObjectClass' => $record->ObjectClass,
                        'ObjectID' => $record->ObjectID,
                        'Locale' => $record->Locale,
                    ]);

                $object = $record->Object();
            } else {
                $actions = $record->getContainingObjects();
                $object = $record->getObject();

            }

            $this->publishTranslationActionCollection($actions, $object);

            $count++;
        }


        $message = _t(self::class . '.MESSAGE', 'Published {Count} items', [
            'Count' => $count
        ]);

        $response->setMessage($message);


        return $response;
    }

    public function publishTranslationActionCollection(DataList $actions, DataObject $object)
    {
        if ($object->hasExtension(FluentExtension::class)) {
            FluentState::singleton()->withState(function (FluentState $newState) use ($actions, $object) {
                $newState->setLocale($actions->first()->Locale);
 
                // get object in locale
                $object = DataObject::get_by_id($object->ClassName, $object->ID);
 
                if ($object->hasExtension(Versioned::class)) {
                    Versioned::set_stage(Versioned::DRAFT);
 
                    foreach ($actions as $action) {
                        $translatedFieldName = $action->FieldName;
                        $actionValue = $action->getField("Value_" . $action->Locale);
                        $object->setField($translatedFieldName, $actionValue);
                    }
 
                    $object->write();
                    $object->publishRecursive();
                } else {
                    foreach ($actions as $action) {
                        $translatedFieldName = $action->FieldName;
                        $actionValue = $action->getField("Value_" . $action->Locale);
                        $object->setField($translatedFieldName, $actionValue);
                    }
 
                    $object->write();
                }
 
                foreach ($actions as $action) {
                    $action->Status = "Accepted";
                    $action->PublishedPlace = "TranslationAdmin";
                    $action->write();
                }
 
                $object->invokeWithExtensions('onAfterTranslationPublish');
            });

            return;
        }

        if (!$object->hasExtension(Versioned::class)) {
            foreach ($actions as $action) {
                $translatedFieldName = $action->FieldName;
                $actionValue = $action->getField("Value_" . $action->Locale);
                $object->setField($translatedFieldName, $actionValue);
            }

            $object->write();
 
            foreach ($actions as $action) {
                $action->Status = "Accepted";
                $action->PublishedPlace = "TranslationAdmin";
                $action->write();
            }
        }
    }
}