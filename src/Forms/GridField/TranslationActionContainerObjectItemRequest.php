<?php

namespace S2Hub\TextAssistant\Forms\GridField;

use S2Hub\TextAssistant\Models\TranslationAction;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\CMS\Model\SiteTree;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\State\FluentState;
use SilverStripe\ORM\SS_List;
use SilverStripe\View\ViewableData;

class TranslationActionContainerObjectItemRequest extends GridFieldDetailForm_ItemRequest
{

    private static $allowed_actions = [
        'ItemEditForm'
    ];

    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();

        $form->setFields($this->getFieldsForObject($this->record));

        $form->addExtraClass('TranslationActionContainerObject');

        return $form;
    }

    /**
     * Build the set of form field actions for this DataObject
     *
     * @return FieldList
     */
    protected function getFormActions()
    {
        $manager = $this->getStateManager();

        $actions = FieldList::create();
        $majorActions = CompositeField::create()->setName('MajorActions');
        $majorActions->setFieldHolderTemplate(get_class($majorActions) . '_holder_buttongroup');
        $actions->push($majorActions);

        if ($this->record->ID !== 0) { // existing record
            if ($this->record->canEdit()) {
                $majorActions->push(FormAction::create('doPublish', _t(self::class.'.PUBLISH', 'Publish'))
                    ->addExtraClass('btn-primary font-icon-tick')
                    ->setUseButtonTag(true)
                    ->setAttribute('data-text-alternate', _t(self::class.'.PUBLISH', 'Publish')));
            }

            $gridState = $this->gridField->getState(false);
            $actions->push(HiddenField::create($manager->getStateKey($this->gridField), null, $gridState));

            $actions->push($this->getRightGroupField());
        }

        $this->extend('updateFormActions', $actions);

        return $actions;
    }

    public function doPublish($data, $form)
    {
        if (!$this->record->canEdit()) {
            $this->httpError(403, _t(
                __CLASS__ . '.EditPermissionsFailure',
                'It seems you don\'t have the necessary permissions to edit "{ObjectTitle}"',
                ['ObjectTitle' => $this->record->singular_name()]
            ));
            return null;
        }

        $controller = $this->getToplevelController();
        TranslationAction::config()->set('allow_logging', false);

        $ids = array_keys($data['TranslationAction']);

        $actions = DataList::create(TranslationAction::class)->filter('ID', $ids);
        $object = $actions->first()->Object();
        $isVersioned = $object->hasExtension(Versioned::class);
        $currentStage = Versioned::get_stage();

        if ($object->hasExtension(FluentExtension::class)) {
            $this->publishFluent($object, $actions, $data, $isVersioned);
        }

        if ($isVersioned) {
            Versioned::set_stage($currentStage);

            $object->publishRecursive();
        }

        if ($object->hasMethod('onAfterTranslationPublish')) {
            $object->onAfterTranslationPublish();
        }

        $message = _t(
            self::class . '.PUBLISH_MESSAGE',
            'Published "{Name}".',
            [
                'Name' => Convert::raw2xml($object->Title)
            ]
        );

        $controller = $this->getToplevelController();
        $controller->getResponse()->addHeader('X-Status', $message);

        // close self and return to gridfield
        return $controller->redirect($controller->Link());
    }

    public function publishTranslatable(DataObject $object, SS_List $actions, array $data, bool $isVersioned = false)
    {
        $hasChanges = false;
        if ($isVersioned) {
            Versioned::set_stage(Versioned::DRAFT);
        }

        $doResetEditableUrlSegment = false;
        
        foreach ($actions as $action) {

            if ($action->FieldName === "Title" && $object->config()->editable_urlsegment === true) {
                $doResetEditableUrlSegment = true;
                $object->config()->editable_urlsegment = false;
            }

            $formValue = $data['TranslationAction'][$action->ID][$action->FieldName . "_" . $action->Locale];
            $translationChangedByUser = false;

            // check if translation was changed by user
            if ($formValue != $object->getField($action->FieldName . "_" . $action->Locale)) {
                $action->setField("Value_".$action->Locale, $formValue);

                $translationChangedByUser = true;
            }

            if ($translationChangedByUser) {
                $object->setField($action->FieldName . "_" . $action->Locale, $formValue);

                $hasChanges = true;
            }
		}
 
        if ($hasChanges) {
            $object->write();
        }
 
        foreach ($actions as $action) {
            $action->Status = "Accepted";
            $action->PublishedPlace = "TranslationAdmin";
            $action->write();
        }

        if ($doResetEditableUrlSegment) {
            $object->config()->editable_urlsegment = true;
        }
    }

    public function publishFluent(DataObject $object, SS_List $actions, array $data, bool $isVersioned = false)
    {
        $hasChanges = false;
        if ($isVersioned) {
            Versioned::set_stage(Versioned::DRAFT);
        }

        $action = $actions->first();

        FluentState::singleton()->withState(function (FluentState $newState) use ($object, $action, $actions, $data, $isVersioned, &$hasChanges) {
            $newState->setLocale($action->Locale);

            foreach ($actions as $action) {
                $formValue = $data['TranslationAction'][$action->ID][$action->FieldName . "_" . $action->Locale];
                $translationChangedByUser = false;

                // check if translation was changed by user
                if ($formValue != $object->getField($action->FieldName)) {
                    $action->setField("Value_".$action->Locale, $formValue);

                    $translationChangedByUser = true;
                }

                if ($translationChangedByUser || $object->getField($action->FieldName) != $formValue) {
                    $object->setField($action->FieldName, $formValue);

                    $hasChanges = true;
                }
            }

            if ($hasChanges) {
                $object->write();
            }

            foreach ($actions as $action) {
                $action->Status = "Accepted";
                $action->PublishedPlace = "TranslationAdmin";
                $action->write();
            }
        });
    }

    public function getFieldsForObject(TranslationAction $record): FieldList
    {
        $fields = new FieldList();

        $translationActions = DataList::create(TranslationAction::class)
            ->filter([
                'ObjectID' => $record->ObjectID,
                'ObjectClass' => $record->ObjectClass,
                'Status' => 'Draft',
                'Locale' => $record->Locale
            ]);

        $informationFields = [];

        $informationFields[] = LiteralField::create('BasicInformation', ViewableData::singleton()->renderWith('TranslationAction_Information', [
            'Record' => $record
        ]));

        $fields->push(CompositeField::create($informationFields)->addExtraClass('translation-action-container'));

        $order = $this->getCMSFieldsTranslatableFieldsOrder($record->Object());

        $decorativeTopFieldsComposite = null;

        if ($translationActions->first()->Object()->hasMethod('getTranslationActionDecorativeTopFields')) {
            $objectDecorativeTopFields = $translationActions->first()->Object()->getTranslationActionDecorativeTopFields($translationActions->first());

            $decorativeTopFieldsComposite = new CompositeField($objectDecorativeTopFields);
            $decorativeTopFieldsComposite->setName("ObjectDecorativeTopFields");
            $decorativeTopFieldsComposite->addExtraClass('translation-action-container');
        }




        foreach ($translationActions as $translationAction) {
            $fieldsForAction = $translationAction->getCMSFields();

            $composite = new CompositeField($fieldsForAction);
            $composite->setName('TranslationAction_' . $translationAction->ID);

            $composite->addExtraClass('translation-action-container');

            $order[$translationAction->FieldName] = $composite;
        }

        $order = $this->augmentOrderedFields($order);

        if ($decorativeTopFieldsComposite !== null) {
            array_unshift($order, $decorativeTopFieldsComposite);

        }


        foreach ($order as $name => $field) {

            if (!is_string($field)) {
                $fields->push($field);
            }

        }



        return $fields;
    }

    public function augmentOrderedFields(array $fields): array
    {
        // all this stuff is so we can move the MetaTitle and MetaDescription fields into a Metadata toggle that looks similar to how it's everywhere.
        $metaFields = [];
        if (isset($fields['MetaTitle']) && !is_string($fields['MetaTitle'])) $metaFields['MetaTitle'] = $fields['MetaTitle'];
        if (isset($fields['MetaDescription']) && !is_string($fields['MetaDescription'])) $metaFields['MetaDescription'] = $fields['MetaDescription'];

        if (!empty($metaFields)) {

            $first = true;
            $first_key = null;
            foreach ($metaFields as $metaKey => $metaField) {
                if ($first) {
                    $first = false;
                    $first_key = $metaKey;
                } else {
                    unset($fields[$metaKey]);
                }
            }

            if ($first_key) {
                $fields[$first_key] = ToggleCompositeField::create(
                    'Metadata',
                    _t(SiteTree::class.'.MetadataToggle', 'Metadata'),
                    $metaFields
                )->setHeadingLevel(4)->setStartClosed(false);
            }

        }


        return $fields;
    }

    /**
     * Returns an array of field names in the order that they're inserted into getCMSFields().
     */
    public function getCMSFieldsTranslatableFieldsOrder(DataObject $object): array
    {
        $dataFields = $object->getCMSFields()->dataFields();
        $dataFieldsNames = [];

        foreach ($dataFields as $field) {
            $name = $field->getName();

            $dataFieldsNames[$name] = $name;
        }

        return $dataFieldsNames;
    }
}