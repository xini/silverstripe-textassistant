<?php

namespace S2Hub\TextAssistant\Models;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\SelectionGroup_Item;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\SelectionGroup;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class TranslateFilter extends DataObject
{
    private static $table_name = 'TextAssistant_TranslateFilter';

    private static $db = [
        'Type' => 'Enum("DontTranslate, Change", "DontTranslate")',
        'Text' => 'Varchar(255)',
        'ChangeTo' => 'Varchar(255)'
    ];

    private static $summary_fields = array(
        'Text',
        'Action'
    );

    public function populateDefaults()
    {
        $this->Type = 'DontTranslate';
        parent::populateDefaults();
    }

    public function getTitle()
    {
        if ($this->Type === "DontTranslate") {
            return $this->getTypeNice() . ": " . $this->Text;
        } else if ($this->Type === "Change") {
            return $this->getTypeNice() . ": \"" . $this->Text . "\" → \"" . $this->ChangeTo . "\"";

        }
    }

    public function getCMSFields()
    {
        $fields = new FieldList();

        $fields->push(new TextField('Text', _t(self::class.'.TEXT', 'Text')));

        $changeItem = new SelectionGroup_Item('Change', null, _t(self::class.'.TYPE_CHANGE', 'Change text to'));
        $dontTranslate = new SelectionGroup_Item('DontTranslate', null, _t(self::class.'.TYPE_DONTTRANSLATE', 'Do not change text'));

        $changeItem->setChildren(new FieldList(
            new TextField('ChangeTo', _t(self::class.'.CHANGETO', 'Change text to'))
        ));

        $fields->push(SelectionGroup::create('Type', [
            $dontTranslate,
            $changeItem,
        ])->setTitle(_t(self::class.'.TYPE', 'Type')));

        return $fields;
    }

    public function validate()
    {
        $result = parent::validate();

        if (empty($this->Text)) {
            $result->addError(_t(self::class.'.TEXT_REQUIRED', 'Text is required'));
        }

        if ($this->Type == 'Change' && empty($this->ChangeTo)) {
            $result->addError(_t(self::class.'.CHANGETO_REQUIRED', 'Change to is required'));
        }

        return $result;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $this->Text = trim($this->Text);
    }

    public function getTypeNice()
    {
        return _t(self::class.'.TYPE_'.strtoupper($this->Type), implode(" ", preg_split('/(?=[A-Z])/',$this->Type)));
    }

    public function getAction()
    {
        if ($this->Type === "DontTranslate") {
            return $this->getTypeNice();
        } else if ($this->Type === "Change") {
            return $this->getTypeNice() . " \"" . $this->ChangeTo . "\"";
        }
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
                Permission::checkMember($member, "EDIT_TRANSLATION_FILTERS")
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
