<?php

namespace S2Hub\TextAssistant\Helpers;

use Exception;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Core\Config\Config;
use TractorCow\Fluent\State\FluentState;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\FieldType\DBVarchar;
use TractorCow\Fluent\Extension\FluentExtension;
use S2Hub\TextAssistant\Models\TranslationAction;
use SilverStripe\View\Parsers\URLSegmentFilter;

class TranslationJobHelper
{

    private static $formFieldForType_cache = [];

    public static function setupForTranslatable(DataObject $object): array
    {
        $remaining = [];

        $translatableFields = $object->getTranslatableFields();

        $ignore_translate = $object->config()->openai_ignore_translate ?? [];

        if (!empty($ignore_translate)) {
            $ignore_translate = array_flip($ignore_translate);
        }

        foreach ($translatableFields as $field) {

            if (isset($ignore_translate[$field])) {
                continue;
            }

            $fieldObj = $object->dbObject($field);

            if ($fieldObj instanceof DBHTMLText) {
                $chunks = self::split_html_to_chunks($object->getField($field));

                if (sizeof($chunks) === 1) {
                    $remaining[] = [
                        'id' => $object->ID,
                        'class' => $object->ClassName,
                        'field' => $field,
                        'translation_type' => 'Translatable'
                    ];
                } else {
                    foreach ($chunks as $i => $chunk) {
                        $remaining[] = [
                            'id' => $object->ID,
                            'class' => $object->ClassName,
                            'field' => $field,
                            'html_chunk' => $i,
                            'translation_type' => 'Translatable'
                        ];
                    }
                }

            } else {
                $remaining[] = [
                    'id' => $object->ID,
                    'class' => $object->ClassName,
                    'field' => $field,
                    'translation_type' => 'Translatable'
                ];
            }

        }

        return $remaining;
    }

    public static function setupForFluent(DataObject $object, string $fromLocale): array
    {
        return FluentState::singleton()->withState(function (FluentState $newState) use ($object, $fromLocale) {
            $newState->setLocale($fromLocale);
            $remaining = [];

            $object = DataObject::get_by_id(get_class($object), $object->ID, false);

            $ignore_translate = $object->config()->openai_ignore_translate ?? [];

            $localisedFields = self::getAllLocalisedFields(get_class($object));

            if (!$object->hasExtension(FluentExtension::class)) {
                throw new Exception(get_class($object) . " does not have FluentExtension");
            }

            if (!isset(self::$formFieldForType_cache[get_class($object)])) {
                $formFieldForType = [];
                foreach ($object->getCMSFields()->dataFields() as $field) {
                    $formFieldForType[$field->getName()] = $field;
                }

                self::$formFieldForType_cache[get_class($object)] = $formFieldForType;
            }
            
            $formFieldForType = self::$formFieldForType_cache[get_class($object)];

            foreach ($localisedFields as $field => $type) {

                if (isset($ignore_translate[$field])) {
                    continue;
                }
    
                // If there's not a dataField for it, skip it.
                if (!isset($formFieldForType[$field])) {
                    continue;
                }
    
                // FluentExtension localises everything, so we still need to know which fields hold
                // translatable text. The CMS field is only used to prove the field is editable content
                // (see above); the actual type comes from the DB field, because getCMSFields() may
                // return e.g. a ReadonlyField when the current context lacks edit permission.
                $formField = $formFieldForType[$field];
                if ($formField instanceof ReadonlyField) {
                    // fall back to the DB type for readonly fields only
                    if (!self::isTranslatableDBField($object, $field)) {
                        continue;
                    }
                } elseif (!($formField instanceof TextField
                    || $formField instanceof TextareaField
                    || $formField instanceof HTMLEditorField)) {
                    continue;
                }

                if (self::isHTMLDBField($object, $field)) {
    
                    $chunks = self::split_html_to_chunks($object->getField($field));
    
                    if (sizeof($chunks) === 1) {
    
                        $remaining[] = [
                            'id' => $object->ID,
                            'class' => $object->ClassName,
                            'field' => $field,
                            'fieldtype' => $type,
                            'translation_type' => 'Fluent'
                        ];
    
                    } else {
    
                        foreach ($chunks as $i => $chunk) {
                            $remaining[] = [
                                'id' => $object->ID,
                                'class' => $object->ClassName,
                                'field' => $field,
                                'fieldtype' => $type,
                                'html_chunk' => $i,
                                'translation_type' => 'Fluent'
                            ];
                        }
    
                    }
    
    
    
                } else {

                    $remaining[] = [
                        'id' => $object->ID,
                        'class' => $object->ClassName,
                        'field' => $field,
                        'fieldtype' => $type,
                        'translation_type' => 'Fluent'
                    ];
                }
    
            }

            return $remaining;
        });
    }

    protected static function isHTMLDBField(DataObject $object, string $field): bool
    {
        return $object->dbObject($field) instanceof DBHTMLText;
    }

    protected static function isTranslatableDBField(DataObject $object, string $field): bool
    {
        $fieldObj = $object->dbObject($field);

        if (!$fieldObj) {
            return false;
        }

        // DBHTMLText extends DBText, DBHTMLVarchar extends DBVarchar, so both are covered.
        return $fieldObj instanceof DBText || $fieldObj instanceof DBVarchar;
    }

    public static function split_html_to_chunks($html): array
    {
        $nominal_max_length = 4000;

        // do nothing if the html is already short enough
        if (strlen($html) < $nominal_max_length) {
            return [$html];
        }

        preg_match_all('/<[^>]*>(.*)+/', $html, $output_array);

        $parts = $output_array[0];


        $current_length = 0;

        $parts_by_nominal = [];
        $current_index = 0;

        foreach ($parts as $part) {

            if (!isset($parts_by_nominal[$current_index])) {
                $parts_by_nominal[$current_index] = "";
            }

            $part_length = strlen($part);
            
            $parts_by_nominal[$current_index] .= $part . "\n";

            
            if ($part_length + $current_length > $nominal_max_length) {
                $current_length = 0;
                $current_index++;
                
            } else {
                $current_length += $part_length;
            }
            
        }

        return $parts_by_nominal;
    }

    /**
     *      This func is a straight copy out of FluentExtension::getLocalisedFields(),
     *      but that func doesn't take parent databaseFields, but we need those too.
     */
    public static function getAllLocalisedFields($class)
    {

        // List of DB fields
        $fields = DataObject::getSchema()->databaseFields($class, true);
        $filter = Config::inst()->get($class, 'translate', Config::UNINHERITED);
        if ($filter === FluentExtension::TRANSLATE_NONE || empty($fields)) {
            return [];
        }

        // filter out DB
        foreach ($fields as $field => $type) {
            if (!self::isFieldLocalised($field, $type, $class)) {
                unset($fields[$field]);
            }
        }

        return $fields;
    }

    
    /**
     *      This func is a straight copy out of FluentExtension::getLocalisedFields(),
     *      but that func doesn't take parent databaseFields, but we need those too.
     */
    protected static function isFieldLocalised($field, $type, $class)
    {
        // Explicit per-table filter
        $filter = Config::inst()->get($class, 'translate', Config::UNINHERITED);
        if ($filter === FluentExtension::TRANSLATE_NONE) {
            return false;
        }
        if ($filter && is_array($filter)) {
            return in_array($field, $filter);
        }

        // Named blacklist
        $fieldsExclude = Config::inst()->get($class, 'field_exclude');
        if ($fieldsExclude && self::anyMatch($field, $fieldsExclude)) {
            return false;
        }

        // Named whitelist
        $fieldsInclude = Config::inst()->get($class, 'field_include');
        if ($fieldsInclude && self::anyMatch($field, $fieldsInclude)) {
            return true;
        }

        // Typed blacklist
        $dataExclude = Config::inst()->get($class, 'data_exclude');
        if ($dataExclude && self::anyMatch($type, $dataExclude)) {
            return false;
        }

        // Typed whitelist
        $dataInclude = Config::inst()->get($class, 'data_include');
        if ($dataInclude && self::anyMatch($type, $dataInclude)) {
            return true;
        }

        return false;
    }

    protected static function anyMatch($value, $patterns)
    {
        // Test both explicit value, as well as the value stripped of any trailing parameters
        $valueBase = preg_replace('/\(.*/', '', $value);
        foreach ($patterns as $pattern) {
            if (strpos($pattern, '/') === 0) {
                // Assume value prefaced with '/' are regexp
                if (preg_match($pattern, $value) || preg_match($pattern, $valueBase)) {
                    return true;
                }
            } else {
                // Assume simple string comparison otherwise
                if ($pattern === $value || $pattern === $valueBase) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function TranslatableDeleteOldGeneratedTranslations(TranslationAction $action, DataObject $record, string $field, string $toLocale)
    {
        // We're not interested in old Generated translations, only the latest.
        // Delete all old.
        $oldActions = DataList::create(TranslationAction::class)->filter([
            'ID:ExactMatch:not' => $action->ID,
            'ObjectID' => $record->ID,
            'ObjectClass' => get_class($record),
            'Locale' => $toLocale,
            'Status' => ['Draft', 'Accepted'],
            'Type' => 'Generated'
        ])->where('CONCAT(FieldName, \'_\', Locale) = \''.$field . '\'');

        foreach ($oldActions as $action) {
            $action->delete();
        }
    }

    public static function TranslatableDeleteOldDraftManualTranslations(TranslationAction $action, DataObject $record, string $field, string $toLocale)
    {
        // If we're translating with AI, we're probably not interested in the old draft manual translations.
        $oldActions = DataList::create(TranslationAction::class)->filter([
            'ID:ExactMatch:not' => $action->ID,
            'ObjectID' => $record->ID,
            'ObjectClass' => get_class($record),
            'Locale' => $toLocale,
            'Status' => 'Draft',
            'Type' => 'Manual'
        ])->where('CONCAT(FieldName, \'_\', Locale) = \''.$field . '\'');

        foreach ($oldActions as $action) {
            $action->delete();
        }
    }

    public static function FluentDeleteOldDraftManualTranslations(TranslationAction $action, DataObject $record, string $field, string $toLocale)
    {
        // If we're translating with AI, we're probably not interested in the old draft manual translations.
        $oldActions = DataList::create(TranslationAction::class)->filter([
            'ID:ExactMatch:not' => $action->ID,
            'ObjectID' => $record->ID,
            'ObjectClass' => get_class($record),
            'Locale' => $toLocale,
            'Status' => 'Draft',
            'Type' => 'Manual',
            'FieldName' => $field
        ]);

        foreach ($oldActions as $action) {
            $action->delete();
        }
    }

    public static function FluentDeleteOldGeneratedTranslations(TranslationAction $action, DataObject $record, string $field, string $toLocale)
    {
        // We're not interested in old Generated translations, only the latest.
        // Delete all old.
        $oldActions = DataList::create(TranslationAction::class)->filter([
            'ID:ExactMatch:not' => $action->ID,
            'ObjectID' => $record->ID,
            'ObjectClass' => get_class($record),
            'Locale' => $toLocale,
            'Status' => ['Draft', 'Accepted'],
            'Type' => 'Generated',
            'FieldName' => $field
        ]);

        foreach ($oldActions as $action) {
            $action->delete();
        }
    }

    public static function parseResponse($response, $fieldName = ""): string
    {
        $response = str_replace(["```html"], "", $response);
        $response = str_replace(['```json'], "", $response);
        $response = str_replace(['```'], "", $response);

        if (!empty($fieldName)) {

            if ($fieldName === "URLSegment") {
                $filter = URLSegmentFilter::create();
                $response = $filter->filter($response);
                
            }
        }

        return $response;
    }

}
