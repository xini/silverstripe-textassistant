<?php

namespace S2Hub\TextAssistant\Extensions;

use S2Hub\TextAssistant\Models\TranslationAction;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ListboxField;

class DataObjectTranslationActionExtension extends Extension
{
    public static function getGridFieldFilterFields(): FieldList
    {
        $statusOptions = singleton(TranslationAction::class)->dbObject('Status')->enumValues();
        
        foreach ($statusOptions as $key => $value) {
            $statusOptions[$key] = _t(TranslationAction::class.'.STATUS_'.strtoupper($key), $value);
        }


        $fields = new FieldList([
            ListboxField::create('Status', _t(TranslationAction::class.'.STATUS', 'Status'), $statusOptions)
        ]);

        return $fields;
    }
}