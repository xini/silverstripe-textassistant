<?php

namespace S2Hub\TextAssistant\View;

use S2Hub\TextAssistant\Models\TranslationAction;
use SilverStripe\ORM\DataList;
use SilverStripe\View\ViewableData;
use SilverStripe\ORM\DataObjectInterface;

class TranslationActionContainerObject extends ViewableData implements DataObjectInterface
{
    private $action;
    private $dataObject;
    protected $record;

    public function __construct($record = [], $creationType = null, $queryParams = [])
    {
        parent::__construct();

        $dataList = DataList::create(TranslationAction::class);
        $this->action = $dataList->createDataObject($record);
        $this->record = [];

        if ($this->action) {
            $this->dataObject = $this->action->Object();
            
            $this->record['ID'] = $this->action->ID;
        }
    }

    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    public function canEdit($member = null)
    {
        return true;
    }

    public function canDelete($member = null)
    {
        return false;
    }

    public function write()
    {
        // NOOP
    }

    public function delete()
    {
        // NOOP
    }

    public function setCastedField($fieldName, $val)
    {
        // NOOP
    }

    public function __call($method, $arguments)
    {
        // if (method_exists($this->dataObject, $method)) {
        //     return call_user_func_array([$this->dataObject, $method], $arguments);
        // }

        return parent::__call($method, $arguments);
    }

    public function __get($fieldName)
    {
        if (isset($this->record[$fieldName])) {
            return $this->record[$fieldName];
        }

        // not sure if needed
        // if (isset($this->dataObject->$fieldName)) {
        //     return $this->dataObject->$fieldName;
        // }

        return parent::__get($fieldName);
    }

    public function getObject()
    {
        return $this->dataObject;
    }

    public function getObjectID()
    {
        return $this->dataObject->ID;
    }

    public function hasDatabaseField($field)
    {
        return false;
    }

    public function getObjectType()
    {
        $title = $this->dataObject->i18n_singular_name();

        if ($title === $this->dataObject->ClassName) {
            $title = _t($this->dataObject->ClassName.'.CMSTITLE', $this->dataObject->ClassName);
        }

        if ($title === $this->dataObject->ClassName) {
            $title = _t($this->dataObject->ClassName.'.DefaultTitle', $this->dataObject->ClassName);
        }

        return $title;
    }

    public function getObjectDescription()
    {
        $title = $this->dataObject->Value ?? $this->dataObject->Name ?? $this->dataObject->Title;

        return $title;
    }

    public function getActionFromToLocale()
    {
        return $this->action->getFromToLocale();
    }

    public function getCreatedNice()
    {
        return $this->action->getCreatedNice();
    }

    public function getContainingObjects(): DataList
    {
        return DataList::create(TranslationAction::class)
            ->filter([
                'ObjectClass' => $this->dataObject->ClassName,
                'ObjectID' => $this->dataObject->ID,
                'Locale' => $this->action->Locale,
            ]);
    }

}