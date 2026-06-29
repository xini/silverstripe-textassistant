<?php

namespace S2Hub\TextAssistant\Models;

use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class TextAssistantSettings extends DataObject
{
    private static $db = [
        'ContextForAI' => 'Text'
    ];

    private static $table_name = 'TextAssistant_Settings';

    public function getCMSFields()
    {
        $fields = new FieldList();

        $fields->push(TextareaField::create('ContextForAI', _t(self::class.'.CONTEXTFORAI', 'Help context for AI'))
            ->setRightTitle(_t(self::class.'.CONTEXTFORAI_RIGHTTITLE', 'This text will be used to give context to AI to help it create better translations. Give a brief explanation about your company and the product and services it offers.'))
            ->setMaxLength(200));

        return $fields;
    }

     /**
     * Get the current settings record
     */
    public static function currentRecord(): TextAssistantSettings
    {
        $record = TextAssistantSettings::get()->first();
        if (!$record) {
            $record = TextAssistantSettings::create();
            $record->write();
        }
        return $record;
    }

    public function canEdit($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // check for extensions, we do this first as they can overrule everything
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        if (// either we have an ADMIN
            (bool)Permission::checkMember($member, "ADMIN")
            || (
                // or specific persmissions
                Permission::checkMember($member, "CMS_ACCESS_TranslationAdmin") &&
                Permission::checkMember($member, "EDIT_TEXTASSISTANT_SETTINGS")
            )
        ) {
            return true;
        }

        return false;
    }

    public function canView($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // check for extensions, we do this first as they can overrule everything
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        return $this->canEdit($member);
    }

    public function canDelete($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // check for extensions, we do this first as they can overrule everything
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }

        return $this->canEdit($member);
    }
}
