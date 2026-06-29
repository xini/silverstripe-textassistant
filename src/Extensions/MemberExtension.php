<?php

namespace S2Hub\TextAssistant\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Permission;
use TractorCow\Fluent\Model\Locale;

class MemberExtension extends Extension
{
    private static $belongs_many_many = [
        'ApprovalLocales' => Locale::class,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName([
            'ApprovalLocales',
        ]);
        if (Permission::check("EDIT_PERMISSIONS")
            && Permission::checkMember($this->getOwner(), "CMS_ACCESS_TranslationAdmin")
        ) {
            $fields->addFieldsToTab(
                'Root.AITranslations',
                [
                    CheckboxSetField::create(
                        'ApprovalLocales',
                        _t(__CLASS__ . '.APPROVALLOCALES', 'AI translations allowed to approve'),
                        Locale::get()
                    )
                ]
            );
            $tab = $fields->fieldByName('Root.AITranslations');
            if ($tab) {
                $tab->setTitle(
                    _t(__CLASS__ . '.AITRANSLATIONSTAB', 'Translation Permissions'),
                );
            }
        }
    }
}
