<?php

namespace S2Hub\TextAssistant\Jobs;

use Exception;
use S2Hub\TextAssistant\Helpers\TranslationJobHelper;
use S2Hub\TextAssistant\Models\TranslationAction;
use S2Hub\TextAssistant\Models\TranslationAction_ObjectQueue;
use S2Hub\TextAssistant\Services\TextAssistantService;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\Queries\SQLDelete;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use TractorCow\Fluent\State\FluentState;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\Extension\FluentVersionedExtension;
use TractorCow\Fluent\Model\Locale;

class ObjectTranslationJob extends AbstractQueuedJob
{

    /**
     * If the field is chunked, we dont want to write TranslationAction until all chunks are translated.
     * This var will hold the chunks until all are translated and then write TranslationAction.
     */
    private $chunking_object_buffer = [];

    public function getTitle()
    {
        return _t(self::class.'.TITLE', 'Translating..');
    }

    public function setup()
    {
        parent::setup();

        TranslationAction::config()->set('allow_logging', false);
        $remaining = [];

        $ids = $this->jobData->ids;
        $toLocale = $this->jobData->toLocale;
        $fromLocale = $this->jobData->fromLocale;

        if (empty($ids) || empty($toLocale) || empty($fromLocale)) {
            throw new Exception("ids, dataClass, toLocale and fromLocale must all be defined");
        }

        $objects = DataList::create(TranslationAction_ObjectQueue::class)->filter('ID', $ids);

        foreach ($objects as $object) {
            $targetObject = $object->Object();

            if ($targetObject->hasExtension(Versioned::class) && !$targetObject->isPublished()) {
                // If the object is not published, we don't want to translate it.
                continue;
            }

            if ($targetObject->hasExtension(FluentExtension::class)) {
                $remaining = array_merge($remaining, TranslationJobHelper::setupForFluent($targetObject, $fromLocale));
            } else {
                throw new Exception(get_class($targetObject)." had neither Translatable or Fluent");
            }
        }

        $this->remaining = $remaining;

        $this->totalSteps = count($this->remaining);
    }

    public function process()
    {
        TranslationAction::config()->set('allow_logging', false);
        $remaining = $this->remaining;

        // check for trivial case
        if (count($remaining) === 0) {
            $this->isComplete = true;

            return;
        }

        $item = array_shift($remaining);

        if ($item['translation_type'] === "Translatable") {
            $this->translateTranslatable($item);

        } else if ($item['translation_type'] === "Fluent") {
            $this->translateFluent($item);

        }

        // Delete the TranslationAction_ObjectQueue now since it's processed
        // we're intentionally not using GroupIdentifier here so if obj was re-queued it'll just be ignored
        SQLDelete::create(DataObject::getSchema()->tableName(TranslationAction_ObjectQueue::class), [
            'ObjectID' => $item['id'],
            'ObjectClass' => $item['class'],
        ])->execute();


        $this->remaining = $remaining;

        $this->currentStep += 1;

        // check for job completion
        if (count($remaining) > 0) {
            return;
        }

        // Queue runner will mark this job as finished
        $this->isComplete = true;
    }

    public function translateFluent($item)
    {   
        $id = $item['id'];
        $dataClass = $item['class'];
        $toLocale = $this->jobData->toLocale;
        $fromLocale = $this->jobData->fromLocale;
        $fieldName = $item['field'];

        $records = [];
        $allowed_locales = Locale::getCached()->column('Locale');
        foreach ($allowed_locales as $locale) {
            $records[$locale] = FluentState::singleton()->withState(function (FluentState $newState) use ($id, $dataClass, $locale) {
                $newState->setLocale($locale);
                return DataObject::get_by_id($dataClass, $id, false);
            });

        }

        if (!$records[$fromLocale]) {
            $this->addMessage("Record #".$id." not found", 'INFO');
            return;
        }

        $fieldValue = $records[$fromLocale]->getField($fieldName);

        if (empty($fieldValue)) {
            $fieldValue = $records[$fromLocale]->$fieldName;
        }

        if (empty($fieldValue)) {
            $this->addMessage("Field $fieldName is empty on $dataClass#$id ($fromLocale)", 'INFO');

            return;
        }

        // A special case: We want to avoid translation SiteTree with "home" URLSegment
        if ($fieldName === "URLSegment" && strtolower($fieldValue) === "home") {
            $this->addMessage("Field $fieldName is 'home' on #" . $id . ". Skipping this special value.", 'INFO');
            return;
        }

        $is_chunked = false;            // if chunked we want add to the field rather than overwrite the whole field

        if (isset($item['html_chunk'])) {
            $chunks = DataObjectTranslationHandlerJob::split_html_to_chunks($fieldValue);
            $fieldValue = $chunks[$item['html_chunk']];
            $is_chunked = true;
        }

        $messages = TextAssistantService::getTranslatePrompt($fieldValue, $toLocale, $records[$fromLocale]);

        try {
            $result = TextAssistantService::singleton()->prompt($messages, true);
        } catch (Exception $e) {
            $result = false;

            $this->addMessage("Failed to translate field $fieldName on #" . $item['id'] . ":" . $e, 'WARNING');

            user_error($e, E_USER_WARNING);
        }

        if ($result) {
            $result = TranslationJobHelper::parseResponse($result, $fieldName);

            if (strtoupper(str_replace(['-', ' '], '_', $result)) == "TEXT_ALREADY_TRANSLATED") {
                // We gave instructions to OpenAI to not translate text already in $toLocale language
                $this->addMessage("Field $fieldName on #" . $item['id'] . " is already in $toLocale", 'INFO');
                return;
            }

            $action = new TranslationAction();
            $action->ObjectID = $records[$fromLocale]->ID;
            $action->ObjectClass = get_class($records[$fromLocale]);
            $action->CreatorID = $this->jobData->CreatorID;

            $allowed_locales = Locale::getCached()->column('Locale');
            foreach ($allowed_locales as $locale) {
                $translatedValue = $records[$locale]->getField($fieldName);
                $action->setField("Value_".$locale, $translatedValue);
            }

            $action->setField("Value_".$toLocale, $result);

            $action->FromLocale = $fromLocale;
            $action->Locale = $toLocale;
            $action->FieldName = $fieldName;

            $action->Type = "Generated";
            $action->Status = "Draft";

            if ($is_chunked) {
                
                $uniq = $records[$toLocale]->ID . get_class($records[$toLocale]) . $fieldName . $toLocale;
                if (!isset($this->chunking_object_buffer[$uniq])) {
                    $this->chunking_object_buffer[$uniq] = "";

                    // Since we're now chunk-writing to the obj, we must empty the field here
                    // because we'll be sequentially writing to $field without emptying it.
                    // however only if the record exists in the locale
                    if ($records[$toLocale]->existsInLocale($toLocale)) {
                        $id_was_written = true;

                        $recordInLocale = $records[$toLocale];

                        FluentState::singleton()->withState(function (FluentState $newState) use ($recordInLocale, $toLocale, $fieldName) {
                            $newState->setLocale($toLocale);

                            $recordInLocale->setField($fieldName, "");

                            if (!empty($recordInLocale->getChangedFields(true, DataObject::CHANGE_VALUE))) {
                                if (!$recordInLocale->hasExtension(FluentVersionedExtension::class)) {
                                    $recordInLocale->write();
                                }
                            }
                        });
                    }
                }

                // if last chunk, write to db
                if ($item['html_chunk'] === sizeof($chunks) - 1) {

                    // write last chunk
                    $this->chunking_object_buffer[$uniq] .= $result;

                    $action->setField("Value_".$toLocale, $this->chunking_object_buffer[$uniq]);
                    $action->write();

                    TranslationJobHelper::FluentDeleteOldGeneratedTranslations($action, $records[$toLocale], $fieldName, $toLocale);
                    TranslationJobHelper::FluentDeleteOldDraftManualTranslations($action, $records[$toLocale], $fieldName, $toLocale);

                    unset($this->chunking_object_buffer[$uniq]);

                } else {

                    // write chunk to buffer
                    $this->chunking_object_buffer[$uniq] .= $result;
                }

            } else {

                $action->write();

                TranslationJobHelper::FluentDeleteOldGeneratedTranslations($action, $records[$toLocale], $fieldName, $toLocale);
                TranslationJobHelper::FluentDeleteOldDraftManualTranslations($action, $records[$toLocale], $fieldName, $toLocale);
            }
        }
    }

    public function translateTranslatable($item)
    {
        $record = DataObject::get_by_id($item['class'], $item['id']);

        if (!$record) {
            $this->addMessage("Record #".$item['id']." not found", 'INFO');
            return;
        }

        $fromLocale = $this->jobData->fromLocale;
        $toLocale = $this->jobData->toLocale;
        $field = $item['field'];

        $fieldName = $record->getLocaleFieldName($field, $fromLocale);
        $fieldValue = $record->getField($fieldName);

        if (empty($fieldValue)) {
            // commented out, not really neccessary to log this
            // $this->addMessage("Field $fieldName is empty on #" . $item['id'], 'INFO');
            return;
        }

        $is_chunked = false;            // if chunked we want add to the field rather than overwrite the whole field

        if (isset($item['html_chunk'])) {
            $chunks = TranslationJobHelper::split_html_to_chunks($fieldValue);
            $fieldValue = $chunks[$item['html_chunk']];
            $is_chunked = true;
        }

        $messages = TextAssistantService::getTranslatePrompt($fieldValue, $toLocale, $record);

        try {
            $result = TextAssistantService::singleton()->prompt($messages, true);
        } catch (Exception $e) {
            $result = false;

            $this->addMessage("Failed to translate field $fieldName on #" . $item['id'] . ":" . $e, 'WARNING');

            user_error($e, E_USER_WARNING);
        }

        if ($result) {

            $result = TranslationJobHelper::parseResponse($result, $field);

            if (strtoupper(str_replace(['-', ' '], '_', $result)) == "TEXT_ALREADY_TRANSLATED") {
                // We gave instructions to OpenAI to not translate text already in $toLocale language
                $this->addMessage("Field $fieldName on #" . $item['id'] . " is already in $toLocale", 'INFO');
                return;
            }

            $action = new TranslationAction();

            $action->ObjectID = $record->ID;
            $action->ObjectClass = get_class($record);
            $action->CreatorID = $this->jobData->CreatorID;

            $allowed_locales = Locale::getCached()->column('Locale');
            foreach ($allowed_locales as $locale) {
                $translatedField = $record->getLocaleFieldName($field, $locale);
                $action->setField("Value_".$locale, $record->getField($translatedField));
            }

            $translatedField = $record->getLocaleFieldName($field, $locale);
            $action->setField("Value_".$toLocale, $result);

            $action->FromLocale = $fromLocale;
            $action->Locale = $toLocale;
            $action->FieldName = $field;

            $action->Type = "Generated";
            $action->Status = "Draft";

            if ($is_chunked) {

                $uniq = $record->ID . get_class($record) . $field . $toLocale;
                if (!isset($this->chunking_object_buffer[$uniq])) {
                    $this->chunking_object_buffer[$uniq] = "";

                    // Since we're now chunk-writing to the obj, we must empty the field here
                    // because we'll be sequentially writing to $field without emptying it.
                    $fieldName = $record->getLocaleFieldName($field, $toLocale);
                    $record->setField($fieldName, "");

                    DataObject::config()->set('validation_enabled', false);
                    if (!$record->hasExtension(Versioned::class) && !empty($record->getChangedFields(true, DataObject::CHANGE_VALUE))) {
                        $record->write();
                    }

                }

                // if last chunk, write to db
                if ($item['html_chunk'] === sizeof($chunks) - 1) {

                    // write last chunk
                    $this->chunking_object_buffer[$uniq] .= $result;

                    $translatedField = $record->getLocaleFieldName($field, $toLocale);

                    $action->setField("Value_".$toLocale, $this->chunking_object_buffer[$uniq]);
                    $action->write();

                    TranslationJobHelper::TranslatableDeleteOldGeneratedTranslations($action, $record, $translatedField, $toLocale);
                    TranslationJobHelper::TranslatableDeleteOldDraftManualTranslations($action, $record, $translatedField, $toLocale);

                    unset($this->chunking_object_buffer[$uniq]);

                } else {

                    // write chunk to buffer
                    $this->chunking_object_buffer[$uniq] .= $result;
                }


            } else {
                $action->write();

                TranslationJobHelper::TranslatableDeleteOldGeneratedTranslations($action, $record, $translatedField, $toLocale);
                TranslationJobHelper::TranslatableDeleteOldDraftManualTranslations($action, $record, $translatedField, $toLocale);
            }

        }
    }


}